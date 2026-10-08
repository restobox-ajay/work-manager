<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-109, re-based on ADR-068: the separate admin_login_history table and /admin/login-history page are
 * gone — an admin's interactive login is recorded in the one login_history like every account's and shown on
 * /account/login-history. Page behaviour in general (own rows only, auth required) is LoginHistoryViewTest's.
 */
final class AdminLoginHistoryTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'adminlh1@example.com';

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
        $this->conn->executeStatement('DELETE FROM login_history WHERE user_id IN (SELECT id FROM "user" WHERE email = ?)', [self::EMAIL]);
        $this->conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->em->clear();
    }

    public function testAdminLoginIsRecordedInTheOneLoginHistoryAndShownToThem(): void
    {
        $adminId = (int) $this->createTestAdmin(self::EMAIL, roles: ['ROLE_SUPER_ADMIN'])->getId();

        $this->client->setServerParameter('HTTP_USER_AGENT', 'AdminHistoryAgent/1.0');
        $this->loginAsAdmin(self::EMAIL);

        self::assertSame(
            1,
            (int) $this->conn->fetchOne('SELECT COUNT(*) FROM login_history WHERE user_id = ?', [$adminId]),
            'one interactive admin login = one login_history row'
        );

        $this->client->request('GET', '/account/login-history');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('AdminHistoryAgent/1.0', (string) $this->client->getResponse()->getContent());
    }

    public function testTheSeparateAdminLoginHistoryPageIsGone(): void
    {
        $this->createTestAdmin(self::EMAIL);
        $this->loginAsAdmin(self::EMAIL);

        $this->client->request('GET', '/admin/login-history');
        $this->assertResponseStatusCodeSame(404);
    }
}
