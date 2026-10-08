<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ImpersonateTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();

        $this->createTestAdmin('imptest_admin@example.com', 'Impersonate Admin');
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
            $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'imptest_%'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, array $roles = []): User
    {
        return $this->createTestUser($email, 'Impersonate Target', 'userpass', 'active', $roles);
    }

    private function submitImpersonateForm(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action="/admin/users/' . $userId . '/impersonate-start"]')->form();
        $this->client->submit($form);
    }

    // AC1: Admin user list has an 'Impersonate' button
    public function testAdminUserListHasImpersonateButton(): void
    {
        $user = $this->createUser('imptest_user1@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action="/admin/users/' . $user->getId() . '/impersonate-start"]');
    }

    // AC2: Clicking impersonate switches the session to act as that user
    public function testImpersonateStartSwitchesSessionToUser(): void
    {
        $user = $this->createUser('imptest_user2@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->submitImpersonateForm((int) $user->getId());

        // The start endpoint swaps the security token to the target (ADR-068) and lands on /dashboard.
        $this->assertResponseRedirects('/dashboard');
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('imptest_user2@example.com', $content);

        // The browser now acts as the target: the dashboard greets them, not the admin.
        $this->assertStringContainsString('Welcome, Impersonate Target', $content);
        $this->assertStringNotContainsString('Welcome, Impersonate Admin', $content);
        $this->client->request('GET', '/admin/users');
        $this->assertResponseStatusCodeSame(403, 'a plain user target has no admin access while impersonated');
    }

    // AC3: A persistent banner indicates active impersonation
    public function testImpersonationBannerShownOnPage(): void
    {
        $user = $this->createUser('imptest_user3@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->submitImpersonateForm((int) $user->getId());

        $this->client->followRedirect(); // → /dashboard

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('impersonation-banner', $content);
        $this->assertStringContainsString('imptest_user3@example.com', $content);
    }

    // AC4: Clicking 'Exit impersonation' restores the admin session
    public function testExitImpersonationRestoresAdminSession(): void
    {
        $user = $this->createUser('imptest_user4@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->submitImpersonateForm((int) $user->getId());

        $this->client->followRedirect(); // → /dashboard

        // Submit the exit form from the banner
        $crawler = $this->client->getCrawler();
        $exitForm = $crawler->filter('form[action="/impersonate/exit"]')->form();
        $this->client->submit($exitForm);

        // The impersonator is restored and sent back to the user list.
        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.impersonation-banner');
    }

    // Issue #64: exit must actually take the browser out of the impersonated user's account. ContextListener used
    // to write the still-loaded user token back into the session at response time, so after "Exit Impersonation"
    // the browser was still logged in as the user (no banner, no 2FA skip, but full access). With one firewall
    // (ADR-068) exit swaps the token back, so every later request must act as the admin again.
    public function testExitImpersonationSignsTheBrowserOutOfTheUsersAccount(): void
    {
        $user = $this->createUser('imptest_user5@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->submitImpersonateForm((int) $user->getId());
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Welcome, Impersonate Target', (string) $this->client->getResponse()->getContent(), 'precondition: acting as the user');

        $this->client->submit($this->client->getCrawler()->filter('form[action="/impersonate/exit"]')->form());
        $this->assertResponseRedirects('/admin/users');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Welcome, Impersonate Target', $content, 'the user account must be closed after exit');
        $this->assertStringContainsString('Welcome, Impersonate Admin', $content, 'the browser is the admin again');
        $this->assertSelectorNotExists('.impersonation-banner');

        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful('the admin stays signed in');
    }
}

