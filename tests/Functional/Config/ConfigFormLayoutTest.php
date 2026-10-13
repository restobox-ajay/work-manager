<?php

declare(strict_types=1);

namespace App\Tests\Functional\Config;

use App\Entity\Settings\TaskStatus;
use App\Service\Config\ConfigListRegistry;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-133/134: every Config form stacks its fields one per line; a list's order field (Order / Sort Order) is not on
 * the form — it is set only by dragging the list — and a new row goes to the end of the list.
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

    public function testTheTaskStatusFormStacksNameAndActiveWithoutOrder(): void
    {
        $crawler = $this->client->request('GET', '/config/task-statuses/new');
        self::assertResponseIsSuccessful();

        $grid = $crawler->filter('form.mx-form .form-grid.cf-stack');
        self::assertCount(1, $grid, 'The fields are stacked one per line.');
        self::assertSame(['name', 'isActive'], $grid->filter('input')->each(static fn ($input) => $input->attr('name')));
        self::assertNotNull($crawler->filter('#cf-name')->attr('autofocus'), 'Name, now first, takes the focus.');
    }

    public function testANewStatusGoesToTheEndOfTheList(): void
    {
        $lastOrder = (int) $this->em->getConnection()->fetchOne('SELECT MAX("order") FROM task_status');

        $this->client->request('GET', '/config/task-statuses/new');
        $this->client->submitForm('Add', ['name' => self::STATUS_NAME]);

        self::assertResponseRedirects('/config/task-statuses');
        $status = $this->em->getRepository(TaskStatus::class)->findOneBy(['name' => self::STATUS_NAME]);
        self::assertNotNull($status);
        self::assertSame($lastOrder + 10, $status->getOrder());
    }

    public function testSavingAnExistingStatusKeepsItsOrder(): void
    {
        $status = (new TaskStatus())->setName(self::STATUS_NAME)->setOrder(35)->setIsActive(true);
        $this->em->persist($status);
        $this->em->flush();
        $id = $status->getId();
        $this->em->clear();

        $form = $this->client->request('GET', "/config/task-statuses/$id/edit")->selectButton('Save')->form();
        // A hand-crafted post: the form itself has no Order field.
        $this->client->request('POST', $form->getUri(), $form->getValues() + ['order' => '1']);
        self::assertResponseRedirects('/config/task-statuses');

        $this->em->clear();
        self::assertSame(35, $this->em->getRepository(TaskStatus::class)->find($id)->getOrder(), 'A posted order is ignored.');
    }

    public function testEveryConfigFormIsStackedWithoutAnOrderField(): void
    {
        foreach (explode('|', ConfigListRegistry::KINDS) as $kind) {
            $crawler = $this->client->request('GET', "/config/$kind/new");
            self::assertResponseIsSuccessful($kind);
            self::assertCount(1, $crawler->filter('form.mx-form .form-grid.cf-stack'), "$kind: fields one per line.");
            self::assertCount(0, $crawler->filter('form.mx-form .span-2'), "$kind: no field spans columns.");
            self::assertCount(0, $crawler->filter('[name="order"], [name="sortOrder"]'), "$kind: no order field.");
        }
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM task_status WHERE name = ?', [self::STATUS_NAME]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
