<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event\Event;

/**
 * When an event next happens, and how many years it marks (ADR-126). Pure date arithmetic, no database:
 *  - a yearly event happens on its month and day every year from its first date on; 29 February falls on
 *    28 February in other years;
 *  - a one-off event happens on its date, and counts as current until its end date when it has one;
 *  - "years" is the count since the first date (a birthday's age, an anniversary's number), for yearly events only.
 */
final class EventCalendar
{
    /** The next date the event happens on or after $today; null when a one-off event is over. */
    public static function nextOccurrence(Event $event, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $today = $today->setTime(0, 0);
        $first = $event->getEventDate()->setTime(0, 0);
        if (!$event->isRepeatsYearly()) {
            $end = $event->getEndDate()?->setTime(0, 0) ?? $first;

            return $end >= $today ? $first : null;
        }
        if ($first >= $today) {
            return $first;
        }
        $thisYear = self::onYear($first, (int) $today->format('Y'));

        return $thisYear >= $today ? $thisYear : self::onYear($first, (int) $today->format('Y') + 1);
    }

    /** Years since the first date at this occurrence (a yearly event's age / anniversary number); null otherwise. */
    public static function yearsAt(Event $event, \DateTimeImmutable $occurrence): ?int
    {
        if (!$event->isRepeatsYearly()) {
            return null;
        }
        $years = (int) $occurrence->format('Y') - (int) $event->getEventDate()->format('Y');

        return $years > 0 ? $years : null;
    }

    /** Whole days from $today to $date (negative when past). */
    public static function daysBetween(\DateTimeImmutable $today, \DateTimeImmutable $date): int
    {
        return (int) $today->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');
    }

    /**
     * The occurrence a reminder email is due for today, or null: from N days before the occurrence up to the day
     * itself (so a missed day of the scheduled task still sends it), once per occurrence.
     */
    public static function reminderDueFor(Event $event, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $daysBefore = $event->getRemindDaysBefore();
        if ($daysBefore === null) {
            return null;
        }
        $occurrence = self::nextOccurrence($event, $today);
        if ($occurrence === null) {
            return null;
        }
        $daysLeft = self::daysBetween($today, $occurrence);
        if ($daysLeft < 0 || $daysLeft > $daysBefore) {
            return null;
        }
        $last = $event->getLastRemindedFor();

        return $last !== null && $last->format('Y-m-d') === $occurrence->format('Y-m-d') ? null : $occurrence;
    }

    /** The same month and day in another year; 29 February becomes 28 February when that year has none. */
    public static function onYear(\DateTimeImmutable $date, int $year): \DateTimeImmutable
    {
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');
        if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
            $day = 28;
        }

        return $date->setDate($year, $month, $day)->setTime(0, 0);
    }
}
