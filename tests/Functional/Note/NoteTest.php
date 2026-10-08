<?php

declare(strict_types=1);

namespace App\Tests\Functional\Note;

use App\Entity\Client;
use App\Entity\Note;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-101: notes on a client, project or task, shown on that record's page and as visible as the record; and
 * independent notes, private to their author. Only the author or an admin changes a note.
 */
final class NoteTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const ADMIN = 'notes-admin@example.com';
    private const MEMBER = 'notes-member@example.com';
    private const OTHER = 'notes-other@example.com';
    private const CLIENT_NAME = 'Notes Test Client';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $clientId;
    private int $projectId;
    private int $taskId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestUser(self::ADMIN, 'Notes Admin', roles: ['ROLE_ADMIN']);
        $this->createTestUser(self::MEMBER, 'Notes Member');
        $this->createTestUser(self::OTHER, 'Notes Other');

        $client = (new Client())->setName(self::CLIENT_NAME);
        $project = (new Project())->setClient($client)->setName('Notes project');
        $task = (new Task())->setName('Notes task')->setProject($project);
        $this->em->persist($client);
        $this->em->persist($project);
        $this->em->persist($task);
        $this->em->flush();
        [$this->clientId, $this->projectId, $this->taskId] = [(int) $client->getId(), (int) $project->getId(), (int) $task->getId()];
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testAnIndependentNoteIsSavedAndPrivateToItsAuthor(): void
    {
        $this->signIn(self::MEMBER);
        $this->client->request('GET', '/note/new');
        $this->client->submitForm('Save note', ['title' => 'My own idea', 'body' => "Line one\nLine two"]);

        $note = $this->findNote('My own idea');
        self::assertResponseRedirects('/note/'.$note->getId());
        self::assertSame(Note::TYPE_INDEPENDENT, $note->getType());
        self::assertSame("Line one\nLine two", $note->getBody());

        $this->client->request('GET', '/note');
        self::assertSelectorTextContains('table.t', 'My own idea');

        // Nobody else sees it — not another user, and not an admin either.
        foreach ([self::OTHER, self::ADMIN] as $email) {
            $this->signIn($email);
            $this->client->request('GET', '/note/'.$note->getId());
            self::assertResponseStatusCodeSame(403, "$email may not open someone else's independent note.");
            $this->client->request('GET', '/note');
            self::assertSelectorTextNotContains('body', 'My own idea');
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function subjects(): iterable
    {
        yield 'client'  => ['client', '/client/%d'];
        yield 'project' => ['project', '/project/%d'];
        yield 'task'    => ['task', '/task/%d'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('subjects')]
    public function testANoteOnARecordIsShownOnThatRecordsPage(string $type, string $pageUrl): void
    {
        $id = $this->idOf($type);
        $this->signIn(self::ADMIN);
        $this->client->request('GET', "/note/new?$type=$id");
        $this->client->submitForm('Save note', ['title' => "About the $type", 'pinned' => '1']);

        self::assertResponseRedirects(sprintf($pageUrl, $id).'#notes');
        $note = $this->findNote("About the $type");
        self::assertSame($type, $note->getType());
        self::assertTrue($note->isPinned());

        $this->client->request('GET', sprintf($pageUrl, $id));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#notes', "About the $type");
        self::assertSelectorExists(sprintf('#notes a[href="/note/new?%s=%d"]', $type, $id));
    }

    public function testTheProjectPageAlsoShowsTheNotesOnItsTasks(): void
    {
        $this->persistNote(self::ADMIN, 'Project-level note', 'project');
        $this->persistNote(self::ADMIN, 'Note on the task', 'task');
        $deletedTask = (new Task())->setName('Deleted task')->setIsDeleted(1)
            ->setProject($this->em->getRepository(Project::class)->find($this->projectId));
        $this->em->persist($deletedTask);
        $this->em->flush();
        $this->em->persist((new Note())->setTitle('Note on a deleted task')->attachTo($deletedTask)
            ->setAuthor($this->em->getRepository(User::class)->findOneBy(['email' => self::ADMIN]))
            ->setCreatedAt(time())->setUpdatedAt(time()));
        $this->em->flush();
        $this->em->clear();

        $this->signIn(self::ADMIN);
        $crawler = $this->client->request('GET', '/project/'.$this->projectId);

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#notes')->text();
        self::assertStringContainsString('Project-level note', $card);
        self::assertStringContainsString("Notes on this project's tasks", $card);
        self::assertStringContainsString('Note on the task', $card);
        self::assertCount(1, $crawler->filter(sprintf('#notes a[href="/task/%d"]', $this->taskId)), 'A task note links to its task.');
        self::assertStringNotContainsString('Note on a deleted task', $card);
    }

    public function testSomeoneWhoCannotSeeTheRecordCanNeitherReadNorWriteItsNotes(): void
    {
        $note = $this->persistNote(self::ADMIN, 'Client secret', 'client');

        $this->signIn(self::OTHER);
        $this->client->request('GET', '/note/'.$note);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/note/new?client='.$this->clientId);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/note');
        self::assertSelectorTextNotContains('body', 'Client secret');
    }

    public function testOnlyTheAuthorOrAnAdminChangesANote(): void
    {
        $note = $this->persistNote(self::MEMBER, 'Draft title', null);

        $this->signIn(self::OTHER);
        $this->client->request('GET', "/note/$note/edit");
        self::assertResponseStatusCodeSame(403);

        $this->signIn(self::MEMBER);
        $this->client->request('GET', "/note/$note/edit");
        $this->client->submitForm('Save note', ['title' => 'Final title']);
        self::assertResponseRedirects("/note/$note");
        $this->em->clear();
        self::assertSame('Final title', $this->em->getRepository(Note::class)->find($note)->getTitle());

        $adminNote = $this->persistNote(self::MEMBER, 'Member task note', 'task');
        $this->signIn(self::ADMIN);
        $this->client->request('GET', "/note/$adminNote");
        $this->client->submitForm('Delete note');
        self::assertResponseRedirects("/task/{$this->taskId}#notes");
        $this->em->clear();
        self::assertNull($this->em->getRepository(Note::class)->find($adminNote), 'An admin may delete any note on a record.');
    }

    public function testATitleIsRequiredAndNothingIsSavedWithoutOne(): void
    {
        $this->signIn(self::MEMBER);
        $this->client->request('GET', '/note/new');
        $this->client->submitForm('Save note', ['title' => '   ', 'body' => 'Text without a title']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Title is required.');
        self::assertNull($this->em->getRepository(Note::class)->findOneBy(['body' => 'Text without a title']));
    }

    public function testANoteForARecordThatDoesNotExistIsNotFound(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', '/note/new?task=999999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheListSearchesAndFiltersByTypeWithPinnedNotesFirst(): void
    {
        $this->persistNote(self::ADMIN, 'Older plain note', 'project');
        $this->persistNote(self::ADMIN, 'Pinned reminder', null, pinned: true);
        $this->persistNote(self::ADMIN, 'Client call summary', 'client');

        $this->signIn(self::ADMIN);
        $crawler = $this->client->request('GET', '/note');
        self::assertStringContainsString('Pinned reminder', $crawler->filter('table.t tbody tr')->first()->text());

        $crawler = $this->client->request('GET', '/note?type=client');
        self::assertCount(1, $crawler->filter('table.t tbody tr'));
        self::assertSelectorTextContains('table.t', 'Client call summary');

        $crawler = $this->client->request('GET', '/note?q=reminder');
        self::assertCount(1, $crawler->filter('table.t tbody tr'));
        self::assertSelectorTextContains('table.t', 'Pinned reminder');
    }

    private function signIn(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->loginUser($email);
    }

    private function idOf(string $type): int
    {
        return match ($type) {
            'client'  => $this->clientId,
            'project' => $this->projectId,
            default   => $this->taskId,
        };
    }

    private function persistNote(string $authorEmail, string $title, ?string $type, bool $pinned = false): int
    {
        $subject = match ($type) {
            'client'  => $this->em->getRepository(Client::class)->find($this->clientId),
            'project' => $this->em->getRepository(Project::class)->find($this->projectId),
            'task'    => $this->em->getRepository(Task::class)->find($this->taskId),
            default   => null,
        };
        $note = (new Note())
            ->setTitle($title)
            ->setPinned($pinned)
            ->attachTo($subject)
            ->setAuthor($this->em->getRepository(User::class)->findOneBy(['email' => $authorEmail]))
            ->setCreatedAt(time())
            ->setUpdatedAt(time());
        $this->em->persist($note);
        $this->em->flush();
        $this->em->clear();

        return (int) $note->getId();
    }

    private function findNote(string $title): Note
    {
        $note = $this->em->getRepository(Note::class)->findOneBy(['title' => $title]);
        self::assertNotNull($note, "Note \"$title\" was saved.");

        return $note;
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $users = 'SELECT id FROM "user" WHERE email IN (?, ?, ?)';
        $emails = [self::ADMIN, self::MEMBER, self::OTHER];
        $conn->executeStatement("DELETE FROM note WHERE author_id IN ($users)", $emails);
        $projects = 'SELECT id FROM project WHERE client_id IN (SELECT id FROM client WHERE name = ?)';
        $conn->executeStatement("DELETE FROM task WHERE project_id IN ($projects)", [self::CLIENT_NAME]);
        $conn->executeStatement("DELETE FROM project_staff WHERE project_id IN ($projects)", [self::CLIENT_NAME]);
        $conn->executeStatement('DELETE FROM project WHERE client_id IN (SELECT id FROM client WHERE name = ?)', [self::CLIENT_NAME]);
        $conn->executeStatement('DELETE FROM client WHERE name = ?', [self::CLIENT_NAME]);
        $conn->executeStatement('DELETE FROM "user" WHERE email IN (?, ?, ?)', $emails);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }
}
