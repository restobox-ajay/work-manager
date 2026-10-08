<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-102 (review C7 / ADR-020): deactivating OR soft-deleting an account must immediately kill
 * ALL of its outstanding recovery artefacts (password-reset + magic-link tokens), and the reset
 * consumption flow must reject a token whose account is inactive — for plain users and admin-role accounts alike
 * (ADR-068: one `user` table, one reset flow, one token table).
 */
final class RecoveryTokenInvalidationTest extends WebTestCase
{
    private const PREFIX = 'rectok-';

    use AuthenticationTestTrait;

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
        try {
            $like = self::PREFIX . '%@example.com';
            $this->conn->executeStatement('DELETE FROM personal_access_tokens WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)', [$like]);
            $this->conn->executeStatement('DELETE FROM password_reset_tokens WHERE email LIKE ?', [$like]);
            $this->conn->executeStatement('DELETE FROM magic_link_tokens WHERE email LIKE ?', [$like]);
            $this->conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', [$like]);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    /** @param list<string> $roles */
    private function makeUser(string $email, string $status = 'active', array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Recovery Token User');
        $user->setPassword(password_hash('userpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus($status);
        $user->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->getId();
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($id);
    }

    /** @param list<string> $roles */
    private function makeAdmin(string $email, array $roles = ['ROLE_ADMIN'], string $status = 'active'): User
    {
        return $this->createTestAdmin($email, 'Recovery Token Admin', status: $status, roles: $roles);
    }

    /** @return string plaintext token */
    private function seedToken(string $table, string $email): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert($table, [
            'email'      => $email,
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
            'used_at'    => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function tokenUsedAt(string $table, string $email): ?string
    {
        $value = $this->conn->fetchOne('SELECT used_at FROM ' . $table . ' WHERE email = ?', [$email]);

        return $value === false ? null : ($value === null ? null : (string) $value);
    }

    /** An admin calls /admin-api with an ordinary personal access token (ADR-068). */
    private function makeAdminApiToken(User $admin): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('personal_access_tokens', [
            'user_id'    => $admin->getId(),
            'name'       => 'Recovery Token API',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }


    // AC1/AC5: deactivating a user (API) kills its outstanding password-reset token.
    public function testDeactivatingUserInvalidatesPasswordResetToken(): void
    {
        $admin = $this->makeAdmin(self::PREFIX . 'apiadmin@example.com');
        $token = $this->makeAdminApiToken($admin);
        $user  = $this->makeUser(self::PREFIX . 'target@example.com');
        $email = $user->getEmail();
        $this->seedToken('password_reset_tokens', $email);

        $this->assertNull($this->tokenUsedAt('password_reset_tokens', $email), 'precondition: token unused');

        $this->client->request('POST', '/admin-api/users/' . $user->getId() . '/deactivate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertNotNull(
            $this->tokenUsedAt('password_reset_tokens', $email),
            'Deactivation must mark the outstanding reset token as used'
        );
    }

    // AC7: deactivating a user (API) also kills its outstanding magic-link token.
    public function testDeactivatingUserInvalidatesMagicLinkToken(): void
    {
        $admin = $this->makeAdmin(self::PREFIX . 'apiadmin2@example.com');
        $token = $this->makeAdminApiToken($admin);
        $user  = $this->makeUser(self::PREFIX . 'magic@example.com');
        $email = $user->getEmail();
        $this->seedToken('magic_link_tokens', $email);

        $this->client->request('POST', '/admin-api/users/' . $user->getId() . '/deactivate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertNotNull(
            $this->tokenUsedAt('magic_link_tokens', $email),
            'Deactivation must mark the outstanding magic-link token as used'
        );
    }

    // AC8: soft-deleting a user (admin panel) kills its outstanding reset token.
    public function testSoftDeleteUserInvalidatesResetToken(): void
    {
        $this->makeAdmin(self::PREFIX . 'paneladmin@example.com');
        $user  = $this->makeUser(self::PREFIX . 'deltarget@example.com');
        $email = $user->getEmail();
        $userId = $user->getId();
        $this->seedToken('password_reset_tokens', $email);

        $this->loginAsAdmin(self::PREFIX . 'paneladmin@example.com');

        $crawler = $this->client->request('GET', '/admin/users');
        $form    = $crawler->filter('form[action="/admin/users/' . $userId . '/delete"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/users');

        $this->assertNotNull(
            $this->tokenUsedAt('password_reset_tokens', $email),
            'Soft delete must mark the outstanding reset token as used'
        );
    }

    // AC3: the user reset-consumption flow rejects a token whose account is inactive.
    public function testResetConsumptionRejectedForInactiveUser(): void
    {
        $user  = $this->makeUser(self::PREFIX . 'inactive@example.com', 'inactive');
        $email = $user->getEmail();
        $plaintext = $this->seedToken('password_reset_tokens', $email);

        $originalHash = (string) $this->conn->fetchOne('SELECT password FROM "user" WHERE email = ?', [$email]);

        // GET the reset page: the deactivated account must be rejected, not shown the form.
        $crawler = $this->client->request('GET', '/reset-password/' . $plaintext);
        $this->assertStringContainsString('no longer valid', $crawler->text());

        // POST a new password anyway: it must NOT be applied.
        $this->client->request('POST', '/reset-password/' . $plaintext, ['password' => 'BrandNewPass123!']);
        $newHash = (string) $this->conn->fetchOne('SELECT password FROM "user" WHERE email = ?', [$email]);

        $this->assertSame($originalHash, $newHash, 'Inactive account password must not change via reset');
    }

    // AC2/AC6: deactivating an admin (edit form -> inactive) kills its admin reset token.
    public function testDeactivatingAdminInvalidatesResetToken(): void
    {
        $this->makeAdmin(self::PREFIX . 'super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin(self::PREFIX . 'edittarget@example.com', ['ROLE_ADMIN']);
        $email  = $target->getEmail();
        $this->seedToken('password_reset_tokens', $email);

        $this->loginAsAdmin(self::PREFIX . 'super@example.com');

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId() . '/edit');
        $form = $crawler->filter('form[action$="/edit"]')->form();
        $form['status'] = 'inactive';
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/users');

        $this->assertNotNull(
            $this->tokenUsedAt('password_reset_tokens', $email),
            'Deactivating an admin must mark its outstanding reset token as used'
        );
    }

    // AC8: soft-deleting an admin (delete) kills its admin reset token.
    public function testSoftDeleteAdminInvalidatesResetToken(): void
    {
        $this->makeAdmin(self::PREFIX . 'super2@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin(self::PREFIX . 'deladmin@example.com', ['ROLE_ADMIN']);
        $email  = $target->getEmail();
        $this->seedToken('password_reset_tokens', $email);

        $this->loginAsAdmin(self::PREFIX . 'super2@example.com');

        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action="/admin/users/' . $target->getId() . '/delete"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/users');

        $this->assertNotNull(
            $this->tokenUsedAt('password_reset_tokens', $email),
            'Soft-deleting an admin must mark its outstanding reset token as used'
        );
    }

    // AC4: the (one) reset-consumption flow rejects a token whose admin-role account is inactive.
    public function testAdminResetConsumptionRejectedForInactiveAdmin(): void
    {
        $admin = $this->makeAdmin(self::PREFIX . 'inactiveadmin@example.com', ['ROLE_ADMIN'], 'inactive');
        $email = $admin->getEmail();
        $plaintext = $this->seedToken('password_reset_tokens', $email);

        $originalHash = (string) $this->conn->fetchOne('SELECT password FROM "user" WHERE email = ?', [$email]);

        $crawler = $this->client->request('GET', '/reset-password/' . $plaintext);
        $this->assertStringContainsString('no longer valid', $crawler->text());

        $this->client->request('POST', '/reset-password/' . $plaintext, ['password' => 'BrandNewPass123!']);
        $newHash = (string) $this->conn->fetchOne('SELECT password FROM "user" WHERE email = ?', [$email]);

        $this->assertSame($originalHash, $newHash, 'Inactive admin password must not change via reset');
    }
}
