<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-108, re-based on ADR-068: the admin-only notification store and config keys
 * (login_notifications.admin_*) are gone; an admin's login runs the one "new login from an unrecognised
 * device" flow. Recognition modes and the global switch are LoginNotificationTest's; this pins that admin
 * accounts are covered by it.
 */
final class AdminLoginNotificationTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'adminln1@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement('DELETE FROM login_notification_seen WHERE user_id IN (SELECT id FROM "user" WHERE email = ?)', [self::EMAIL]);
        $this->conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->conn->executeStatement('DELETE FROM audit_log WHERE actor = ?', [self::EMAIL]);
        $this->em->clear();
    }

    private function loginFrom(string $userAgent): void
    {
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $this->loginAsAdmin(self::EMAIL, followRedirect: false);
    }

    public function testAdminIsNotifiedOncePerNewDevice(): void
    {
        $this->createTestAdmin(self::EMAIL);

        $this->loginFrom('AdminAgent/1.0');
        $this->assertEmailCount(1);
        self::assertSame(1, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = 'login_notification_sent'",
            [self::EMAIL]
        ));

        // Same device again: recognised, no second email.
        $this->client->request('GET', '/logout');
        self::getContainer()->get('mailer.message_logger_listener')->reset();
        $this->loginFrom('AdminAgent/1.0');
        $this->assertEmailCount(0);
    }
}
