<?php

declare(strict_types=1);

namespace App\Tests\Functional\Project;

use App\Entity\Client;
use App\Entity\Project;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-076: a project's local, dev, prod and doc URLs — saved from the project form, validated as URLs,
 * completed with "http://" when typed without a scheme, and shown as links on the project page.
 */
final class ProjectLinksTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'project-links-admin@example.com';
    private const CLIENT_NAME = 'Project Links Client';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $clientId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestUser(self::EMAIL, roles: ['ROLE_ADMIN']);
        $client = (new Client())->setName(self::CLIENT_NAME);
        $this->em->persist($client);
        $this->em->flush();
        $this->clientId = (int) $client->getId();
        $this->em->clear();

        $this->loginUser(self::EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testCreatingAProjectStoresItsLinksAndCompletesAMissingScheme(): void
    {
        $this->submitProjectForm('/project/new?clientId='.$this->clientId, 'Create project', [
            'name'     => 'Links project',
            'localUrl' => 'http://localhost:8000',
            'devUrl'   => 'dev.example.com',
            'prodUrl'  => 'https://example.com',
            'docUrl'   => '',
            'mockupUrl' => 'figma.com/file/abc',
        ]);

        self::assertResponseRedirects();
        $project = $this->findProject('Links project');
        self::assertSame('http://localhost:8000', $project->getLocalUrl());
        self::assertSame('http://dev.example.com', $project->getDevUrl());
        self::assertSame('https://example.com', $project->getProdUrl());
        self::assertNull($project->getDocUrl(), 'A blank link is stored as NULL, not the empty string.');
        self::assertSame('http://figma.com/file/abc', $project->getMockupUrl());
    }

    public function testAnInvalidUrlIsRefusedAndNothingIsSaved(): void
    {
        $this->submitProjectForm('/project/new?clientId='.$this->clientId, 'Create project', [
            'name'   => 'Bad links project',
            'docUrl' => 'not a url',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Doc / Specs link is not a valid URL.');
        self::assertNull($this->em->getRepository(Project::class)->findOneBy(['name' => 'Bad links project']));
    }

    public function testEditingChangesAndClearsLinks(): void
    {
        $id = $this->persistProject(['localUrl' => 'http://localhost', 'prodUrl' => 'https://old.example.com']);

        $crawler = $this->client->request('GET', "/project/$id/edit");
        self::assertSame('http://localhost', $crawler->filter('#f-localUrl')->attr('value'), 'The edit form shows the stored link.');

        $this->submitProjectForm("/project/$id/edit", 'Save', ['localUrl' => '', 'prodUrl' => 'https://new.example.com']);

        self::assertResponseRedirects("/project/$id");
        $project = $this->em->getRepository(Project::class)->find($id);
        self::assertNull($project->getLocalUrl());
        self::assertSame('https://new.example.com', $project->getProdUrl());
    }

    public function testTheProjectPageLinksOnlyHttpUrls(): void
    {
        // A non-http value can only arrive by a direct data copy; it must still never become a clickable link.
        $id = $this->persistProject(['prodUrl' => 'https://example.com', 'docUrl' => 'javascript:alert(1)']);

        $crawler = $this->client->request('GET', "/project/$id");

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="https://example.com"]'));
        self::assertCount(0, $crawler->filter('a[href^="javascript:"]'));
        self::assertSelectorTextContains('body', 'javascript:alert(1)');
    }

    /** @param array<string, string> $fields */
    private function submitProjectForm(string $url, string $button, array $fields): void
    {
        $this->client->request('GET', $url);
        $this->client->submitForm($button, $fields);
    }

    /** @param array{localUrl?: string, devUrl?: string, prodUrl?: string, docUrl?: string, mockupUrl?: string} $links */
    private function persistProject(array $links): int
    {
        $project = (new Project())
            ->setClient($this->em->getRepository(Client::class)->find($this->clientId))
            ->setName('Existing links project')
            ->setLocalUrl($links['localUrl'] ?? null)
            ->setDevUrl($links['devUrl'] ?? null)
            ->setProdUrl($links['prodUrl'] ?? null)
            ->setDocUrl($links['docUrl'] ?? null)
            ->setMockupUrl($links['mockupUrl'] ?? null);
        $this->em->persist($project);
        $this->em->flush();
        $this->em->clear();

        return (int) $project->getId();
    }

    private function findProject(string $name): Project
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['name' => $name]);
        self::assertNotNull($project, "Project \"$name\" was saved.");

        return $project;
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM project WHERE client_id IN (SELECT id FROM client WHERE name = ?)', [self::CLIENT_NAME]);
        $conn->executeStatement('DELETE FROM client WHERE name = ?', [self::CLIENT_NAME]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
