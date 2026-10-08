<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-021, kept by ADR-068 (which replaced the old cross-realm lockout test — there is one realm now):
 * accounts holding an admin role are never hard-locked. Account-keyed lockout is a username-targeted DoS, and
 * locking out the people who unlock others would be self-defeating; admins are still rate-limited per IP.
 * A plain user past the same threshold IS locked (the control case).
 */
final class AdminAccountLockoutTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const ADMIN_EMAIL = 'lockout-admin@example.com';
    private const USER_EMAIL  = 'lockout-user@example.com';
    private const THRESHOLD   = 3;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->cleanup();

        $this->setConfig('lockout.max_attempts', (string) self::THRESHOLD);
        $this->setConfig('lockout.duration_minutes', '15');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement('DELETE FROM login_attempts');
        $this->conn->executeStatement('DELETE FROM "user" WHERE email IN (?, ?)', [self::ADMIN_EMAIL, self::USER_EMAIL]);
        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key IN ('lockout.max_attempts', 'lockout.duration_minutes')"
        );
        $this->em->clear();
    }

    private function setConfig(string $key, string $value): void
    {
        $this->conn->executeStatement('REPLACE INTO config (config_key, config_value) VALUES (?, ?)', [$key, $value]);
    }

    private function failLoginsPastTheThreshold(string $email): void
    {
        for ($i = 0; $i <= self::THRESHOLD; ++$i) {
            $this->client->request('GET', '/login');
            $this->client->submitForm('Sign in', ['email' => $email, 'password' => 'wrongpassword']);
        }
    }

    private function isLocked(string $email): bool
    {
        return $this->conn->fetchOne(
            'SELECT 1 FROM account_lockouts WHERE user_id = (SELECT id FROM "user" WHERE email = ?)',
            [$email]
        ) !== false;
    }

    /** @return iterable<string, array{list<string>}> */
    public static function adminRoles(): iterable
    {
        yield 'admin' => [['ROLE_ADMIN']];
        yield 'super admin' => [['ROLE_SUPER_ADMIN']];
        yield 'tech support' => [['ROLE_TECH_SUPPORT']];
    }

    /** @param list<string> $roles */
    #[\PHPUnit\Framework\Attributes\DataProvider('adminRoles')]
    public function testAnAdminRoleAccountIsNeverHardLocked(array $roles): void
    {
        $this->createTestAdmin(self::ADMIN_EMAIL, roles: $roles);

        $this->failLoginsPastTheThreshold(self::ADMIN_EMAIL);

        self::assertFalse($this->isLocked(self::ADMIN_EMAIL), 'an admin-role account must never get a lockout row');
    }

    public function testAnAdminCanStillSignInAfterFailuresPastTheThreshold(): void
    {
        $this->createTestAdmin(self::ADMIN_EMAIL, 'Lockout Admin', 'adminpass');
        $this->failLoginsPastTheThreshold(self::ADMIN_EMAIL);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => self::ADMIN_EMAIL, 'password' => 'adminpass']);

        $this->assertResponseRedirects('/dashboard');
    }

    public function testAPlainUserPastTheThresholdIsLocked(): void
    {
        $this->createTestUser(self::USER_EMAIL);

        $this->failLoginsPastTheThreshold(self::USER_EMAIL);

        self::assertTrue($this->isLocked(self::USER_EMAIL), 'control: the same failures do lock a plain user');
    }
}
