<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-133 / review C23, re-based on ADR-068 — impersonation authorization:
 *  (1) impersonation runs the same UserChecker a real login runs, so an inactive admin cannot be impersonated
 *      into a live session, and the refusal is shown to the impersonator;
 *  (2) demoting an account must take its old privileges away from its live session on the next request.
 */
final class AdminImpersonationAuthzTest extends WebTestCase
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
        $this->em->getConnection()->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'imp_authz_test_%'");
        $this->em->clear();
    }

    // AC1/AC2/AC4: impersonating an inactive admin is rejected (UserChecker runs); no impersonation starts.
    public function testImpersonatingInactiveAdminIsRejected(): void
    {
        $this->createTestAdmin('imp_authz_test_super@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $inactive = $this->createTestAdmin('imp_authz_test_inactive@example.com', status: 'inactive');

        $this->loginAsAdmin('imp_authz_test_super@example.com');
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter(sprintf('form[action="/admin/users/%d/impersonate-start"]', $inactive->getId()))->form();
        $this->client->submit($form);

        // Rejected fail-closed: bounced back to the Users list, not to the success target (/dashboard).
        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('impersonation-banner', $content);
        $this->assertStringContainsString('cannot be impersonated', $content, 'the refusal must be shown to the impersonator');
    }

    // AC3/AC5: demoting an account with a live session takes the higher role away on its next request.
    public function testDemotingTechSupportDropsTheRoleInTheLiveSession(): void
    {
        $this->loginAsEnrolledTechSupport('imp_authz_test_tech@example.com');
        $this->client->request('GET', '/admin/db');
        $this->assertResponseIsSuccessful('sanity: tech support reaches the tech-support-only console');

        // Demoted to super admin (persisted) while the session is live.
        $tech = $this->em->getRepository(User::class)->findOneBy(['email' => 'imp_authz_test_tech@example.com']);
        $tech->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em->flush();
        $this->em->clear();

        // The live session must no longer carry ROLE_TECH_SUPPORT: the console is refused (403) or the stale
        // session is signed out (redirect to /login) — never served.
        $this->client->request('GET', '/admin/db');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertNotSame(200, $status, 'a demoted account must not keep its old role in a live session');
        $this->assertContains($status, [302, 403]);
    }
}
