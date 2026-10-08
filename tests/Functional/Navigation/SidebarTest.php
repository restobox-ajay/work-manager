<?php

declare(strict_types=1);

namespace App\Tests\Functional\Navigation;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The role-aware left sidebar (templates/layout/_app_shell.html.twig + _nav_tree.html.twig) and the shell around
 * it. One account type (ADR-068): everyone signed in gets their own account pages; ROLE_ADMIN adds the management
 * pages and only an account actually holding ROLE_TECH_SUPPORT gets the maintainer tools. The markup and assets
 * are the Maxeme Auto mockup's (ADR-069).
 */
final class SidebarTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const USER_EMAIL = 'sidebar-user@example.com';
    private const SUPER_EMAIL = 'sidebar-superadmin@example.com';
    private const TECH_EMAIL = 'sidebar-techsupport@example.com';

    /** Your own account and security pages sit in the top-right account menu, not the sidebar. */
    private const OWN_ACCOUNT_LINKS = ['/account/settings', '/account/password', '/account/sessions', '/account/login-history', '/account/tokens', '/account/2fa/setup'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestUser(self::USER_EMAIL, 'Sidebar User', 'userpass');
        $this->createTestAdmin(self::SUPER_EMAIL, 'Sidebar SuperAdmin', 'superpass', roles: ['ROLE_SUPER_ADMIN']);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email IN (?, ?, ?)', [self::USER_EMAIL, self::SUPER_EMAIL, self::TECH_EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }

    private function loginAsSuperAdmin(): void
    {
        $this->loginAsAdmin(self::SUPER_EMAIL, 'superpass');
    }

    public function testUserSidebarShowsTheirOwnAccountPagesAndNoManagementPages(): void
    {
        $this->loginUser(self::USER_EMAIL, 'userpass');
        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('aside.sidebar');

        $this->assertSelectorExists('aside.sidebar a[href="/dashboard"]');
        $this->assertOwnAccountPagesAreInTheAccountMenu();
        // Logout lives in the account menu, as in the mockup (ADR-069).
        $this->assertSelectorExists('.topbar .user-menu .dropdown a[href="/logout"]');

        foreach (['/admin/dashboard', '/admin/users', '/admin/config', '/admin/audit-log', '/admin/db', '/admin/htaccess-lock'] as $href) {
            $this->assertSelectorNotExists(sprintf('aside.sidebar a[href="%s"]', $href), "A plain user must not be offered $href");
        }
    }

    public function testSuperAdminSidebarAddsTheManagementPagesToTheirOwnAccountPages(): void
    {
        $this->loginAsSuperAdmin();
        $this->client->request('GET', '/admin/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('aside.sidebar');

        foreach (['/admin/dashboard', '/admin/users', '/admin/users/invite', '/admin/users/invitations', '/admin/audit-log', '/admin/config', '/admin/api-docs'] as $href) {
            $this->assertSelectorExists(
                sprintf('aside.sidebar a[href="%s"]', $href),
                sprintf('Admin sidebar should link to %s', $href)
            );
        }
        // One account type: an admin has the same own-account pages as everyone else.
        $this->assertOwnAccountPagesAreInTheAccountMenu();

        // ROLE_TECH_SUPPORT is a hidden maintainer tier (ADR-050): a plain super admin must not see the
        // maintainer tools, since only an account actually HOLDING ROLE_TECH_SUPPORT does.
        $this->assertSelectorNotExists('aside.sidebar a[href="/admin/db"]');
        $this->assertSelectorNotExists('aside.sidebar a[href="/admin/htaccess-lock"]');
    }

    /** The account menu marks the current page and the breadcrumb still names it. */
    public function testAnAccountPageIsMarkedInTheAccountMenu(): void
    {
        $this->loginUser(self::USER_EMAIL, 'userpass');
        $this->client->request('GET', '/account/sessions');

        $this->assertSelectorExists('.topbar .user-menu .dropdown a.active[href="/account/sessions"][aria-current="page"]');
        $this->assertSelectorTextContains('.topbar .crumb', 'Security › Active Sessions');
    }

    public function testTechSupportSidebarShowsTheMaintainerTools(): void
    {
        $this->loginAsEnrolledTechSupport(self::TECH_EMAIL);
        $this->client->request('GET', '/admin/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('aside.sidebar a[href="/admin/db"]', 'Tech-support sidebar should link to the DB console.');
        $this->assertSelectorExists('aside.sidebar a[href="/admin/htaccess-lock"]', 'Tech-support sidebar should link to the Htaccess Lock.');
    }

    public function testUnauthenticatedLoginPageHasNoSidebar(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('aside.sidebar');
    }

    /**
     * The current page is marked in the sidebar, and its group is rendered open server-side (a <details> with
     * `open`), so the active page is visible without JavaScript. Other groups stay closed.
     */
    public function testTheCurrentPageIsMarkedAndOnlyItsGroupRendersOpen(): void
    {
        $this->loginAsSuperAdmin();
        $this->client->request('GET', '/admin/users');

        $this->assertSelectorExists('aside.sidebar li.active > a[href="/admin/users"][aria-current="page"]');
        $this->assertSelectorExists('aside.sidebar details.nav-group[open] a[href="/admin/users"]');
        $this->assertSelectorExists('aside.sidebar details.nav-group:not([open]) a[href="/admin/config"]');
        $this->assertSelectorNotExists('aside.sidebar details.nav-group:not([open]) li.active');
        // The breadcrumb is taken from the same menu entry.
        $this->assertSelectorTextContains('.topbar .crumb', 'Users › All Users');
    }

    public function testSignedInPagesUseTheThemeShell(): void
    {
        $this->loginAsSuperAdmin();
        $this->client->request('GET', '/admin/dashboard');

        $this->assertSelectorExists('body.site-app');
        $this->assertSelectorExists('link[href^="/css/app.css"]');
        $this->assertSelectorExists('link[href^="/css/app-bridge.css"]');
        $this->assertSelectorExists('script[src^="/js/app.js"]');
        $this->assertSelectorExists('script[src^="/js/early.js"]');
        $this->assertSelectorExists(sprintf('.topbar .user-menu-toggle[title="%s"]', self::SUPER_EMAIL));
        $this->assertSelectorExists('.topbar .user-menu .dropdown a[href="/logout"]');
        $this->assertSelectorExists('.frame main.content.page-body h1');
    }

    private function assertOwnAccountPagesAreInTheAccountMenu(): void
    {
        foreach (self::OWN_ACCOUNT_LINKS as $href) {
            $this->assertSelectorExists(sprintf('.topbar .user-menu .dropdown a[href="%s"]', $href), "The account menu should link to $href");
            $this->assertSelectorNotExists(sprintf('aside.sidebar a[href="%s"]', $href), "$href belongs in the account menu, not the sidebar");
        }
    }

    public function testSignedOutPagesUseTheSignInCard(): void
    {
        $this->client->request('GET', '/login');

        $this->assertSelectorExists('body.site-auth');
        $this->assertSelectorExists('.auth .auth-card.page-body form');
        $this->assertSelectorNotExists('.topbar');
    }

    public function testTheThemeAssetsTheLayoutLinksExist(): void
    {
        $publicDir = self::getContainer()->getParameter('kernel.project_dir') . '/public';

        foreach (['/css/app.css', '/css/app-bridge.css', '/js/app.js', '/js/early.js'] as $asset) {
            self::assertFileExists($publicDir . $asset);
        }
    }
}
