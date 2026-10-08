<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Enum\Role;
use App\Repository\UserRepository;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Managing admin accounts. ADR-068 folded "Manage Admins" into Users: a super admin creates, promotes,
 * demotes, deactivates and deletes admins on /admin/users; a plain admin manages plain users only
 * (AccountManagementPolicy). The anti-lockout guards (never strip the last active super admin; never
 * delete/deactivate yourself) live in UserAccountAdminService.
 */
final class AdminManagementTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const PASSWORD = 'superpass';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'admgmt-%@example.com')");
        $conn->executeStatement("DELETE FROM audit_log WHERE actor LIKE 'admgmt-%@example.com'");
        $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'admgmt-%@example.com'");
        $this->em->clear();
    }

    /** @param list<string> $roles */
    private function makeAccount(string $email, array $roles, string $status = 'active'): User
    {
        return $this->createTestUser($email, 'Mgmt ' . $email, self::PASSWORD, $status, $roles);
    }

    private function loginAs(string $email, string $password = self::PASSWORD): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => $password]);
    }

    private function reload(string $email): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function editAs(User $target, string $role, string $status = 'active'): void
    {
        $this->client->request('GET', '/admin/users/' . $target->getId() . '/edit');
        $this->client->submitForm('Save Changes', [
            'email'  => $target->getEmail(),
            'name'   => 'Target',
            'role'   => $role,
            'status' => $status,
        ]);
    }

    private function submitDelete(User $target): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action$="/admin/users/' . $target->getId() . '/delete"]')->form();
        $this->client->submit($form);
    }

    public function testSuperadminCreatesAnAdmin(): void
    {
        $this->makeAccount('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create User', [
            'email'    => 'admgmt-created@example.com',
            'name'     => 'Created Admin',
            'password' => 'password123',
            'role'     => 'ROLE_ADMIN',
        ]);
        $this->assertResponseRedirects('/admin/users');

        $created = $this->reload('admgmt-created@example.com');
        $this->assertNotNull($created);
        $this->assertSame(Role::Admin, $created->getPrimaryRole());
        $this->assertSame('active', $created->getStatus());
    }

    public function testEditPromotesAndDemotesRoleWhenAnotherSuperExists(): void
    {
        $this->makeAccount('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAccount('admgmt-target@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $this->editAs($target, 'ROLE_SUPER_ADMIN');
        $this->assertResponseRedirects('/admin/users');
        $this->assertSame(Role::SuperAdmin, $this->reload('admgmt-target@example.com')->getPrimaryRole());

        // Demote again (now there are two supers, so the guard allows it).
        $this->editAs($target, 'ROLE_ADMIN');
        $this->assertResponseRedirects('/admin/users');
        $this->assertSame(Role::Admin, $this->reload('admgmt-target@example.com')->getPrimaryRole());
    }

    public function testDeactivatedAdminCannotLogIn(): void
    {
        $this->makeAccount('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAccount('admgmt-victim@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $this->editAs($target, 'ROLE_ADMIN', 'inactive');
        $this->assertResponseRedirects('/admin/users');
        $this->assertSame('inactive', $this->reload('admgmt-victim@example.com')->getStatus());

        $this->client->getCookieJar()->clear();
        $this->loginAs('admgmt-victim@example.com');
        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ADR-020 / FEATURE-110: deleting an admin is a SOFT delete — the row stays, status→inactive.
    public function testDeleteSoftDeletesAdmin(): void
    {
        $this->makeAccount('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAccount('admgmt-del@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $this->submitDelete($target);

        $this->assertResponseRedirects('/admin/users');
        $reloaded = $this->reload('admgmt-del@example.com');
        $this->assertNotNull($reloaded, 'Soft delete must keep the row');
        $this->assertSame('inactive', $reloaded->getStatus());
    }

    public function testCannotDeleteSelf(): void
    {
        $super = $this->makeAccount('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->makeAccount('admgmt-super2@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $this->submitDelete($super);

        $this->assertResponseRedirects('/admin/users');
        $this->assertSame('active', $this->reload('admgmt-super@example.com')->getStatus(), 'A super admin must not be able to delete themselves');
    }

    public function testCannotDemoteLastSuperadmin(): void
    {
        $super = $this->makeAccount('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->assertSame(
            1,
            self::getContainer()->get(UserRepository::class)->countActiveWithRole(Role::SuperAdmin),
            'precondition: this is the only active super admin',
        );
        $this->loginAs('admgmt-super@example.com');

        $this->editAs($super, 'ROLE_ADMIN');

        // Re-renders with an error, no redirect; role unchanged.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.error', 'last active super admin');
        $this->assertSame(Role::SuperAdmin, $this->reload('admgmt-super@example.com')->getPrimaryRole());
    }

    public function testRegularAdminCannotSeeOrManageAnotherAdmin(): void
    {
        $this->makeAccount('admgmt-regular@example.com', ['ROLE_ADMIN']);
        $other = $this->makeAccount('admgmt-other@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-regular@example.com');

        // Not manageable reads like an unknown id — a 404, never a 403.
        $this->client->request('GET', '/admin/users/' . $other->getId() . '/edit');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('admgmt-other@example.com', (string) $this->client->getResponse()->getContent());
    }

    public function testRegularAdminCannotGrantTheAdminRole(): void
    {
        $this->makeAccount('admgmt-regular@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-regular@example.com');

        $crawler = $this->client->request('GET', '/admin/users/new');
        $this->assertCount(0, $crawler->filter('select[name="role"] option[value="ROLE_ADMIN"]'), 'the role is not offered');

        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/users/new', [
            '_token'   => $token,
            'email'    => 'admgmt-escalated@example.com',
            'name'     => 'Escalated',
            'password' => 'password123',
            'role'     => 'ROLE_ADMIN',
            'status'   => 'active',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertNull($this->reload('admgmt-escalated@example.com'), 'a forged role must not create the account');
    }
}
