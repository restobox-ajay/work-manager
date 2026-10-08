<?php

declare(strict_types=1);

namespace App\Tests\Functional\Task;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-100: contractor, reviewer, billable time and billable date are off every task page. The columns stay (all
 * nullable): a task saved from a form keeps whatever values it already had.
 */
final class TaskHiddenFieldsTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'task-hidden-fields-admin@example.com';
    private const CLIENT_NAME = 'Task Hidden Fields Client';
    private const HIDDEN_LABELS = ['Contractor', 'Reviewer', 'Billable'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $projectId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestUser(self::EMAIL, roles: ['ROLE_ADMIN']);
        $client = (new Client())->setName(self::CLIENT_NAME);
        $project = (new Project())->setClient($client)->setName('Hidden fields project');
        $this->em->persist($client);
        $this->em->persist($project);
        $this->em->flush();
        $this->projectId = (int) $project->getId();
        $this->em->clear();

        $this->loginUser(self::EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testTheTaskFormHasNoContractorReviewerOrBillableFields(): void
    {
        $crawler = $this->client->request('GET', '/task/new');

        self::assertResponseIsSuccessful();
        foreach (['assigneeId', 'reviewerUserId', 'timeBudget', 'billableDate'] as $field) {
            self::assertCount(0, $crawler->filter("[name=\"$field\"]"), "The task form has no $field field.");
        }
    }

    public function testCreatingATaskLeavesTheHiddenFieldsEmpty(): void
    {
        $this->client->request('GET', '/task/new');
        $this->client->submitForm('Create task', ['name' => 'Plain task', 'projectId' => (string) $this->projectId]);

        self::assertResponseRedirects();
        $task = $this->em->getRepository(Task::class)->findOneBy(['name' => 'Plain task']);
        self::assertNotNull($task);
        // ADR-114 (owner's one-person setup): with no assignee field, a new task is its creator's.
        self::assertSame(self::EMAIL, $task->getAssignee()?->getEmail());
        self::assertNull($task->getReviewerUserId());
        self::assertNull($task->getTimeBudget());
        self::assertNull($task->getBillableDate());
    }

    public function testEditingATaskKeepsItsExistingHiddenValues(): void
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        $task = (new Task())
            ->setName('Legacy task')
            ->setProject($this->em->getRepository(Project::class)->find($this->projectId))
            ->setAssignee($admin)
            ->setReviewerUserId($admin->getId())
            ->setTimeBudget(90)
            ->setBillableDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->persist($task);
        $this->em->flush();
        $id = (int) $task->getId();
        $this->em->clear();

        $this->client->request('GET', "/task/$id/edit");
        $this->client->submitForm('Save', ['name' => 'Legacy task, renamed']);

        self::assertResponseRedirects("/task/$id");
        $task = $this->em->getRepository(Task::class)->find($id);
        self::assertSame('Legacy task, renamed', $task->getName());
        self::assertSame($admin->getId(), $task->getAssignee()?->getId());
        self::assertSame($admin->getId(), $task->getReviewerUserId());
        self::assertSame(90, $task->getTimeBudget());
        self::assertSame('2026-09-01', $task->getBillableDate()?->format('Y-m-d'));

        $page = $this->client->request('GET', "/task/$id")->filter('.page-body')->text();
        foreach (self::HIDDEN_LABELS as $label) {
            self::assertStringNotContainsString($label, $page, "The task page does not show \"$label\".");
        }
    }

    public function testTheTaskListAndByDateReportShowNoHiddenColumns(): void
    {
        foreach (['/task', '/task/by-date'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $headings = implode(' ', $crawler->filter('th, label')->each(static fn ($node) => $node->text()));
            foreach (self::HIDDEN_LABELS as $label) {
                self::assertStringNotContainsString($label, $headings, "$url shows no \"$label\" column or filter.");
            }
        }
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $projects = 'SELECT id FROM project WHERE client_id IN (SELECT id FROM client WHERE name = ?)';
        $conn->executeStatement("DELETE FROM task WHERE project_id IN ($projects)", [self::CLIENT_NAME]);
        $conn->executeStatement("DELETE FROM project_staff WHERE project_id IN ($projects)", [self::CLIENT_NAME]);
        $conn->executeStatement('DELETE FROM project WHERE client_id IN (SELECT id FROM client WHERE name = ?)', [self::CLIENT_NAME]);
        $conn->executeStatement('DELETE FROM client WHERE name = ?', [self::CLIENT_NAME]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
