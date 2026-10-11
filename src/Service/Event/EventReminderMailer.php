<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event\Event;
use App\Entity\User;
use App\Enum\Role;
use App\Repository\Event\EventRepository;
use App\Repository\UserRepository;
use App\Security\Work\WorkAccess;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Event reminder emails (ADR-126), sent by `app:events:remind` from a daily scheduled task: one email to each
 * active admin listing every event whose reminder is due, then each event remembers the occurrence it was sent for
 * so a second run the same day (or the next) sends nothing more.
 */
final class EventReminderMailer
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly UserRepository $users,
        private readonly WorkAccess $access,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<array{event: Event, date: \DateTimeImmutable, years: ?int, daysAway: int}> what is due today */
    public function due(\DateTimeImmutable $today): array
    {
        $rows = [];
        foreach ($this->events->findWithReminders() as $event) {
            $date = EventCalendar::reminderDueFor($event, $today);
            if ($date !== null) {
                $rows[] = ['event' => $event, 'date' => $date, 'years' => EventCalendar::yearsAt($event, $date), 'daysAway' => EventCalendar::daysBetween($today, $date)];
            }
        }
        usort($rows, static fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $rows;
    }

    /** @return list<string> the admin addresses reminders go to — not the hidden maintainer accounts (ADR-050) */
    public function recipients(): array
    {
        return array_values(array_map(static fn (User $u) => $u->getEmail(), array_filter(
            $this->users->findActiveOrderedByName(),
            fn (User $u) => $this->access->isAdmin($u) && !in_array(Role::TechSupport->value, $u->getRoles(), true),
        )));
    }

    /**
     * Sends today's reminders. @return array{events: int, sent: int, failed: list<string>}
     *
     * @param list<array{event: Event, date: \DateTimeImmutable, years: ?int, daysAway: int}> $due
     */
    public function send(array $due): array
    {
        if ($due === []) {
            return ['events' => 0, 'sent' => 0, 'failed' => []];
        }
        $subject = count($due) === 1
            ? sprintf('Reminder: %s — %s', $due[0]['event']->getTitle(), self::when($due[0]))
            : sprintf('Reminder: %d upcoming events', count($due));
        $body = $this->body($due);
        $sent = 0;
        $failed = [];
        foreach ($this->recipients() as $address) {
            try {
                $this->mailer->send((new Email())->to($address)->subject($subject)->text($body));
                ++$sent;
            } catch (TransportExceptionInterface $e) {
                $failed[] = $address;
                $this->logger->error('Event reminder email failed', ['to' => $address, 'error' => $e->getMessage()]);
            }
        }
        if ($sent > 0) {
            foreach ($due as $row) {
                $row['event']->setLastRemindedFor($row['date']);
            }
            $this->em->flush();
        }

        return ['events' => count($due), 'sent' => $sent, 'failed' => $failed];
    }

    /** @param array{event: Event, date: \DateTimeImmutable, years: ?int, daysAway: int} $row */
    public static function when(array $row): string
    {
        return match ($row['daysAway']) {
            0       => 'today',
            1       => 'tomorrow',
            default => sprintf('in %d days (%s)', $row['daysAway'], $row['date']->format('l, j M')),
        };
    }

    /** @param list<array{event: Event, date: \DateTimeImmutable, years: ?int, daysAway: int}> $due */
    private function body(array $due): string
    {
        $lines = ['Coming up:', ''];
        foreach ($due as $row) {
            $event = $row['event'];
            $lines[] = sprintf('• %s — %s%s', $event->getTitle(), self::when($row), $row['years'] !== null ? sprintf(' · %d years', $row['years']) : '');
            $details = array_filter([
                $event->getCategory()?->getName(),
                $event->getEventTime()?->format('H:i'),
                $event->getPlace(),
                $event->getPeople(),
            ]);
            if ($details !== []) {
                $lines[] = '  '.implode(' · ', $details);
            }
            $lines[] = '  '.$this->urls->generate('app_event_show', ['id' => $event->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            $lines[] = '';
        }
        $lines[] = 'You get this because the events ask for a reminder. Change it on each event\'s Edit page.';

        return implode("\n", $lines);
    }
}
