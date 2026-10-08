<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The /admin-api boundary under ADR-068: /api and /admin-api share the stateless `api` firewall and the same
 * personal access tokens, and what a token may do is decided by its OWNER'S role. A plain user's valid token
 * authenticates (it works on /api) but is refused on /admin-api with a JSON 403; the same kind of token owned
 * by an admin is accepted. A role string outside the ladder, injected straight into the roles column, grants
 * no admin-API access.
 */
final class AdminApiBoundaryTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const USER_EMAIL  = 'boundary@example.com';
    private const ADMIN_EMAIL = 'boundary-admin@example.com';

    private KernelBrowser $client;
    private Connection $conn;
    private EntityManagerInterface $em;

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
            'DELETE FROM personal_access_tokens WHERE user_id IN (SELECT id FROM "user" WHERE email IN (?, ?))',
            [self::USER_EMAIL, self::ADMIN_EMAIL]
        );
        $this->conn->executeStatement('DELETE FROM "user" WHERE email IN (?, ?)', [self::USER_EMAIL, self::ADMIN_EMAIL]);
    }

    /** Insert a valid personal access token for the account and return its plaintext. */
    private function issueToken(int $userId): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('personal_access_tokens', [
            'user_id'    => $userId,
            'name'       => 'Boundary PAT',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function get(string $path, string $token): void
    {
        $this->client->request('GET', $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    public function testPlainUsersValidTokenIsRefusedOnTheAdminApi(): void
    {
        $user  = $this->createTestUser(self::USER_EMAIL, 'Boundary User');
        $token = $this->issueToken((int) $user->getId());

        $this->get('/admin-api/users', $token);
        $this->assertResponseStatusCodeSame(403);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        // Sanity: the token itself is valid — on /api it authenticates, so an unknown route is a 404, not a
        // 401. The 403 above is the role boundary, not a malformed token.
        $this->get('/api/__boundary_probe__', $token);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRoleStringOutsideTheLadderGrantsNoAdminApiAccess(): void
    {
        $user = $this->createTestUser(self::USER_EMAIL, 'Boundary User');
        $this->conn->executeStatement(
            'UPDATE "user" SET roles = ? WHERE id = ?',
            ['["ROLE_GOD","ROLE_ADMINISTRATOR"]', $user->getId()]
        );
        $token = $this->issueToken((int) $user->getId());

        $this->get('/admin-api/users', $token);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminsTokenIsAcceptedOnTheAdminApi(): void
    {
        $admin = $this->createTestAdmin(self::ADMIN_EMAIL);
        $token = $this->issueToken((int) $admin->getId());

        $this->get('/admin-api/users', $token);
        $this->assertResponseIsSuccessful();
    }
}
