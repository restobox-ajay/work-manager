<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-051, re-based on ADR-068: the admin-only "remember me" (a long admin session plus an email-prefill cookie,
 * with its admin-only lifetime key) is gone; "staying signed in is the remember-me cookie's job" for every
 * account. The cookie's own behaviour is RememberMeTest's. Kept here are the admin-side guarantees that
 * carried over: a remembered admin is let back into the admin area, and terminating their sessions ("Logout
 * Everywhere") cuts the remembered device off too — nothing left can re-authenticate it.
 */
final class AdminRememberMeTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'adminrm@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
        $this->createTestAdmin(self::EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM "user" WHERE email = ?)', [self::EMAIL]);
        $connection->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->em->clear();
    }

    /** Sign in with "remember me" ticked and return the issued REMEMBERME cookie. */
    private function loginRemembered(): \Symfony\Component\BrowserKit\Cookie
    {
        $this->client->request('POST', '/login', [
            'email'        => self::EMAIL,
            'password'     => 'adminpass',
            '_remember_me' => '1',
        ]);
        $this->assertResponseRedirects('/dashboard');

        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie, 'a REMEMBERME cookie must be issued');

        return $cookie;
    }

    /** "Come back later": the session is gone, only the remember-me cookie survives. */
    private function returnWithOnly(\Symfony\Component\BrowserKit\Cookie $rememberMe): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($rememberMe);
    }

    public function testRememberedAdminIsLetBackIntoTheAdminArea(): void
    {
        $rememberMe = $this->loginRemembered();
        $this->returnWithOnly($rememberMe);

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();
    }

    public function testLogoutEverywhereStillWinsOverRememberMe(): void
    {
        $rememberMe = $this->loginRemembered();

        $crawler = $this->client->request('GET', '/account/sessions');
        $this->client->submit($crawler->selectButton('Logout Everywhere')->form());
        $this->assertResponseRedirects('/login');

        $this->returnWithOnly($rememberMe);
        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseRedirects(
            '/login',
            null,
            'a remembered admin must be cut off once their sessions are terminated'
        );
    }
}
