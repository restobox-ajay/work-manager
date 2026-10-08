<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-068: admins sign in through the one login page (/login) like every account; the separate /admin/login
 * is gone. The generic login behaviour (form, bad password, already-signed-in bounce) is UserLoginTest's; this
 * pins what is admin-specific.
 */
final class AdminLoginTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'adminlogintest@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();
        $this->createTestAdmin(self::EMAIL, 'Admin Login Test', 'adminpassword');
    }

    protected function tearDown(): void
    {
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->em->clear();
    }

    public function testTheSeparateAdminLoginPageIsGone(): void
    {
        $this->client->request('GET', '/admin/login');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAdminSignsInThroughTheOneLoginAndReachesTheAdminArea(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            'email' => self::EMAIL,
            'password' => 'adminpassword',
        ]);

        $this->assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();
    }

    public function testUnauthenticatedAccessToAdminRouteRedirectsToTheOneLogin(): void
    {
        $this->client->request('GET', '/admin/dashboard');

        $this->assertResponseRedirects('/login');
    }
}
