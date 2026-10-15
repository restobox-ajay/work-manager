<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rent;

use App\Entity\Rent\RentProperty;
use App\Entity\Rent\RentTenant;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-141: a tenant has any number (up to five) of mobile numbers, each with an optional label; blank rows are
 * skipped, a number must look like one (7–15 digits), the same number twice is refused, and every number is shown as
 * a tap-to-call link on the tenant list and page.
 */
final class RentTenantPhonesTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'rent-phones-admin@example.com';
    private const PROPERTY = 'Phones Test House';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $propertyId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
        $this->createTestUser(self::EMAIL, roles: ['ROLE_ADMIN']);
        $property = (new RentProperty())->setName(self::PROPERTY)->setElectricityRate('8.00')->setDefaultRent('5000.00');
        $this->em->persist($property);
        $this->em->flush();
        $this->propertyId = (int) $property->getId();
        $this->em->clear();
        $this->loginUser(self::EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testATenantIsSavedWithSeveralNumbersAndTheyAreShownAsCallLinks(): void
    {
        $this->post([
            ['number' => '+91 98100 12345', 'label' => 'Self'],
            ['number' => '', 'label' => ''],
            ['number' => '(0124) 456-7890', 'label' => ''],
        ]);
        self::assertResponseRedirects();

        $tenant = $this->tenant();
        self::assertSame([
            ['number' => '+91 98100 12345', 'label' => 'Self'],
            ['number' => '(0124) 456-7890', 'label' => null],
        ], $tenant->getPhones(), 'Blank rows are skipped; numbers are kept as typed.');

        $page = $this->client->request('GET', '/rent/tenants/'.$tenant->getId());
        self::assertSame('tel:+919810012345', $page->filter('.lead a')->eq(0)->attr('href'));
        self::assertSame('tel:01244567890', $page->filter('.lead a')->eq(1)->attr('href'));
        self::assertStringContainsString('(Self)', $page->filter('.lead')->text());

        $list = $this->client->request('GET', '/rent/tenants');
        self::assertCount(2, $list->filter('a[href^="tel:"]'));

        $edit = $this->client->request('GET', '/rent/tenants/'.$tenant->getId().'/edit');
        self::assertSame('(0124) 456-7890', $edit->filter('input[name="phones[1][number]"]')->attr('value'), 'The edit form shows each number.');
    }

    public function testBadDuplicateOrUnnumberedRowsAreRefused(): void
    {
        $this->post([
            ['number' => 'call me', 'label' => ''],
            ['number' => '12345', 'label' => 'Short'],
            ['number' => '98100 12345', 'label' => 'Self'],
            ['number' => '9810012345', 'label' => 'Again'],
            ['number' => '', 'label' => 'Father'],
        ]);

        self::assertResponseStatusCodeSame(422);
        $body = $this->client->getCrawler()->filter('body')->text();
        self::assertStringContainsString('Mobile number 1 is not a valid phone number.', $body);
        self::assertStringContainsString('Mobile number 2 ("Short") is not a valid phone number.', $body);
        self::assertStringContainsString('Mobile number 4 ("Again") is entered twice.', $body);
        self::assertStringContainsString('Mobile number 5 ("Father"): enter the number.', $body);
        self::assertNull($this->em->getRepository(RentTenant::class)->findOneBy(['name' => 'Phone Tenant']));
    }

    public function testAtMostFiveNumbers(): void
    {
        $this->post(array_map(static fn (int $i) => ['number' => '98100 1000'.$i, 'label' => ''], range(1, 6)));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'A tenant can have at most 5 numbers.');
    }

    /** @param list<array{number: string, label: string}> $phones */
    private function post(array $phones): void
    {
        $crawler = $this->client->request('GET', '/rent/tenants/new');
        $this->client->request('POST', '/rent/tenants/new', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'name' => 'Phone Tenant', 'propertyId' => (string) $this->propertyId, 'monthlyRent' => '5000', 'startDate' => '2026-01-01',
            'isActive' => '1', 'phones' => $phones,
        ]);
    }

    private function tenant(): RentTenant
    {
        $this->em->clear();
        $tenant = $this->em->getRepository(RentTenant::class)->findOneBy(['name' => 'Phone Tenant']);
        self::assertNotNull($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE t FROM rent_tenant t JOIN rent_property p ON p.id = t.property_id WHERE p.name = ?', [self::PROPERTY]);
        $conn->executeStatement('DELETE FROM rent_property WHERE name = ?', [self::PROPERTY]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
