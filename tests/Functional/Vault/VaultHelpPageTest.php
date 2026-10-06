<?php

declare(strict_types=1);

namespace App\Tests\Functional\Vault;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-092: the Password Manager guide — readable by admins, linked from the vault page and the menu, refused to
 * plain users like the vault itself.
 */
final class VaultHelpPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const ADMIN_EMAIL = 'vault-help-admin@example.com';
    private const USER_EMAIL = 'vault-help-user@example.com';

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

    public function testAnAdminReadsTheGuideExplainingTheMasterPassword(): void
    {
        $this->createTestUser(self::ADMIN_EMAIL, roles: ['ROLE_ADMIN']);
        $this->loginUser(self::ADMIN_EMAIL);

        $this->client->request('GET', '/vault/help');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'How the vault works');
        self::assertSelectorTextContains('.vault-help', 'Why is there a master password?');
        self::assertSelectorTextContains('.vault-help', 'There is no recovery.');
        self::assertSelectorExists('.ph-actions a[href="/vault"]');
    }

    public function testTheVaultPageAndTheMenuLinkToTheGuide(): void
    {
        $this->createTestUser(self::ADMIN_EMAIL, roles: ['ROLE_ADMIN']);
        $this->loginUser(self::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/vault');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(2, $crawler->filter('a[href="/vault/help"]')->count(), 'Linked from the page lead and the sidebar.');
    }

    public function testAPlainUserIsRefused(): void
    {
        $this->createTestUser(self::USER_EMAIL);
        $this->loginUser(self::USER_EMAIL);

        $this->client->request('GET', '/vault/help');

        self::assertResponseStatusCodeSame(403);
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email IN (?, ?)', [self::ADMIN_EMAIL, self::USER_EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
