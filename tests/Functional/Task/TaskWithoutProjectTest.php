<?php

declare(strict_types=1);

namespace App\Tests\Functional\Task;

use App\Entity\Task;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-131: a task may be filed with no client and no project — from the task form and from Quick Add.
 */
final class TaskWithoutProjectTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'task-no-project-admin@example.com';
    private const NAME_PREFIX = 'NoProjectTask ';

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

    public function testTheTaskFormSavesATaskWithNoProject(): void
    {
        $crawler = $this->client->request('GET', '/task/new');
        self::assertNull($crawler->filter('#f-project')->attr('required'), 'The project select is optional.');
        self::assertCount(0, $crawler->filter('label[for="f-project"] .req'), 'The project label is not marked required.');

        $this->client->submitForm('Create task', ['name' => self::NAME_PREFIX . 'form', 'projectId' => '']);

        self::assertResponseRedirects();
        $task = $this->findTask(self::NAME_PREFIX . 'form');
        self::assertNull($task->getProject());
        self::assertNull($task->getClientId());

        $this->client->request('GET', '/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.eyebrow', 'No project');
    }

    public function testQuickAddSavesARowWithNoProject(): void
    {
        $crawler = $this->client->request('GET', '/task/quick-add');
        self::assertSame('No project', trim($crawler->filter('select[name="rows[0][projectId]"] option')->first()->text()));

        $this->client->submitForm('Create tasks', ['rows[0][name]' => self::NAME_PREFIX . 'quick', 'rows[0][projectId]' => '']);

        self::assertResponseRedirects('/task');
        self::assertNull($this->findTask(self::NAME_PREFIX . 'quick')->getProject());

        $this->client->request('GET', '/task');
        self::assertResponseIsSuccessful();
    }

    private function findTask(string $name): Task
    {
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['name' => $name]);
        self::assertNotNull($task, "Task \"$name\" was saved.");

        return $task;
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM task WHERE name LIKE ?', [self::NAME_PREFIX . '%']);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
