<?php

declare(strict_types=1);

namespace App\Tests\Functional\Project;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\ProjectLink;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-076 / ADR-129: a project's links — any number of named URLs with a description, saved from the project form,
 * validated as URLs, completed with "http://" when typed without a scheme, and shown on the project page with the
 * name opening the URL (only http(s) is ever clickable).
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

    public function testCreatingAProjectStoresItsLinksInOrderAndCompletesAMissingScheme(): void
    {
        $this->client->request('GET', '/project/new?clientId='.$this->clientId);
        $this->client->request('POST', '/project/new?clientId='.$this->clientId, $this->form('Links project', [
            ['name' => 'Local', 'url' => 'http://localhost:8000', 'description' => 'php -S'],
            ['name' => '', 'url' => '', 'description' => ''],
            ['name' => 'Staging', 'url' => 'staging.example.com', 'description' => ''],
            ['name' => '', 'url' => 'https://figma.com/file/abc', 'description' => 'Mockups'],
        ]));

        self::assertResponseRedirects();
        $links = $this->linksOf('Links project');
        self::assertSame([
            ['Local', 'http://localhost:8000', 'php -S'],
            ['Staging', 'http://staging.example.com', null],
            ['Link', 'https://figma.com/file/abc', 'Mockups'],
        ], $links, 'Blank rows are skipped, a missing scheme is added, a blank name becomes "Link".');
    }

    public function testAnInvalidOrMissingUrlIsRefusedAndNothingIsSaved(): void
    {
        $this->client->request('GET', '/project/new?clientId='.$this->clientId);
        $this->client->request('POST', '/project/new?clientId='.$this->clientId, $this->form('Bad links project', [
            ['name' => 'Docs', 'url' => 'not a url', 'description' => ''],
            ['name' => 'Prod', 'url' => '', 'description' => 'forgot the url'],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Link 1 ("Docs") is not a valid URL.');
        self::assertSelectorTextContains('body', 'Link 2 ("Prod"): enter its URL.');
        self::assertNull($this->em->getRepository(Project::class)->findOneBy(['name' => 'Bad links project']));
    }

    /** ADR-130: a URL must be an http(s) web address whose host is localhost, an IP address or a dotted domain. */
    #[DataProvider('urls')]
    public function testOnlyRealWebAddressesAreAccepted(string $typed, ?string $stored): void
    {
        $this->client->request('GET', '/project/new?clientId='.$this->clientId);
        $this->client->request('POST', '/project/new?clientId='.$this->clientId, $this->form('Url rule project', [
            ['name' => 'Dev', 'url' => $typed, 'description' => ''],
        ]));

        if ($stored === null) {
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Link 1 ("Dev") is not a valid URL. Enter a web address such as https://example.com');
            self::assertNull($this->em->getRepository(Project::class)->findOneBy(['name' => 'Url rule project']));

            return;
        }
        self::assertResponseRedirects();
        self::assertSame([['Dev', $stored, null]], $this->linksOf('Url rule project'));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function urls(): iterable
    {
        yield 'https domain with path and query' => ['https://example.com/a/b?q=1#top', 'https://example.com/a/b?q=1#top'];
        yield 'domain without scheme'            => ['dev.myapp.co.in', 'http://dev.myapp.co.in'];
        yield 'localhost with port'              => ['localhost:8000/app', 'http://localhost:8000/app'];
        yield 'LAN IP address'                   => ['192.168.1.20:8080', 'http://192.168.1.20:8080'];
        yield 'local .test domain'               => ['http://shop.test', 'http://shop.test'];
        yield 'a single word'                    => ['abc', null];
        yield 'words with spaces'                => ['my dev site', null];
        yield 'space inside the address'         => ['https://exa mple.com', null];
        yield 'ftp address'                      => ['ftp://files.example.com', null];
        yield 'javascript: URL'                  => ['javascript:alert(1)', null];
        yield 'scheme with no host'              => ['https://', null];
        yield 'numeric last label'               => ['http://example.123', null];
        yield 'domain ending in a dot'           => ['example.', null];
    }

    public function testEditingReplacesTheLinks(): void
    {
        $id = $this->persistProject([['Local', 'http://localhost'], ['Prod', 'https://old.example.com']]);

        $crawler = $this->client->request('GET', "/project/$id/edit");
        self::assertSame('http://localhost', $crawler->filter('input[name="links[0][url]"]')->attr('value'), 'The edit form shows the stored links.');

        $this->client->request('POST', "/project/$id/edit", $this->form('Existing links project', [
            ['name' => 'Prod', 'url' => 'https://new.example.com', 'description' => 'Live'],
        ], $crawler->filter('input[name="_token"]')->attr('value')));

        self::assertResponseRedirects("/project/$id");
        self::assertSame([['Prod', 'https://new.example.com', 'Live']], $this->linksOf('Existing links project'));
    }

    public function testTheProjectPageLinksTheNameToTheUrlAndOnlyHttpUrls(): void
    {
        // A non-http value can only arrive by a direct data copy; it must still never become a clickable link.
        $id = $this->persistProject([['Prod', 'https://example.com'], ['Old doc', 'javascript:alert(1)']]);

        $crawler = $this->client->request('GET', "/project/$id");

        self::assertResponseIsSuccessful();
        $prodLinks = $crawler->filter('#links a[href="https://example.com"]');
        self::assertCount(2, $prodLinks, 'The name and the URL both open it.');
        self::assertSame('Prod', $prodLinks->first()->text());
        self::assertCount(0, $crawler->filter('a[href^="javascript:"]'));
        self::assertSelectorTextContains('#links', 'javascript:alert(1)');
    }

    /** ADR-130: the name is a coloured label — fixed colours for the usual environments, a stable one for any other name. */
    public function testTheNameIsShownAsAColouredLabel(): void
    {
        $id = $this->persistProject([['Prod', 'https://example.com'], ['Dev', 'https://dev.example.com'], ['Figma', 'https://figma.com/x'], ['Old doc', 'javascript:alert(1)']]);

        $crawler = $this->client->request('GET', "/project/$id");

        self::assertSame('Prod', $crawler->filter('#links a.pl-label.pl-tone-3')->text());
        self::assertSame('Dev', $crawler->filter('#links a.pl-label.pl-tone-1')->text());
        self::assertSame('Figma', $crawler->filter('#links a.pl-label.pl-tone-5')->text());
        self::assertSame('Old doc', $crawler->filter('#links span.pl-label')->text(), 'A link that cannot be clicked still gets its label.');
    }

    /**
     * @param list<array{name: string, url: string, description: string}> $links
     *
     * @return array<string, mixed>
     */
    private function form(string $name, array $links, ?string $token = null): array
    {
        $token ??= $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');

        return ['_token' => $token, 'clientId' => (string) $this->clientId, 'name' => $name, 'status' => '1', 'links' => $links];
    }

    /** @param list<array{0: string, 1: string}> $links */
    private function persistProject(array $links): int
    {
        $project = (new Project())
            ->setClient($this->em->getRepository(Client::class)->find($this->clientId))
            ->setName('Existing links project')
            ->replaceLinks(array_map(static fn (array $l) => (new ProjectLink())->setName($l[0])->setUrl($l[1]), $links));
        $this->em->persist($project);
        $this->em->flush();
        $this->em->clear();

        return (int) $project->getId();
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    private function linksOf(string $projectName): array
    {
        $this->em->clear();
        $project = $this->findProject($projectName);

        return array_values(array_map(static fn (ProjectLink $l) => [$l->getName(), $l->getUrl(), $l->getDescription()], $project->getLinks()->toArray()));
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
