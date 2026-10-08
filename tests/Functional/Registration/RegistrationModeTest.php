<?php

declare(strict_types=1);

namespace App\Tests\Functional\Registration;

use App\Service\ConfigService;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationModeTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanupConfigKey('registration.mode');
        $this->removeTestAdmin();

        $this->createTestAdmin('modetest-admin@example.com', 'Mode Test Admin');
    }

    protected function tearDown(): void
    {
        $this->cleanupConfigKey('registration.mode');
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function cleanupConfigKey(string $key): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM config WHERE config_key = ?',
                [$key]
            );
        } catch (\Throwable) {}
    }

    private function removeTestAdmin(): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM "user" WHERE email = ?',
            ['modetest-admin@example.com']
        );
        $this->em->clear();
    }

    private function setMode(string $mode): void
    {
        /** @var ConfigService $configService */
        $configService = self::getContainer()->get(ConfigService::class);
        $configService->set('registration.mode', $mode);
        $this->em->clear();
    }

    // AC1: When mode=open, GET /register is publicly accessible
    public function testOpenModeAllowsRegistration(): void
    {
        $this->setMode('open');

        $this->client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
    }

    // AC2: When mode=invitation-only, GET /register without valid invite token is not found (ADR-095: was 403)
    public function testInvitationOnlyModeBlocksWithoutToken(): void
    {
        $this->setMode('invitation-only');

        $this->client->request('GET', '/register');

        $this->assertResponseStatusCodeSame(404);
    }

    // AC3: Admin can change the mode via /admin/config
    public function testAdminCanChangeModeViaConfig(): void
    {
        $this->loginAsAdmin('modetest-admin@example.com');

        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="general"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/general', [
            '_token' => $configToken,
            'fields' => ['registration.mode' => 'open'],
        ]);

        $this->assertResponseRedirects('/admin/config');

        $row = $this->em->getConnection()->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['registration.mode']
        );
        $this->assertSame('open', $row);
    }

    // AC4: Switching to invitation-only immediately prevents open registration
    public function testSwitchingToInvitationOnlyImmediatelyBlocksRegistration(): void
    {
        $this->setMode('open');
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        $this->setMode('invitation-only');
        $this->client->request('GET', '/register');
        $this->assertResponseStatusCodeSame(404);
    }
}
