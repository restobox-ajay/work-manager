<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-128 (review C39): adversarial tests for the impersonation authorization seams, on the single
 * firewall of ADR-068 (impersonation is a token swap done by ImpersonationManager).
 *
 *  - A plain admin manages plain users only (AccountManagementPolicy): trying to impersonate another admin
 *    must be refused as an unknown account (404) BEFORE the CSRF check, and write no impersonation marker
 *    (fails if the policy check is removed or moved after CSRF).
 *  - The exit endpoint restores an impersonator only when an impersonation is in progress; hitting it
 *    anonymously must authenticate nobody.
 */
final class ImpersonationAuthzAdversarialTest extends WebTestCase
{
    use AuthenticationTestTrait;

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
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'impadv_%@example.com'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    /** @param list<string> $roles */
    private function createAdmin(string $email, array $roles): User
    {
        return $this->createTestAdmin($email, 'ImpAdv ' . $email, roles: $roles);
    }

    // AC2: a plain (non-superadmin) admin cannot impersonate another admin.
    public function testNonSuperadminAdminCannotStartAdminImpersonation(): void
    {
        $this->createAdmin('impadv_plain@example.com', ['ROLE_ADMIN']);
        $target = $this->createAdmin('impadv_target@example.com', ['ROLE_ADMIN']);

        $this->loginAsAdmin('impadv_plain@example.com');

        $this->client->request('POST', '/admin/users/' . $target->getId() . '/impersonate-start', [
            '_token' => 'bad', // no valid token; the management policy must reject first anyway
        ]);

        // An account the caller may not manage is indistinguishable from a missing one (ADR-050/068).
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());

        // The rejection must come from the policy check, which runs BEFORE the controller's CSRF check. If
        // the policy check is removed or reordered, the plain admin falls through to that CSRF check
        // ("Invalid CSRF token" 403) — so asserting the 404 keeps the test sensitive to weakening the gate.
        $this->assertStringNotContainsString(
            'Invalid CSRF token',
            (string) $this->client->getResponse()->getContent(),
            'A plain admin must be stopped by the management policy before the CSRF check'
        );

        // No impersonation marker was written into the session.
        $this->assertNull(
            $this->client->getRequest()->getSession()->get('_impersonating_as'),
            'A forbidden impersonation attempt must not set the impersonation marker'
        );

        // And the browser is still the plain admin, not the target.
        $this->client->request('GET', '/dashboard');
        $this->assertStringContainsString('Welcome, ImpAdv impadv_plain@example.com', (string) $this->client->getResponse()->getContent());
    }

    // AC2: the exit endpoint, hit with no impersonation in progress, authenticates nobody.
    public function testImpersonationExitWithoutAnImpersonationAuthenticatesNobody(): void
    {
        // Anonymous request straight to the exit endpoint.
        $this->client->request('POST', '/impersonate/exit');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        // Nobody is logged in: a protected user page still bounces to the user login.
        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
