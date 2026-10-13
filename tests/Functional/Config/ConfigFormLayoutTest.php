<?php

declare(strict_types=1);

namespace App\Tests\Functional\Config;

use App\Entity\Settings\TaskStatus;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-132: the task status form shows Name, Order and Active side by side on one row, Name first; other Config
 * forms keep their two-column layout.
 */
final class ConfigFormLayoutTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'config-form-layout-admin@example.com';
    private const STATUS_NAME = 'Layout Test Status';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();
        $this->createTestUser(self::EMAIL, roles: ['ROLE_ADMIN']);
        $this->loginUser(self::EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testTheTaskStatusFormIsOneRowWithNameFirst(): void
    {
        $crawler = $this->client->request('GET', '/config/task-statuses/new');
        self::assertResponseIsSuccessful();

        $grid = $crawler->filter('form.mx-form .form-grid.cf-row');
        self::assertCount(1, $grid, 'The fields sit in one row.');
        self::assertSame(['name', 'order', 'isActive'], $grid->filter('input')->each(static fn ($input) => $input->attr('name')));
        self::assertCount(0, $grid->filter('.span-2'), 'No field spans the whole row.');
        self::assertNotNull($crawler->filter('#cf-name')->attr('autofocus'), 'Name, now first, takes the focus.');
    }

    public function testTheTaskStatusFormStillSaves(): void
    {
        $this->client->request('GET', '/config/task-statuses/new');
        $this->client->submitForm('Add', ['name' => self::STATUS_NAME, 'order' => '95']);

        self::assertResponseRedirects('/config/task-statuses');
        $status = $this->em->getRepository(TaskStatus::class)->findOneBy(['name' => self::STATUS_NAME]);
        self::assertNotNull($status);
        self::assertSame(95, $status->getOrder());
    }

    public function testOtherConfigFormsKeepTheTwoColumnLayout(): void
    {
        $crawler = $this->client->request('GET', '/config/task-types/new');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.form-grid.cf-row'));
        self::assertCount(1, $crawler->filter('form.mx-form .form-grid'));
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM task_status WHERE name = ?', [self::STATUS_NAME]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
