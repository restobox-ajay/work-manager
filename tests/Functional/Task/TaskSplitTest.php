<?php

declare(strict_types=1);

namespace App\Tests\Functional\Task;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-139: split a task (work-platform's task split) — row 1 edits the task, every named row below becomes a new
 * task in the same project for the same contractor and reviewer; all rows are checked before anything is saved.
 */
final class TaskSplitTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const ADMIN = 'task-split-admin@example.com';
    private const MEMBER = 'task-split-member@example.com';
    private const PREFIX = 'SplitTest ';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $projectId;
    private int $otherProjectId;
    private int $contractorId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();

        $this->createTestUser(self::ADMIN, roles: ['ROLE_ADMIN']);
        $this->contractorId = (int) $this->createTestUser(self::MEMBER)->getId();

        $client = (new Client())->setName(self::PREFIX.'Client');
        $project = (new Project())->setClient($client)->setName(self::PREFIX.'Project');
        $other = (new Project())->setClient($client)->setName(self::PREFIX.'Other project');
        foreach ([$client, $project, $other] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->projectId = (int) $project->getId();
        $this->otherProjectId = (int) $other->getId();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testThePageShowsTheTaskAndOneNewRowPrefilled(): void
    {
        $id = $this->persistTask('Build site', '100.00');
        $this->loginUser(self::ADMIN);

        $crawler = $this->client->request('GET', "/task/$id");
        self::assertCount(1, $crawler->filter('a[href="/task/'.$id.'/split"]'), 'The task page offers Split.');

        $crawler = $this->client->request('GET', "/task/$id/split");
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-split-rows] [data-split-row]'));
        self::assertSame(self::PREFIX.'Build site', $crawler->filter('input[name="rows[1][name]"]')->attr('value'), 'A new row starts with the name.');
        self::assertSame('100.00', $crawler->filter('input[name="rows[0][totalAmount]"]')->attr('value'));
        self::assertSame('0.00', $crawler->filter('input[name="rows[1][totalAmount]"]')->attr('value'), 'A new row starts with no payout.');

        $crawler = $this->client->request('GET', "/task/$id/split?rows=3");
        self::assertCount(4, $crawler->filter('[data-split-rows] [data-split-row]'), '"Add row" without JavaScript.');
    }

    public function testSplittingUpdatesTheTaskAndCreatesTheNamedRows(): void
    {
        $id = $this->persistTask('Build site', '100.00');
        $this->loginUser(self::ADMIN);

        $this->postSplit($id, [
            ['name' => self::PREFIX.'Build site — design', 'totalAmount' => '60.00', 'taskStatusId' => (string) TaskStatus::PENDING_ID],
            ['name' => self::PREFIX.'Build site — code', 'totalAmount' => '30', 'dueDate' => '2026-11-20', 'taskStatusId' => (string) TaskStatus::PENDING_ID, 'projectId' => (string) $this->otherProjectId],
            ['name' => self::PREFIX.'Build site — QA', 'totalAmount' => '10.00', 'taskStatusId' => (string) TaskStatus::PENDING_ID],
            ['name' => '', 'totalAmount' => '5.00'],
        ]);

        $code = $this->findTask('Build site — code');
        self::assertResponseRedirects('/task/'.$code->getId(), message: 'Lands on the first new task.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash, .alert, body', 'Task split into 2 new tasks.');

        $original = $this->em->getRepository(Task::class)->find($id);
        self::assertSame(self::PREFIX.'Build site — design', $original->getName());
        self::assertSame('60.00', $original->getTotalAmount());
        self::assertSame('Original description', $original->getDescription(), 'Fields not on the split form are kept.');

        $qa = $this->findTask('Build site — QA');
        foreach ([$code, $qa] as $new) {
            self::assertSame($this->projectId, $new->getProject()?->getId(), 'A new task stays in the project, whatever is posted.');
            self::assertSame($this->contractorId, $new->getAssignee()?->getId(), 'Same contractor as the original.');
            self::assertSame($this->contractorId, $new->getReviewerUserId(), 'Same reviewer as the original.');
            self::assertSame(TaskStatus::PENDING_ID, $new->getTaskStatusId());
        }
        self::assertSame('30.00', $code->getTotalAmount());
        self::assertSame(strtotime('2026-11-20'), $code->getDueDate());
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task WHERE name LIKE ?', [self::PREFIX.'%']), 'The blank row was skipped.');
    }

    public function testAnInvalidRowSavesNothing(): void
    {
        $id = $this->persistTask('Build site', '100.00');
        $this->loginUser(self::ADMIN);

        $this->postSplit($id, [
            ['name' => self::PREFIX.'Renamed', 'totalAmount' => '50.00'],
            ['name' => self::PREFIX.'Part A', 'totalAmount' => '25.00'],
            ['name' => self::PREFIX.'Part B', 'totalAmount' => 'lots'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Row 3: Payout must be a number.');
        self::assertSame('lots', $this->client->getCrawler()->filter('input[name="rows[2][totalAmount]"]')->attr('value'), 'Typed rows are kept.');
        $this->em->clear();
        self::assertSame(self::PREFIX.'Build site', $this->em->getRepository(Task::class)->find($id)->getName(), 'The task itself is untouched.');
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['name' => self::PREFIX.'Part A']));
    }

    public function testAtLeastOneNewTaskNeedsAName(): void
    {
        $id = $this->persistTask('Build site', '100.00');
        $this->loginUser(self::ADMIN);

        $this->postSplit($id, [['name' => self::PREFIX.'Build site', 'totalAmount' => '100.00'], ['name' => '']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Give at least one new task a name.');
    }

    public function testAPaidTaskCannotBeSplit(): void
    {
        $id = $this->persistTask('Paid work', '100.00', TaskStatus::PAID_ID);
        $this->loginUser(self::ADMIN);

        $crawler = $this->client->request('GET', "/task/$id");
        self::assertCount(0, $crawler->filter('a[href="/task/'.$id.'/split"]'));
        $this->client->request('GET', "/task/$id/split");
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyThoseWhoMayEditAndCreateTasksCanSplit(): void
    {
        $id = $this->persistTask('Build site', '100.00');
        $this->loginUser(self::MEMBER);

        $this->client->request('GET', "/task/$id/split");
        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<array<string, string>> $rows */
    private function postSplit(int $id, array $rows): void
    {
        $crawler = $this->client->request('GET', "/task/$id/split");
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', "/task/$id/split", ['_token' => $token, 'rows' => $rows]);
    }

    private function persistTask(string $name, string $amount, int $statusId = TaskStatus::PENDING_ID): int
    {
        $contractor = $this->em->getRepository(User::class)->find($this->contractorId);
        $project = $this->em->getRepository(Project::class)->find($this->projectId);
        $task = (new Task())->setName(self::PREFIX.$name)->setProject($project)->setClientId($project->getClient()->getId())
            ->setAssignee($contractor)->setReviewerUserId($this->contractorId)->setTaskStatusId($statusId)
            ->setTotalAmount($amount)->setCurrencyId(1)->setDescription('Original description')->setCreatedAt(time());
        $this->em->persist($task);
        $this->em->flush();
        $this->em->clear();

        return (int) $task->getId();
    }

    private function findTask(string $name): Task
    {
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['name' => self::PREFIX.$name]);
        self::assertNotNull($task, "Task \"$name\" exists.");

        return $task;
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $like = self::PREFIX.'%';
        $conn->executeStatement('DELETE FROM task WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM project WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM client WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM "user" WHERE email IN (?, ?)', [self::ADMIN, self::MEMBER]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
