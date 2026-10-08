<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #9: the "Allow Impersonation" toggle (impersonate.enabled, default on) must actually switch impersonation
 * off — for admins impersonating users AND superadmins impersonating admins — and hide the buttons. It used to be
 * saved and never read. Since ADR-068 both are the same flow (POST /admin/users/{id}/impersonate-start swaps the
 * token at once), so there is no queued handoff left for the toggle to catch later.
 */
final class ImpersonationToggleTest extends WebTestCase
{
    use AuthenticationTestTrait;

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
        $this->conn->executeStatement("DELETE FROM config WHERE config_key = 'impersonate.enabled'");
        $this->conn->executeStatement("DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'imptoggle-%')");
        $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'imptoggle-%'");
        $this->conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.impersonate%' AND actor LIKE 'imptoggle-%'");
        $this->em->clear();
    }

    private function disableImpersonation(): void
    {
        $this->conn->executeStatement("REPLACE INTO config (config_key, config_value) VALUES ('impersonate.enabled', '0')");
    }

    /** Plant a valid CSRF token in this browser's session, so only the toggle can stop the request. */
    private function plantCsrf(string $id): string
    {
        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/' . $id, 'planted-valid-token');
        $session->save();

        return 'planted-valid-token';
    }

    public function testWithTheToggleOffAnAdminCannotImpersonateAUser(): void
    {
        $user = $this->createTestUser('imptoggle-user@example.com', 'Toggle User');
        $this->createTestAdmin('imptoggle-admin@example.com', 'Toggle Admin');
        $this->disableImpersonation();
        $this->loginAsAdmin('imptoggle-admin@example.com');

        $this->client->request('GET', '/admin/users');
        self::assertSelectorNotExists(sprintf('form[action="/admin/users/%d/impersonate-start"]', $user->getId()), 'the button is hidden');

        $token = $this->plantCsrf('admin_user_impersonate_' . $user->getId());
        $this->client->request('POST', sprintf('/admin/users/%d/impersonate-start', $user->getId()), ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');
        self::assertNull($this->client->getRequest()->getSession()->get('_impersonating_as'), 'no impersonation started');
        self::assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.impersonate_start' AND actor = 'imptoggle-admin@example.com'"));

        $this->client->request('GET', '/dashboard');
        self::assertStringContainsString('Welcome, Toggle Admin', (string) $this->client->getResponse()->getContent(), 'the browser is still the admin, not the user');
        self::assertSelectorNotExists('.impersonation-banner');
    }

    public function testWithTheToggleOffASuperadminCannotImpersonateAnAdmin(): void
    {
        $target = $this->createTestAdmin('imptoggle-target@example.com', 'Toggle Target');
        $this->createTestAdmin('imptoggle-super@example.com', 'Toggle Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->disableImpersonation();
        $this->loginAsAdmin('imptoggle-super@example.com');

        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(sprintf('form[action="/admin/users/%d/impersonate-start"]', $target->getId()), 'the button is hidden');

        $token = $this->plantCsrf('admin_user_impersonate_' . $target->getId());
        $this->client->request('POST', sprintf('/admin/users/%d/impersonate-start', $target->getId()), ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');
        self::assertNull($this->client->getRequest()->getSession()->get('_impersonating_as'), 'no impersonation started');
        $this->client->followRedirect();
        self::assertSelectorNotExists('.impersonation-banner');

        $this->client->request('GET', '/dashboard');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Welcome, Toggle Super', $content, 'still the superadmin, not the target');
    }

    public function testWithTheToggleOnTheButtonsAreThere(): void
    {
        $user = $this->createTestUser('imptoggle-user@example.com');
        $this->createTestAdmin('imptoggle-admin@example.com');
        $this->conn->executeStatement("REPLACE INTO config (config_key, config_value) VALUES ('impersonate.enabled', '1')");
        $this->loginAsAdmin('imptoggle-admin@example.com');

        $this->client->request('GET', '/admin/users');
        self::assertSelectorExists(sprintf('form[action="/admin/users/%d/impersonate-start"]', $user->getId()));
    }
}
