<?php

declare(strict_types=1);

namespace App\Tests\Functional\Registration;

use App\Config\GeneralConfigPage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-095: the app is run by its owner, so nobody can sign themselves up. With no registration mode configured the
 * sign-up page does not exist without an invitation, and posting the form creates nothing.
 */
final class RegistrationClosedByDefaultTest extends WebTestCase
{
    private const WALK_IN_EMAIL = 'walk-in@example.com';

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

    public function testTheSignUpPageDoesNotExistWithoutAnInvitation(): void
    {
        $this->client->request('GET', '/register');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPostingTheSignUpFormCreatesNoAccount(): void
    {
        $this->client->request('POST', '/register', ['email' => self::WALK_IN_EMAIL, 'name' => 'Walk In', 'password' => 'Str0ng-enough-pass']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [self::WALK_IN_EMAIL]));
    }

    public function testAnUnknownModeIsTreatedAsInvitationOnly(): void
    {
        $this->em->getConnection()->executeStatement(
            'REPLACE INTO config (config_key, config_value) VALUES (?, ?)',
            [GeneralConfigPage::REGISTRATION_MODE_KEY, 'Open ']
        );

        $this->client->request('GET', '/register');

        self::assertResponseStatusCodeSame(404);
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM config WHERE config_key = ?', [GeneralConfigPage::REGISTRATION_MODE_KEY]);
        $connection->executeStatement('DELETE FROM "user" WHERE email = ?', [self::WALK_IN_EMAIL]);
        $connection->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
