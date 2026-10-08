<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Entity\User;
use App\Enum\Role;
use App\Repository\UserRepository;
use App\Service\TotpService;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ROLE_TECH_SUPPORT (ADR-050, carried into the one user table by ADR-068 / AccountManagementPolicy): a
 * maintainer tier above super admin that is invisible to every other account — super admins included — on the
 * account-management surfaces (/admin/users). Tech support sees everyone, each other included. A hidden target
 * is indistinguishable from a nonexistent id (404), and the role is neither offered to nor assignable by
 * anyone else.
 */
final class TechSupportVisibilityTest extends WebTestCase
{
    use AuthenticationTestTrait;

    /** A fixed enrolment secret so tech-support logins can clear the mandatory-2FA gate. */
    private const TS_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

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
        $conn->executeStatement(
            "DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'techsup-%@example.com')"
        );
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
        $conn->executeStatement("DELETE FROM audit_log WHERE actor LIKE 'techsup-%@example.com'");
        $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'techsup-%@example.com'");
        $this->em->clear();
    }

    private function reload(int $id): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($id);
    }

    /**
     * A tech-support account already enrolled in TOTP, so a later login can clear the mandatory-2FA gate
     * (ADR-050 / FEATURE-149 force 2FA for this role).
     */
    private function createTechSupport(string $email, string $name): User
    {
        $account = $this->createTestAdmin($email, $name, roles: ['ROLE_TECH_SUPPORT']);
        self::getContainer()->get(TwoFactorSettingsRepository::class)->enable($account, self::TS_TOTP_SECRET);
        $this->em->clear();

        return $this->reload((int) $account->getId());
    }

    /** Log in an enrolled tech-support account and complete the 2FA challenge. */
    private function loginAsTechSupport(string $email): void
    {
        $this->loginAsAdmin($email, followRedirect: false);
        $this->client->request('GET', '/admin/dashboard');
        $this->client->followRedirect(); // GET /2fa/challenge (the form)
        $code = self::getContainer()->get(TotpService::class)->generateCode(self::TS_TOTP_SECRET);
        $this->client->submitForm('Verify', ['_code' => $code]);
        $this->client->followRedirect();
    }

    // ---------------------------------------------------------------- hierarchy

    public function testTechSupportManagesSuperAdminsAndReachesTechSupportOnlySurfaces(): void
    {
        $super = $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsTechSupport('techsup-ts1@example.com');

        $this->client->request('GET', sprintf('/admin/users/%d/edit', $super->getId()));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/db');
        self::assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------- list visibility

    public function testTechSupportHiddenFromSuperadminList(): void
    {
        $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsAdmin('techsup-super@example.com');

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('techsup-ts1@example.com', $crawler->html());
        self::assertStringContainsString('techsup-super@example.com', $crawler->html());
    }

    public function testTechSupportAccountsSeeEachOtherInList(): void
    {
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->createTestAdmin('techsup-ts2@example.com', 'TS Two', roles: ['ROLE_TECH_SUPPORT']);
        $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsTechSupport('techsup-ts1@example.com');

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('techsup-ts1@example.com', $crawler->html());
        self::assertStringContainsString('techsup-ts2@example.com', $crawler->html());
        self::assertStringContainsString('techsup-super@example.com', $crawler->html());
    }

    // ---------------------------------------------------------------- per-id 404 gating

    public function testEveryPerIdRouteIs404ForHiddenTargetIncludingControlProof(): void
    {
        $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $visible = $this->createTestAdmin('techsup-plain@example.com', 'Plain', roles: ['ROLE_ADMIN']);
        $tech    = $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsAdmin('techsup-super@example.com');

        // Control: the same route resolves for a visible admin, so the 404s below are the
        // visibility gate, not a routing artifact.
        $this->client->request('GET', sprintf('/admin/users/%d/edit', $visible->getId()));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/admin/users/%d/edit', $tech->getId()));
        self::assertResponseStatusCodeSame(404);

        foreach (['delete', 'password-reset', 'reset-2fa', 'unlock', 'revoke-tokens', 'impersonate-start'] as $action) {
            $this->client->request('POST', sprintf('/admin/users/%d/%s', $tech->getId(), $action));
            self::assertResponseStatusCodeSame(
                404,
                sprintf('POST %s for a hidden account must 404 before any CSRF/guard logic.', $action)
            );
        }
    }

    public function testTechSupportCanEditFellowTechSupport(): void
    {
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $other = $this->createTestAdmin('techsup-ts2@example.com', 'TS Two', roles: ['ROLE_TECH_SUPPORT']);
        $this->loginAsTechSupport('techsup-ts1@example.com');

        $this->client->request('GET', sprintf('/admin/users/%d/edit', $other->getId()));
        self::assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------- role assignment

    public function testSuperadminCannotAssignTechSupportAndGetsNoHint(): void
    {
        $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsAdmin('techsup-super@example.com');

        // The role is not advertised in the form...
        $crawler = $this->client->request('GET', '/admin/users/new');
        self::assertStringNotContainsString('ROLE_TECH_SUPPORT', $crawler->html());

        // ...and a forged submission is refused exactly like an unknown role string.
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/users/new', [
            '_token'   => $token,
            'email'    => 'techsup-forged@example.com',
            'name'     => 'Forged',
            'password' => 'ValidPassw0rd!',
            'role'     => 'ROLE_TECH_SUPPORT',
            'status'   => 'active',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.error', 'Invalid role.');
        self::assertStringNotContainsString('Tech Support', (string) $this->client->getResponse()->getContent());

        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'techsup-forged@example.com']));
    }

    public function testTechSupportCanCreateTechSupportViaUi(): void
    {
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsTechSupport('techsup-ts1@example.com');

        $crawler = $this->client->request('GET', '/admin/users/new');
        self::assertCount(1, $crawler->filter('select[name="role"] option[value="ROLE_TECH_SUPPORT"]'));

        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/users/new', [
            '_token'   => $token,
            'email'    => 'techsup-ts3@example.com',
            'name'     => 'TS Three',
            'password' => 'ValidPassw0rd!',
            'role'     => 'ROLE_TECH_SUPPORT',
            'status'   => 'active',
        ]);
        self::assertResponseRedirects('/admin/users');

        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'techsup-ts3@example.com']);
        self::assertNotNull($created);
        self::assertContains('ROLE_TECH_SUPPORT', $created->getRoles());
    }

    // ---------------------------------------------------------------- lockout guard

    public function testLastVisibleSuperadminGuardIgnoresTechSupport(): void
    {
        $super = $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        self::assertSame(
            1,
            self::getContainer()->get(UserRepository::class)->countActiveWithRole(Role::SuperAdmin),
            'precondition: techsup-super is the only active super admin',
        );
        $this->loginAsTechSupport('techsup-ts1@example.com');

        // Tech support outranks a super admin, but must NOT count as one for the anti-lockout guard:
        // the client's last visible super admin stays protected even though a (hidden) maintainer
        // could technically recover access.
        $crawler = $this->client->request('GET', '/admin/users');
        $deleteAction = sprintf('/admin/users/%d/delete', $super->getId());
        $token = $crawler->filter(sprintf('form[action$="%s"] input[name="_token"]', $deleteAction))->attr('value');
        $this->client->request('POST', $deleteAction, ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');

        self::assertTrue($this->reload((int) $super->getId())->isActive(), 'Last visible superadmin must survive the delete attempt.');
    }
}
