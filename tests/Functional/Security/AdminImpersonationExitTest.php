<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Review C18, re-based on ADR-068: ending an impersonation restores the impersonator by IDENTIFIER, re-loaded
 * and re-checked from the database (ImpersonationManager::exit), and fails closed — signs the browser out —
 * when the impersonator can no longer sign in.
 */
final class AdminImpersonationExitTest extends WebTestCase
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
        $this->em->getConnection()->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'imp_exit_test_%'");
        $this->em->clear();
    }

    /** Start impersonating $target from the Users list; returns the crawler of the impersonated landing page. */
    private function startImpersonation(User $target): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter(sprintf('form[action="/admin/users/%d/impersonate-start"]', $target->getId()))->form());

        $landing = $this->client->followRedirect();
        $this->assertStringContainsString(
            'impersonation-banner',
            (string) $this->client->getResponse()->getContent(),
            'Impersonation should be active after start'
        );

        return $landing;
    }

    // AC5: exit restores the original super admin (identity + roles).
    public function testExitRestoresOriginalSuperadmin(): void
    {
        $this->createTestAdmin('imp_exit_test_super@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $target = $this->createTestAdmin('imp_exit_test_target@example.com');

        $this->loginAsAdmin('imp_exit_test_super@example.com');
        $landing = $this->startImpersonation($target);

        // While impersonating the plain admin, the admin-only-by-super-admin edit page is out of reach.
        $this->client->request('GET', sprintf('/admin/users/%d/edit', $target->getId()));
        $this->assertResponseStatusCodeSame(404);

        $this->client->submit($landing->filter('form[action="/impersonate/exit"]')->form());
        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('impersonation-banner', (string) $this->client->getResponse()->getContent());

        // ROLE_SUPER_ADMIN restored: managing an admin account works again.
        $this->client->request('GET', sprintf('/admin/users/%d/edit', $target->getId()));
        $this->assertResponseIsSuccessful();
    }

    // AC6: if the original super admin is disabled mid-impersonation, exit fails closed — no restored session.
    public function testExitFailsClosedWhenOriginalSuperadminDisabled(): void
    {
        $this->createTestAdmin('imp_exit_test_super2@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $target = $this->createTestAdmin('imp_exit_test_target2@example.com');

        $this->loginAsAdmin('imp_exit_test_super2@example.com');
        $landing = $this->startImpersonation($target);

        $this->em->getConnection()->executeStatement(
            'UPDATE "user" SET status = ? WHERE email = ?',
            ['inactive', 'imp_exit_test_super2@example.com']
        );
        $this->em->clear();

        $this->client->submit($landing->filter('form[action="/impersonate/exit"]')->form());
        $this->assertResponseRedirects('/login');

        // No live session remains: neither the impersonator's nor the target's.
        $this->client->request('GET', '/admin/users');
        $this->assertResponseRedirects('/login');
    }
}
