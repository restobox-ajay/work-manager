<?php

declare(strict_types=1);

namespace App\Tests\Functional\Work;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\Settings\TaskStatus;
use App\Entity\Settings\TaskType;
use App\Entity\Task;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * ADR-136: filters in a row under the column labels — Projects and Clients (name, status; Projects also client;
 * no filter bar above the grid), and the dashboard's task grid (client, project, task name, task type, status) with
 * its new Task Type column.
 */
final class GridColumnFiltersTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'grid-filters-admin@example.com';
    private const PREFIX = 'GridFilter ';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $clientId;
    private int $projectId;
    private int $typeId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();

        $this->createTestUser(self::EMAIL, roles: ['ROLE_ADMIN']);

        $live = (new Client())->setName(self::PREFIX.'Alpha Client');
        $archived = (new Client())->setName(self::PREFIX.'Beta Client')->setIsActive(Client::STATUS_ARCHIVE);
        $project = (new Project())->setClient($live)->setName(self::PREFIX.'Apple Site');
        $other = (new Project())->setClient($live)->setName(self::PREFIX.'Banana App')->setStatus(Project::STATUS_ARCHIVE);
        $type = (new TaskType())->setName(self::PREFIX.'Design')->setStatus(true);
        foreach ([$live, $archived, $project, $other, $type] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->clientId = (int) $live->getId();
        $this->projectId = (int) $project->getId();
        $this->typeId = (int) $type->getId();

        foreach ([['Logo task', $this->typeId], ['Copy task', null]] as [$name, $typeId]) {
            $this->em->persist((new Task())->setName(self::PREFIX.$name)->setProject($project)
                ->setClientId($this->clientId)->setTaskTypeId($typeId)->setTaskStatusId(TaskStatus::PENDING_ID)->setCreatedAt(time()));
        }
        $this->em->flush();
        $this->em->clear();

        $this->loginUser(self::EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testProjectGridFiltersByNameStatusAndClientFromTheColumnRow(): void
    {
        $crawler = $this->client->request('GET', '/project');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.table-header'), 'No filter bar above the grid.');
        $row = $crawler->filter('tr.col-filters');
        self::assertCount(1, $row->filter('input[name="name"][form="project-filters"]'));
        self::assertCount(1, $row->filter('select[name="status"][data-autosubmit]'));
        self::assertCount(1, $row->filter('select[name="clientId"][data-autosubmit]'));

        $names = $this->rowNames($this->client->request('GET', '/project?name=Apple'), 2);
        self::assertContains(self::PREFIX.'Apple Site', $names);
        self::assertNotContains(self::PREFIX.'Banana App', $names);

        $names = $this->rowNames($this->client->request('GET', '/project?clientId='.$this->clientId.'&status=archived'), 2);
        self::assertSame([self::PREFIX.'Banana App'], $names);
    }

    public function testClientGridFiltersByNameAndStatusFromTheColumnRow(): void
    {
        $crawler = $this->client->request('GET', '/client');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.table-header'), 'No filter bar above the grid.');
        self::assertCount(1, $crawler->filter('tr.col-filters input[name="name"][form="client-filters"]'));
        self::assertCount(1, $crawler->filter('tr.col-filters select[name="status"][data-autosubmit]'));

        $names = $this->rowNames($this->client->request('GET', '/client?name='.urlencode(self::PREFIX)), 1);
        self::assertSame([self::PREFIX.'Alpha Client', self::PREFIX.'Beta Client'], $names);

        $names = $this->rowNames($this->client->request('GET', '/client?name='.urlencode(self::PREFIX).'&status=archived'), 1);
        self::assertSame([self::PREFIX.'Beta Client'], $names);
    }

    public function testDashboardGridHasATaskTypeColumnAndColumnFilters(): void
    {
        $crawler = $this->client->request('GET', '/dashboard?projectId='.$this->projectId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table.t thead', 'Task Type');
        $row = $crawler->filter('tr.col-filters');
        foreach (['clientId', 'projectId', 'taskTypeId', 'taskStatusId'] as $name) {
            self::assertCount(1, $row->filter(sprintf('select[name="%s"][form="dashboard-filters"]', $name)), $name);
        }
        self::assertCount(1, $row->filter('input[name="q"][form="dashboard-filters"]'));
        self::assertStringContainsString(self::PREFIX.'Design', $crawler->filter('table.t tbody')->text(), 'The type is shown in its column.');

        $text = $this->client->request('GET', '/dashboard?projectId='.$this->projectId.'&taskTypeId='.$this->typeId)->filter('table.t tbody')->text();
        self::assertStringContainsString('Logo task', $text);
        self::assertStringNotContainsString('Copy task', $text);

        $text = $this->client->request('GET', '/dashboard?clientId='.$this->clientId.'&q=Copy&taskStatusId=all')->filter('table.t tbody')->text();
        self::assertStringContainsString('Copy task', $text);
        self::assertStringNotContainsString('Logo task', $text);
    }

    /** @return list<string> the grid rows' text in the given (1-based) column, this test's rows only */
    private function rowNames(Crawler $crawler, int $column): array
    {
        $names = $crawler->filter(sprintf('table.t tbody tr td:nth-child(%d) a', $column))->each(static fn (Crawler $a) => trim($a->text()));

        return array_values(array_filter($names, static fn (string $name) => str_starts_with($name, self::PREFIX)));
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $like = self::PREFIX.'%';
        $conn->executeStatement('DELETE FROM task WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM project WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM client WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM task_type WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
