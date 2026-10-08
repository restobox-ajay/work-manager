<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Super admin impersonates an admin. Since ADR-068 this is the same flow as impersonating a user (the Users
 * list's Impersonate button, app_admin_users_impersonate_start); who may impersonate whom is
 * AccountManagementPolicy: a super admin manages admins, a plain admin manages plain users only.
 */
final class AdminImpersonateAdminTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'impersonate_admin_test_%'");
        $this->em->clear();
    }

    private static function impersonateFormSelector(int $targetId): string
    {
        return sprintf('form[action="/admin/users/%d/impersonate-start"]', $targetId);
    }

    // AC1: a super admin sees an Impersonate option on an admin's row.
    public function testSuperadminSeesImpersonateOptionForAnAdmin(): void
    {
        $this->createTestAdmin('impersonate_admin_test_super@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $target = $this->createTestAdmin('impersonate_admin_test_target@example.com');

        $this->loginAsAdmin('impersonate_admin_test_super@example.com');
        $this->client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(self::impersonateFormSelector((int) $target->getId()));
    }

    // AC2: a super admin can switch to act as an admin.
    public function testSuperadminCanSwitchToActAsAdmin(): void
    {
        $this->createTestAdmin('impersonate_admin_test_super2@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $target = $this->createTestAdmin('impersonate_admin_test_target2@example.com');

        $this->loginAsAdmin('impersonate_admin_test_super2@example.com');
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter(self::impersonateFormSelector((int) $target->getId()))->form());

        $this->assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('impersonation-banner', $content);
        $this->assertStringContainsString('Impersonating: impersonate_admin_test_target2@example.com', $content);
    }

    // AC3: a plain admin is not offered — and cannot start — impersonation of another admin: the account is
    // hidden from them, so the start endpoint answers like an unknown id.
    public function testRegularAdminCannotImpersonateAnotherAdmin(): void
    {
        $this->createTestAdmin('impersonate_admin_test_regular@example.com');
        $target = $this->createTestAdmin('impersonate_admin_test_target3@example.com');

        $this->loginAsAdmin('impersonate_admin_test_regular@example.com');
        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::impersonateFormSelector((int) $target->getId()));

        $this->client->request('POST', sprintf('/admin/users/%d/impersonate-start', $target->getId()), ['_token' => 'irrelevant']);
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/dashboard');
        $this->assertStringNotContainsString('impersonation-banner', (string) $this->client->getResponse()->getContent());
    }
}
