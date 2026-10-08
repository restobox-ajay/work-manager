<?php

declare(strict_types=1);

namespace App\Tests\Functional\Task;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\Task;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-099: a task's optional Doc / Specs link — saved from the task form with the project links' rules (ADR-076):
 * blank → NULL, a missing scheme completed with "http://", an invalid URL refused, only http(s) shown as a link.
 */
final class TaskDocUrlTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'task-doc-url-admin@example.com';
    private const CLIENT_NAME = 'Task Doc Url Client';

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
        $project = (new Project())->setClient($client)->setName('Task doc url project');
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

    public function testCreatingATaskStoresItsDocLinkAndCompletesAMissingScheme(): void
    {
        $this->submitTaskForm('/task/new', 'Create task', ['name' => 'Spec task', 'docUrl' => 'docs.example.com/spec']);

        self::assertResponseRedirects();
        self::assertSame('http://docs.example.com/spec', $this->findTask('Spec task')->getDocUrl());
    }

    public function testTheDocLinkIsOptional(): void
    {
        $this->submitTaskForm('/task/new', 'Create task', ['name' => 'No spec task', 'docUrl' => '']);

        self::assertResponseRedirects();
        self::assertNull($this->findTask('No spec task')->getDocUrl(), 'A blank link is stored as NULL, not the empty string.');
    }

    public function testAnInvalidDocLinkIsRefusedAndNothingIsSaved(): void
    {
        $this->submitTaskForm('/task/new', 'Create task', ['name' => 'Bad spec task', 'docUrl' => 'not a url']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Doc / Specs link is not a valid URL.');
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['name' => 'Bad spec task']));
    }

    public function testEditingChangesAndClearsTheDocLink(): void
    {
        $id = $this->persistTask('https://old.example.com/spec');

        $crawler = $this->client->request('GET', "/task/$id/edit");
        self::assertSame('https://old.example.com/spec', $crawler->filter('#f-docUrl')->attr('value'), 'The edit form shows the stored link.');

        $this->client->submitForm('Save', ['docUrl' => 'https://new.example.com/spec']);
        self::assertResponseRedirects("/task/$id");
        $this->em->clear();
        self::assertSame('https://new.example.com/spec', $this->em->getRepository(Task::class)->find($id)->getDocUrl());

        $this->submitTaskForm("/task/$id/edit", 'Save', ['docUrl' => '']);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Task::class)->find($id)->getDocUrl());
    }

    public function testTheTaskPageLinksOnlyAnHttpDocUrl(): void
    {
        $linked = $this->persistTask('https://example.com/spec');
        $crawler = $this->client->request('GET', "/task/$linked");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="https://example.com/spec"]'));

        // A non-http value can only arrive by a direct data copy; it must still never become a clickable link.
        $unsafe = $this->persistTask('javascript:alert(1)');
        $crawler = $this->client->request('GET', "/task/$unsafe");
        self::assertCount(0, $crawler->filter('a[href^="javascript:"]'));
        self::assertSelectorTextContains('body', 'javascript:alert(1)');
    }

    /** @param array<string, string> $fields */
    private function submitTaskForm(string $url, string $button, array $fields): void
    {
        $this->client->request('GET', $url);
        $this->client->submitForm($button, $fields + ['projectId' => (string) $this->projectId]);
    }

    private function persistTask(?string $docUrl): int
    {
        $task = (new Task())
            ->setName('Existing spec task')
            ->setProject($this->em->getRepository(Project::class)->find($this->projectId))
            ->setDocUrl($docUrl);
        $this->em->persist($task);
        $this->em->flush();
        $this->em->clear();

        return (int) $task->getId();
    }

    private function findTask(string $name): Task
    {
        $task = $this->em->getRepository(Task::class)->findOneBy(['name' => $name]);
        self::assertNotNull($task, "Task \"$name\" was saved.");

        return $task;
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
