<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Entity\Event\Event;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-126: events — saving one, the upcoming list rolling yearly events forward with their years, the categories
 * seeded by the migration, and the reminder command emailing once per occurrence.
 */
final class EventModuleTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const ADMIN = 'event-admin@example.com';
    private const TITLE = 'EventTest ';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
        $this->createTestUser(self::ADMIN, roles: ['ROLE_ADMIN']);
        $this->loginUser(self::ADMIN);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testTheMigrationSeedsTheCategories(): void
    {
        $page = $this->client->request('GET', '/event/new');

        self::assertResponseIsSuccessful();
        $names = $page->filter('#f-category option')->each(static fn ($o) => $o->text());
        foreach (['Birth', 'Birthday', 'Death', 'Anniversary', 'Other'] as $name) {
            self::assertContains($name, $names);
        }
    }

    public function testAYearlyEventShowsOnUpcomingWithItsYears(): void
    {
        $date = (new \DateTimeImmutable('today'))->modify('+5 days')->modify('-40 years');
        $this->create('Dad birthday', $date->format('Y-m-d'), ['repeatsYearly' => '1', 'people' => 'Dad']);

        $page = $this->client->request('GET', '/event');
        self::assertResponseIsSuccessful();
        $row = $page->filter('tbody tr')->reduce(static fn ($tr) => str_contains($tr->text(), self::TITLE.'Dad birthday'));
        self::assertCount(1, $row);
        self::assertStringContainsString('40 years', $row->text());
        self::assertStringContainsString('in 5 days', strtolower($row->text()));
    }

    public function testAPastOneOffEventIsNotUpcomingButIsListed(): void
    {
        $this->create('Old record', '2001-11-02');

        self::assertStringNotContainsString(self::TITLE.'Old record', $this->client->request('GET', '/event?days=365')->text());
        self::assertStringContainsString(self::TITLE.'Old record', $this->client->request('GET', '/event/all?year=2001')->text());
    }

    public function testBadDatesAreRefused(): void
    {
        $this->client->request('GET', '/event/new');
        $this->client->submitForm('Add event', ['title' => self::TITLE.'Bad', 'eventDate' => '2026-10-10', 'endDate' => '2026-10-01']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'The end date cannot be before the date.');
    }

    public function testTheReminderIsEmailedOncePerOccurrence(): void
    {
        $this->create('Anniversary', '2010-12-20', ['repeatsYearly' => '1', 'remindDaysBefore' => '3']);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:events:remind'));

        $tester->execute(['--date' => '2026-12-16']);
        self::assertStringContainsString('No event reminders due', $tester->getDisplay(), 'Four days before is too early.');

        $tester->execute(['--date' => '2026-12-17']);
        self::assertStringContainsString(self::TITLE.'Anniversary', $tester->getDisplay());
        self::assertStringContainsString('Emailed 1 reminder', $tester->getDisplay());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertStringContainsString(self::TITLE.'Anniversary', (string) $email?->getSubject());
        self::assertEmailTextBodyContains($email, '16 years');

        $tester->execute(['--date' => '2026-12-18']);
        self::assertStringContainsString('No event reminders due', $tester->getDisplay(), 'Not sent twice.');
        $this->em->clear();
        self::assertSame('2026-12-20', $this->em->getRepository(Event::class)->findOneBy(['title' => self::TITLE.'Anniversary'])?->getLastRemindedFor()?->format('Y-m-d'));
    }

    /** @param array<string, string> $fields */
    private function create(string $title, string $date, array $fields = []): void
    {
        $page = $this->client->request('GET', '/event/new');
        $form = $page->selectButton('Add event')->form(['title' => self::TITLE.$title, 'eventDate' => $date] + array_diff_key($fields, ['repeatsYearly' => 1]));
        if (isset($fields['repeatsYearly'])) {
            $form['repeatsYearly']->tick();
        }
        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM life_event WHERE title LIKE ?', [self::TITLE.'%']);
        $conn->executeStatement('DELETE FROM user WHERE email = ?', [self::ADMIN]);
    }
}
