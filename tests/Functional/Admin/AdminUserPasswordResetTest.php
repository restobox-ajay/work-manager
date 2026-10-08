<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserPasswordResetTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $admin = (new User())->setRoles(['ROLE_ADMIN']);
        $admin->setEmail('pwreset-admin@example.com');
        $admin->setName('Reset Admin');
        $admin->setPassword(self::hashTestPassword('adminpass'));
        $this->em->persist($admin);

        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'pwreset-%@example.com'");
            $conn->executeStatement("DELETE FROM password_reset_tokens WHERE email LIKE 'pwreset-%@example.com'");
            $conn->executeStatement("DELETE FROM audit_log WHERE actor LIKE 'pwreset-%@example.com'");
            $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'pwreset-admin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email): User
    {
        return $this->createTestUser($email, 'Target User', 'userpass');
    }

    private function submitPasswordResetForm(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action="/admin/users/' . $userId . '/password-reset"]')->form();
        $this->client->submit($form);
    }

    // AC1: POST /admin/users/{id}/password-reset triggers reset email — creates token in DB and redirects
    public function testPasswordResetCreatesTokenAndRedirects(): void
    {
        $user = $this->createUser('pwreset-target1@example.com');
        $id   = $user->getId();

        $this->loginAsAdmin('pwreset-admin@example.com');
        $this->submitPasswordResetForm($id);

        $this->assertResponseRedirects('/admin/users');

        $this->em->clear();
        $token = $this->em->getRepository(PasswordResetToken::class)->findOneBy(['email' => 'pwreset-target1@example.com']);
        $this->assertNotNull($token, 'A PasswordResetToken should be created for the user.');
    }

    // AC2: The user receives a valid single-use reset link
    public function testCreatedTokenIsValidAndSingleUse(): void
    {
        $user = $this->createUser('pwreset-target2@example.com');
        $id   = $user->getId();

        $this->loginAsAdmin('pwreset-admin@example.com');
        $this->submitPasswordResetForm($id);

        $this->em->clear();
        $token = $this->em->getRepository(PasswordResetToken::class)->findOneBy(['email' => 'pwreset-target2@example.com']);
        $this->assertNotNull($token);
        $this->assertTrue($token->isValid(), 'Token should be valid (not expired, not used).');
        $this->assertFalse($token->isUsed(), 'Token should not be marked as used yet.');
        $this->assertFalse($token->isExpired(), 'Token should not be expired.');
    }

    // AC3: The action is recorded as an admin action in the audit log
    public function testPasswordResetCreatesAuditLogEntry(): void
    {
        $user = $this->createUser('pwreset-target3@example.com');
        $id   = $user->getId();

        $conn  = $this->em->getConnection();
        $before = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE actor = 'pwreset-admin@example.com' AND action = 'admin.user_password_reset'"
        );

        $this->loginAsAdmin('pwreset-admin@example.com');
        $this->submitPasswordResetForm($id);

        $after = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE actor = 'pwreset-admin@example.com' AND action = 'admin.user_password_reset' AND actor_type = 'admin'"
        );

        $this->assertSame($before + 1, $after, 'An audit log entry with action=admin.user_password_reset and actor_type=admin should be created.');
    }

    // ADR-068: the superadmin "reset this admin's password" action is now the same Users action, gated by
    // AccountManagementPolicy: a super admin may send it to an admin; a plain admin gets the hidden-account 404.
    public function testSuperAdminSendsAResetLinkToAnAdminButAPlainAdminCannot(): void
    {
        $target = $this->createTestUser('pwreset-target-admin@example.com', 'Target Admin', 'userpass', roles: ['ROLE_ADMIN']);
        $this->createTestUser('pwreset-super@example.com', 'Super', 'adminpass', roles: ['ROLE_SUPER_ADMIN']);

        $this->loginAsAdmin('pwreset-admin@example.com');
        $this->client->request('POST', '/admin/users/' . $target->getId() . '/password-reset', ['_token' => 'irrelevant']);
        $this->assertResponseStatusCodeSame(404);

        $this->client->getCookieJar()->clear();
        $this->loginAsAdmin('pwreset-super@example.com');
        $this->submitPasswordResetForm((int) $target->getId());
        $this->assertResponseRedirects('/admin/users');

        $this->em->clear();
        $this->assertNotNull(
            $this->em->getRepository(PasswordResetToken::class)->findOneBy(['email' => 'pwreset-target-admin@example.com']),
            'the admin receives a reset token',
        );
    }
}
