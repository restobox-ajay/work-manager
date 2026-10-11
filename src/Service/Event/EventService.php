<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Attachment;
use App\Entity\Event\Event;
use App\Entity\User;
use App\Repository\Event\EventCategoryRepository;
use App\Repository\Event\EventRepository;
use App\Service\File\AttachmentStorage;
use App\Service\Text\RichText;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Events (ADR-126): saving and checking one, removing it with its files, and the upcoming list (yearly events rolled
 * forward to their next date, with the years they mark).
 */
final class EventService
{
    /** Upcoming windows offered, in days; the first is the default and the dashboard's. */
    public const WINDOWS = [30, 60, 90, 365];
    public const REMIND_CHOICES = [0 => 'On the day', 1 => '1 day before', 3 => '3 days before', 7 => '1 week before', 14 => '2 weeks before', 30 => '1 month before'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $events,
        private readonly EventCategoryRepository $categories,
        private readonly AttachmentStorage $files,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    /** @return array<string, mixed> a new event's defaults */
    public function defaults(): array
    {
        return ['eventDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'remindDaysBefore' => ''];
    }

    /** @return array<string, mixed> */
    public function valuesFrom(Event $e): array
    {
        return [
            'title' => $e->getTitle(), 'categoryId' => $e->getCategory()?->getId(), 'eventDate' => $e->getEventDate()->format('Y-m-d'),
            'eventTime' => $e->getEventTime()?->format('H:i'), 'endDate' => $e->getEndDate()?->format('Y-m-d'),
            'people' => $e->getPeople(), 'place' => $e->getPlace(), 'description' => $e->getDescription(),
            'repeatsYearly' => $e->isRepeatsYearly(), 'remindDaysBefore' => $e->getRemindDaysBefore() ?? '',
        ];
    }

    /** @param array<string, mixed> $posted @return WriteResult<Event> */
    public function save(?Event $event, array $posted, User $actor): WriteResult
    {
        $errors = [];
        $title = InputValue::text($posted['title'] ?? null) ?? '';
        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = 'Enter a title (at most 200 characters).';
        }
        $category = null;
        if (($categoryId = InputValue::int($posted['categoryId'] ?? null)) !== null && ($category = $this->categories->find($categoryId)) === null) {
            $errors[] = 'Choose a valid category, or none.';
        }
        [$date, $dateOk] = InputValue::ymd($posted['eventDate'] ?? null);
        if (!$dateOk || $date === null) {
            $errors[] = 'Enter the date it happened or will happen.';
        }
        [$endDate, $endOk] = InputValue::ymd($posted['endDate'] ?? null);
        if (!$endOk) {
            $errors[] = 'The end date must be a valid date.';
        } elseif ($date !== null && $endDate !== null && $endDate < $date) {
            $errors[] = 'The end date cannot be before the date.';
        }
        $time = null;
        if (($timeText = InputValue::text($posted['eventTime'] ?? null)) !== null) {
            $time = \DateTimeImmutable::createFromFormat('!H:i', $timeText) ?: null;
            if ($time === null || $time->format('H:i') !== $timeText) {
                $errors[] = 'The time must look like 14:30.';
                $time = null;
            }
        }
        $texts = [];
        foreach (['people' => 'People', 'place' => 'Place'] as $field => $label) {
            $texts[$field] = InputValue::text($posted[$field] ?? null);
            if ($texts[$field] !== null && mb_strlen($texts[$field]) > 255) {
                $errors[] = sprintf('%s cannot be longer than 255 characters.', $label);
            }
        }
        $remind = InputValue::text($posted['remindDaysBefore'] ?? null);
        if ($remind !== null && !isset(self::REMIND_CHOICES[(int) $remind])) {
            $errors[] = 'Choose when to be reminded, or no reminder.';
        }
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $isNew = $event === null;
        $event ??= (new Event())->setCreatedAt(time())->setCreatedBy($actor->getId());
        $dateChanged = !$isNew && $event->getEventDate()->format('Y-m-d') !== $date->format('Y-m-d');
        $event->setTitle($title)->setCategory($category)->setEventDate($date)->setEventTime($time)->setEndDate($endDate)
            ->setPeople($texts['people'])->setPlace($texts['place'])
            ->setDescription(RichText::clean(InputValue::text($posted['description'] ?? null)))
            ->setRepeatsYearly(!empty($posted['repeatsYearly']))
            ->setRemindDaysBefore($remind === null ? null : (int) $remind)
            ->setUpdatedAt(time());
        if ($dateChanged) {
            $event->setLastRemindedFor(null); // a moved date deserves a fresh reminder
        }
        if ($isNew) {
            $this->em->persist($event);
        }
        $this->em->flush();
        $this->audit->record($actor, $isNew ? 'event.create' : 'event.update', $this->describe($event));

        return WriteResult::saved($event);
    }

    public function delete(Event $event, User $actor): void
    {
        $label = $this->describe($event);
        $files = $this->files->deleteAllFor(Attachment::OWNER_EVENT, (int) $event->getId());
        $this->em->remove($event);
        $this->em->flush();
        $this->audit->record($actor, 'event.delete', sprintf('%s (and %d files)', $label, $files));
    }

    /**
     * What happens in the next $days days (today included), soonest first.
     *
     * @return list<array{event: Event, date: \DateTimeImmutable, years: ?int, daysAway: int}>
     */
    public function upcoming(int $days, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $until = $today->modify(sprintf('+%d days', $days));
        $rows = [];
        foreach ($this->events->findUpcomingCandidates($today, $until) as $event) {
            $date = EventCalendar::nextOccurrence($event, $today);
            if ($date === null || $date > $until) {
                continue;
            }
            $rows[] = ['event' => $event, 'date' => $date, 'years' => EventCalendar::yearsAt($event, $date),
                'daysAway' => max(0, EventCalendar::daysBetween($today, $date))];
        }
        usort($rows, static fn (array $a, array $b) => [$a['date'], $a['event']->getTitle()] <=> [$b['date'], $b['event']->getTitle()]);

        return $rows;
    }

    private function describe(Event $event): string
    {
        return sprintf('#%d %s (%s)', (int) $event->getId(), $event->getTitle(), $event->getEventDate()->format('Y-m-d'));
    }
}
