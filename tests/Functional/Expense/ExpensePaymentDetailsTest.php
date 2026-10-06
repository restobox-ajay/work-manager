<?php

declare(strict_types=1);

namespace App\Tests\Functional\Expense;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-097: an expense can carry optional payment details whose meaning follows "Paid by" (UPI id, cheque no. and
 * bank, bank reference); cash has none, and the details show under "Paid by" in the month list.
 */
final class ExpensePaymentDetailsTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'expense-details-admin@example.com';
    private const DESCRIPTION_PREFIX = 'PayDetailsTest ';
    private const DATE = '2026-09-15';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
        $this->createTestUser(self::EMAIL, roles: ['ROLE_ADMIN']);
        $this->loginUser(self::EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testTheFormOffersTheFieldWithALabelPerMethod(): void
    {
        $crawler = $this->client->request('GET', '/expense/new');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="paymentDetails"]:not([required])'), 'Payment details are optional.');
        $labels = json_decode((string) $crawler->filter('#f-method')->attr('data-payment-labels'), true);
        self::assertSame('UPI ID / transaction no.', $labels['upi']);
        self::assertArrayHasKey('cheque', $labels);
        self::assertArrayNotHasKey('cash', $labels, 'Cash asks for no details.');
    }

    public function testUpiDetailsAreSavedAndShownInTheList(): void
    {
        $this->save('upi', 'merchant@okbank / TXN 4411', 'UPI');

        self::assertResponseRedirects();
        self::assertSame('merchant@okbank / TXN 4411', $this->storedDetails('UPI'));
        $list = $this->client->request('GET', '/expense?month=2026-09');
        self::assertStringContainsString('merchant@okbank / TXN 4411', $list->filter('table')->text());
    }

    public function testDetailsAreOptional(): void
    {
        $this->save('cheque', '', 'Cheque');

        self::assertResponseRedirects();
        self::assertNull($this->storedDetails('Cheque'));
    }

    public function testCashKeepsNoDetails(): void
    {
        $this->save('cash', 'should be dropped', 'Cash');

        self::assertResponseRedirects();
        self::assertNull($this->storedDetails('Cash'));
    }

    public function testOverlongDetailsAreRefused(): void
    {
        $this->save('bank', str_repeat('x', 256), 'Bank');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Payment details can be at most 255 characters.');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM expense WHERE description = ?', [self::DESCRIPTION_PREFIX.'Bank']));
    }

    private function save(string $method, string $details, string $suffix): void
    {
        $form = $this->client->request('GET', '/expense/new')->selectButton('Save expense')->form();
        $this->client->submit($form, [
            'spentOn'        => self::DATE,
            'amount'         => '250',
            'method'         => $method,
            'paymentDetails' => $details,
            'description'    => self::DESCRIPTION_PREFIX.$suffix,
        ]);
    }

    private function storedDetails(string $suffix): ?string
    {
        $details = $this->em->getConnection()->fetchOne('SELECT payment_details FROM expense WHERE description = ?', [self::DESCRIPTION_PREFIX.$suffix]);
        self::assertNotFalse($details, 'The expense was saved.');

        return $details;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM expense WHERE description LIKE ?', [self::DESCRIPTION_PREFIX.'%']);
        $connection->executeStatement("DELETE FROM audit_log WHERE action LIKE 'expense.%' AND context LIKE ?", ['%'.self::DESCRIPTION_PREFIX.'%']);
        $connection->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $connection->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
