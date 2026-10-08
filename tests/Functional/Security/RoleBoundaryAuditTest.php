<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-092, re-based on ADR-068 — the role boundary of the one `user` firewall.
 *
 * Since ADR-068 an admin is a User holding ROLE_ADMIN (or above), so the boundary is the role ladder
 * (ROLE_USER < ROLE_ADMIN < ROLE_SUPER_ADMIN < ROLE_TECH_SUPPORT) rather than a separate entity/firewall:
 *
 *   - a plain user cannot reach the ^/admin area and is not shown the admin menu;
 *   - a role string outside the ladder (e.g. one injected straight into the roles column) grants nothing;
 *   - an admin sees the admin menu but not the tech-support-only tools (DB console, Htaccess Lock),
 *     which are refused to them; tech support sees and reaches them.
 */
final class RoleBoundaryAuditTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const USER_EMAIL  = 'audit092_user@example.com';
    private const ADMIN_EMAIL = 'audit092_admin@example.com';
    private const TECH_EMAIL  = 'audit092_tech@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement(
            'DELETE FROM "user" WHERE email IN (?, ?, ?)',
            [self::USER_EMAIL, self::ADMIN_EMAIL, self::TECH_EMAIL]
        );
    }

    public function testPlainUserCannotReachTheAdminArea(): void
    {
        $this->createTestUser(self::USER_EMAIL);
        $this->loginUser(self::USER_EMAIL);

        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful('sanity: the user is signed in');

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/users');
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * A role string outside the ladder — written straight into the roles column, bypassing
     * User::setRoles()'s allowlist (worst case / simulated injection) — is not in role_hierarchy and grants
     * nothing.
     */
    public function testRoleStringOutsideTheLadderGrantsNoAdminAccess(): void
    {
        $user = $this->createTestUser(self::USER_EMAIL);
        $this->conn->executeStatement(
            'UPDATE "user" SET roles = ? WHERE id = ?',
            ['["ROLE_GOD","ROLE_ADMINISTRATOR","ROLE_ALLOWED_TO_SWITCH"]', $user->getId()]
        );
        $this->loginUser(self::USER_EMAIL);

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testPlainUserSeesTheAccountMenuButNotTheAdminMenu(): void
    {
        $this->createTestUser(self::USER_EMAIL);
        $this->loginUser(self::USER_EMAIL);

        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorNotExists('a[href="/admin/config"]');
        $this->assertSelectorNotExists('a[href="/admin/audit-log"]');
        $this->assertSelectorNotExists('a[href="/admin/users"]');
        // The account menu rendered, so the absences above are real.
        $this->assertSelectorExists('a[href="/account/settings"]');
    }

    public function testAdminSeesTheAdminMenuButNotTheTechSupportTools(): void
    {
        $this->createTestAdmin(self::ADMIN_EMAIL);
        $this->loginAsAdmin(self::ADMIN_EMAIL);

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists('a[href="/admin/config"]');
        $this->assertSelectorExists('a[href="/admin/users"]');
        $this->assertSelectorNotExists('a[href="/admin/db"]');
        $this->assertSelectorNotExists('a[href="/admin/htaccess-lock"]');

        $this->client->request('GET', '/admin/db');
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/htaccess-lock');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTechSupportSeesAndReachesTheTechSupportTools(): void
    {
        $this->loginAsEnrolledTechSupport(self::TECH_EMAIL);

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists('a[href="/admin/db"]');
        $this->assertSelectorExists('a[href="/admin/htaccess-lock"]');

        $this->client->request('GET', '/admin/db');
        $this->assertResponseIsSuccessful();
    }
}
