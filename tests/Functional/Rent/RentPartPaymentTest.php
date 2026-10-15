<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rent;

use App\Entity\Rent\RentBill;
use App\Entity\Rent\RentPayment;
use App\Entity\Rent\RentProperty;
use App\Entity\Rent\RentTenant;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * ADR-140: a month's rent (or electricity) can be paid in parts. The Year view's Pay takes an amount (blank = all
 * that is owed, never more), each instalment is listed under the month with what is left, and "+ Payment" can be
 * for one chosen month.
 */
final class RentPartPaymentTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'rent-part-admin@example.com';
    private const PROPERTY = 'Part Payment Test House';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $tenantId;
    /** @var array<string, int> bill id by "Y-m" */
    private array $bills = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
        $this->createTestUser(self::EMAIL, roles: ['ROLE_ADMIN']);

        $property = (new RentProperty())->setName(self::PROPERTY)->setElectricityRate('8.00')->setDefaultRent('5000.00');
        $tenant = (new RentTenant())->setName('Part Payer')->setProperty($property)->setMonthlyRent('5000.00')
            ->setStartDate(new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($property);
        $this->em->persist($tenant);
        foreach (['2026-08' => '4000.00', '2026-09' => '5000.00'] as $month => $rent) {
            $bill = (new RentBill())->setTenant($tenant)->setPeriod(new \DateTimeImmutable($month.'-01'))->setRentAmount($rent)
                ->setUnits('10.00')->setRate('8.00')->setElectricityAmount('80.00')->setTotal(number_format((float) $rent + 80, 2, '.', ''));
            $this->em->persist($bill);
            $this->em->flush();
            $this->bills[$month] = (int) $bill->getId();
        }
        $this->em->flush();
        $this->tenantId = (int) $tenant->getId();
        $this->em->clear();

        $this->loginUser(self::EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testRentIsPaidInTwoPartsAndEachPartIsListedUnderTheMonth(): void
    {
        $this->pay('2026-09', 'rent', '2000', '2026-09-05', 'upi');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash, .alert, body', 'Part payment saved: Rent for September 2026, ₹3,000.00 still owed.');

        $row = $this->yearRow('September');
        self::assertStringContainsString('Part paid', $row->filter('td')->eq(2)->text());
        self::assertStringContainsString('₹2,000.00', $row->filter('.rent-parts')->text());
        self::assertStringContainsString('UPI', $row->filter('.rent-parts')->text());
        self::assertStringContainsString('₹3,000.00 left', $row->filter('td')->eq(2)->text());
        self::assertSame('3000.00', $row->filter('td')->eq(2)->filter('input[name="amount"]')->attr('value'), 'Pay offers what is left.');

        $this->pay('2026-09', 'rent', '', '2026-09-20', 'cash'); // blank: the rest
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Rent for September 2026 marked paid.');

        $row = $this->yearRow('September');
        self::assertStringContainsString('Paid', $row->filter('td')->eq(2)->text());
        self::assertCount(2, $row->filter('td')->eq(2)->filter('.rent-parts li'), 'Both instalments are listed.');
        self::assertSame(['2000.00', '3000.00'], $this->paymentAmounts('rent'));
        self::assertStringContainsString('Sep 20, 2026', $row->filter('td')->eq(3)->text(), 'Paid on = the instalment that completed it.');
    }

    public function testMoreThanIsOwedOrZeroIsRefusedAndNothingIsStored(): void
    {
        $this->pay('2026-08', 'rent', '4000.01', '2026-08-03', 'cash');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '₹4,000.01 is more than the ₹4,000.00 still owed on Rent for August 2026.');

        $this->pay('2026-08', 'rent', '0', '2026-08-03', 'cash');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Amount must be more than 0.');

        $this->pay('2026-08', 'rent', 'abc', '2026-08-03', 'cash');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Amount must be a number');
        self::assertSame([], $this->paymentAmounts('rent'));
    }

    public function testAPaymentForAChosenMonthPaysThatMonthNotTheOldest(): void
    {
        $crawler = $this->client->request('GET', "/rent/tenants/{$this->tenantId}/payments/new?kind=rent&bill=".$this->bills['2026-09']);
        self::assertSame((string) $this->bills['2026-09'], $crawler->filter('select[name="billId"] option[selected]')->attr('value'));
        self::assertStringContainsString('August 2026 — rent ₹4,000.00 · electricity ₹80.00 owed', $crawler->filter('select[name="billId"]')->text());

        $this->client->submit($crawler->filter('form.card')->form(['amount' => '1500', 'kind' => 'rent', 'method' => 'bank']));
        self::assertResponseRedirects("/rent/tenants/{$this->tenantId}");

        self::assertStringContainsString('Unpaid', $this->yearRow('August')->filter('td')->eq(2)->text(), 'August is untouched.');
        self::assertStringContainsString('Part paid', $this->yearRow('September')->filter('td')->eq(2)->text());

        $tenantPage = $this->client->request('GET', "/rent/tenants/{$this->tenantId}");
        self::assertStringContainsString('Sep 2026', $tenantPage->filter('.table-card')->last()->text(), 'The payment list shows its month.');
    }

    public function testAnotherTenantsMonthIsRefused(): void
    {
        $crawler = $this->client->request('GET', "/rent/tenants/{$this->tenantId}/payments/new");
        $form = $crawler->filter('form.card')->form(['amount' => '100', 'kind' => 'rent', 'method' => 'cash']);
        $values = $form->getPhpValues();
        $values['billId'] = '999999999';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choose one of this tenant\'s months, or "Oldest bill first".');
    }

    public function testAPaymentNotForOneMonthIsSplitAndShownUnderEachMonthItPaid(): void
    {
        $crawler = $this->client->request('GET', "/rent/tenants/{$this->tenantId}/payments/new?kind=rent");
        $this->client->submit($crawler->filter('form.card')->form(['amount' => '6000', 'kind' => 'rent', 'method' => 'cash', 'billId' => '']));

        $august = $this->yearRow('August')->filter('td')->eq(2);
        $september = $this->yearRow('September')->filter('td')->eq(2);
        self::assertStringContainsString('Paid', $august->text());
        self::assertStringContainsString('₹4,000.00 of ₹6,000.00', $august->filter('.rent-parts')->text());
        self::assertStringContainsString('₹2,000.00 of ₹6,000.00', $september->filter('.rent-parts')->text());
        self::assertStringContainsString('₹3,000.00 left', $september->text());
    }

    public function testDeletingAnInstalmentFromTheYearViewReturnsThereAndReopensTheMonth(): void
    {
        $this->pay('2026-09', 'rent', '2000', '2026-09-05', 'cash');
        $row = $this->yearRow('September');
        $this->client->submit($row->filter('.rent-part-del')->form());

        self::assertResponseRedirects('/rent/year?tenant='.$this->tenantId.'&year=2026');
        self::assertSame([], $this->paymentAmounts('rent'));
        self::assertStringContainsString('Unpaid', $this->yearRow('September')->filter('td')->eq(2)->text());
    }

    private function pay(string $month, string $kind, string $amount, string $date, string $method): void
    {
        $form = $this->yearRow((new \DateTimeImmutable($month.'-01'))->format('F'))
            ->filter(sprintf('form.rent-mark input[name="kind"][value="%s"]', $kind))->closest('form')->form();
        $this->client->submit($form, ['amount' => $amount, 'paidOn' => $date, 'method' => $method]);
    }

    private function yearRow(string $monthName): Crawler
    {
        $crawler = $this->client->request('GET', "/rent/year?tenant={$this->tenantId}&year=2026");
        self::assertResponseIsSuccessful();

        return $crawler->filter('table.rent-year tbody tr')->reduce(static fn (Crawler $tr) => trim($tr->filter('td')->first()->text()) === $monthName)->first();
    }

    /** @return list<string> */
    private function paymentAmounts(string $kind): array
    {
        $this->em->clear();

        return array_map(static fn (RentPayment $p) => $p->getAmount(),
            $this->em->getRepository(RentPayment::class)->findBy(['tenant' => $this->tenantId, 'kind' => $kind], ['paidOn' => 'ASC', 'id' => 'ASC']));
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $tenants = 'SELECT t.id FROM rent_tenant t JOIN rent_property p ON p.id = t.property_id WHERE p.name = ?';
        $conn->executeStatement("DELETE FROM rent_payment WHERE tenant_id IN ($tenants)", [self::PROPERTY]);
        $conn->executeStatement("DELETE FROM rent_bill WHERE tenant_id IN ($tenants)", [self::PROPERTY]);
        $conn->executeStatement('DELETE t FROM rent_tenant t JOIN rent_property p ON p.id = t.property_id WHERE p.name = ?', [self::PROPERTY]);
        $conn->executeStatement('DELETE FROM rent_property WHERE name = ?', [self::PROPERTY]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
