<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-123 (review C31), re-based on ADR-068: the admin_sessions table and /admin/sessions page are gone —
 * an admin's interactive session is tracked in the one user_sessions table and listed on /account/sessions.
 * Terminating one/all sessions is Account\SessionManagementTest's. Kept here: an admin login is tracked, a
 * plain logout removes its row (no phantom session), and a stateless /admin-api token request tracks none.
 */
final class AdminSessionManagementTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'adminsess1@example.com';

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
        $ids = 'SELECT id FROM "user" WHERE email = ?';
        $this->conn->executeStatement("DELETE FROM user_sessions WHERE user_id IN ($ids)", [self::EMAIL]);
        $this->conn->executeStatement("DELETE FROM personal_access_tokens WHERE user_id IN ($ids)", [self::EMAIL]);
        $this->conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->em->clear();
    }

    private function createAdmin(): int
    {
        return (int) $this->createTestAdmin(self::EMAIL, 'Admin Session Test')->getId();
    }

    private function countSessions(int $userId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$userId]);
    }

    public function testAdminLoginIsTrackedAndListedOnTheAccountSessionsPage(): void
    {
        $adminId = $this->createAdmin();

        $this->client->setServerParameter('HTTP_USER_AGENT', 'AdminViewAgent/2.0');
        $this->loginAsAdmin(self::EMAIL);
        self::assertSame(1, $this->countSessions($adminId));

        $this->client->request('GET', '/account/sessions');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('AdminViewAgent/2.0', (string) $this->client->getResponse()->getContent());
    }

    // The listener must read the session id before Symfony's SessionLogoutListener invalidates (and so re-ids)
    // the session, or the row is left behind as a phantom.
    public function testLogoutRemovesTheSessionRow(): void
    {
        $adminId = $this->createAdmin();
        $this->loginAsAdmin(self::EMAIL);
        self::assertSame(1, $this->countSessions($adminId));

        $this->client->request('GET', '/logout');

        self::assertSame(0, $this->countSessions($adminId));
    }

    public function testAdminApiTokenRequestTracksNoSession(): void
    {
        $adminId = $this->createAdmin();
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('personal_access_tokens', [
            'user_id'    => $adminId,
            'name'       => 'PAT',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->client->request('GET', '/admin-api/users', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext]);

        $this->assertResponseIsSuccessful();
        self::assertSame(0, $this->countSessions($adminId), 'token auth must not create a session record');
    }

    public function testTheSeparateAdminSessionsPageIsGone(): void
    {
        $this->createAdmin();
        $this->loginAsAdmin(self::EMAIL);

        $this->client->request('GET', '/admin/sessions');
        $this->assertResponseStatusCodeSame(404);
    }
}
