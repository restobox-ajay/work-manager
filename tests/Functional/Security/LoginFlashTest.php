<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\PasswordResetToken;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * FEATURE-125 (review C35), carried over to the one login page by ADR-068: /login must render flash
 * messages. PasswordResetController::reset sets a 'success' flash and redirects to /login; if the login
 * template rendered only the security `error`, that confirmation would silently vanish.
 */
final class LoginFlashTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'loginflash@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanUp();
        // An admin-role account: since ADR-068 admins use the same reset flow and login page as everyone.
        $this->createTestAdmin(self::EMAIL, 'Login Flash Admin');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM password_reset_tokens WHERE email = ?', [self::EMAIL]);
        $connection->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->em->clear();
    }

    /** AC1/AC2/AC3: a completed self-service reset lands on /login with its success flash visible. */
    public function testResetSuccessFlashShownOnLogin(): void
    {
        $plaintextToken = bin2hex(random_bytes(32));
        $this->em->persist(new PasswordResetToken(
            self::EMAIL,
            hash('sha256', $plaintextToken),
            new \DateTimeImmutable('+1 hour'),
        ));
        $this->em->flush();
        $this->em->clear();

        $this->client->request('POST', '/reset-password/' . $plaintextToken, [
            'password' => 'BrandNewAdminPassword123',
        ]);

        $this->assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.success', 'the login page must render the reset-success flash');
        $this->assertStringContainsString('Your password has been reset', $crawler->filter('.success')->text());
    }

    /** AC1/AC3: an 'error' flash set before landing on /login is displayed. */
    public function testErrorFlashShownOnLogin(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->getFlashBag()->add('error', 'Login flash error rendered.');
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        $crawler = $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error', 'the login page must render error flashes');
        $this->assertStringContainsString('Login flash error rendered.', $crawler->filter('.error')->text());
    }
}
