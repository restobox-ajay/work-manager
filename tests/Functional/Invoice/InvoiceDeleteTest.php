<?php

declare(strict_types=1);

namespace App\Tests\Functional\Invoice;

use App\Entity\Invoice;
use App\Entity\InvoiceItem;
use App\Entity\InvoiceLog;
use App\Enum\InvoiceKind;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-097: an invoice that was never emailed can be deleted from the list (its lines and history go with it, the
 * audit log keeps a record); a sent invoice shows no Delete button and the server refuses to delete it.
 */
final class InvoiceDeleteTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'invoice-delete-admin@example.com';
    private const NUMBER_PREFIX = 'DELTEST-';

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

    public function testAnUnsentInvoiceHasADeleteButtonAndIsDeletedWithItsLinesAndHistory(): void
    {
        $id = $this->invoice(self::NUMBER_PREFIX.'1', sent: false);

        $crawler = $this->client->request('GET', '/invoice/independent');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action="/invoice/%d/delete"]', $id));
        self::assertCount(1, $form, 'An unsent invoice gets a Delete button.');

        $this->client->submit($form->form());

        self::assertResponseRedirects('/invoice/independent');
        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM invoice WHERE id = ?', [$id]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM invoice_item WHERE invoice_id = ?', [$id]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM invoice_log WHERE invoice_id = ?', [$id]));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'invoice.deleted' AND context LIKE ?", ['%'.self::NUMBER_PREFIX.'1%']));
    }

    public function testASentInvoiceHasNoDeleteButtonAndCannotBeDeleted(): void
    {
        $id = $this->invoice(self::NUMBER_PREFIX.'2', sent: true);

        $crawler = $this->client->request('GET', '/invoice/independent');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(sprintf('form[action="/invoice/%d/delete"]', $id)), 'A sent invoice gets no Delete button.');

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE id = ?', [$id]));
    }

    public function testAnInvoiceSentAfterTheListWasOpenedIsStillRefused(): void
    {
        $id = $this->invoice(self::NUMBER_PREFIX.'4', sent: false);
        $form = $this->client->request('GET', '/invoice/independent')->filter(sprintf('form[action="/invoice/%d/delete"]', $id))->form();
        // Emailed from another tab after this list was rendered: the stale button carries a valid token.
        $this->em->getConnection()->executeStatement('UPDATE invoice SET emailed_at = ? WHERE id = ?', [time(), $id]);

        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/invoice/%d', $id));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE id = ?', [$id]));
    }

    public function testDeletingWithoutTheCsrfTokenIsRefused(): void
    {
        $id = $this->invoice(self::NUMBER_PREFIX.'3', sent: false);

        $this->client->request('POST', sprintf('/invoice/%d/delete', $id), ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE id = ?', [$id]));
    }

    private function invoice(string $number, bool $sent): int
    {
        $invoice = (new Invoice())->setNumber($number)->setKind(InvoiceKind::Independent)->setCurrency('INR')
            ->setFromName('Billing Co')->setToName('Client Co')->setInvoiceDate(new \DateTimeImmutable('today'))
            ->setSubtotal('100.00')->setTotal('100.00')->setCreatedAt(time())->setEmailedAt($sent ? time() : null);
        $invoice->getItems()->add((new InvoiceItem())->setInvoice($invoice)->setPosition(1)->setName('Work')->setGstRate('0.00')
            ->setQuantity('1.00')->setRate('100.00')->setAmount('100.00')->setCgst('0.00')->setSgst('0.00')->setTotal('100.00'));
        $this->em->persist($invoice);
        $this->em->flush();
        $this->em->persist(new InvoiceLog((int) $invoice->getId(), 'created', null, null, time()));
        $this->em->flush();
        $id = (int) $invoice->getId();
        $this->em->clear();

        return $id;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        $ids = 'SELECT id FROM invoice WHERE number LIKE ?';
        $connection->executeStatement("DELETE FROM invoice_log WHERE invoice_id IN (SELECT * FROM ($ids) AS i)", [self::NUMBER_PREFIX.'%']);
        $connection->executeStatement('DELETE FROM invoice WHERE number LIKE ?', [self::NUMBER_PREFIX.'%']);
        $connection->executeStatement("DELETE FROM audit_log WHERE action = 'invoice.deleted' AND context LIKE ?", ['%'.self::NUMBER_PREFIX.'%']);
        $connection->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $connection->executeStatement('DELETE FROM endpoint_rate_limits');
        $this->em->clear();
    }
}
