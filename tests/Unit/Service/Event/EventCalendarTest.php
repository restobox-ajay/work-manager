<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Event;

use App\Entity\Event\Event;
use App\Service\Event\EventCalendar;
use PHPUnit\Framework\TestCase;

/** ADR-126: when an event next happens, the years it marks and when its reminder is due. */
final class EventCalendarTest extends TestCase
{
    public function testAYearlyEventRollsForwardToThisYearOrNext(): void
    {
        $birthday = $this->event('1966-10-20', yearly: true);

        self::assertSame('2026-10-20', EventCalendar::nextOccurrence($birthday, $this->day('2026-10-11'))?->format('Y-m-d'));
        self::assertSame('2026-10-20', EventCalendar::nextOccurrence($birthday, $this->day('2026-10-20'))?->format('Y-m-d'), 'The day itself counts.');
        self::assertSame('2027-10-20', EventCalendar::nextOccurrence($birthday, $this->day('2026-10-21'))?->format('Y-m-d'));
    }

    public function testYearsAreCountedFromTheFirstDate(): void
    {
        $birthday = $this->event('1966-10-20', yearly: true);

        self::assertSame(60, EventCalendar::yearsAt($birthday, $this->day('2026-10-20')));
        self::assertNull(EventCalendar::yearsAt($this->event('2026-10-20', yearly: false), $this->day('2026-10-20')), 'Only yearly events count years.');
    }

    public function testTwentyNinthOfFebruaryFallsOnTheTwentyEighthInOtherYears(): void
    {
        $leap = $this->event('2000-02-29', yearly: true);

        self::assertSame('2027-02-28', EventCalendar::nextOccurrence($leap, $this->day('2026-10-11'))?->format('Y-m-d'));
        self::assertSame('2028-02-29', EventCalendar::nextOccurrence($leap, $this->day('2027-03-01'))?->format('Y-m-d'));
    }

    public function testAFutureYearlyEventStartsOnItsOwnDate(): void
    {
        self::assertSame('2027-05-01', EventCalendar::nextOccurrence($this->event('2027-05-01', yearly: true), $this->day('2026-10-11'))?->format('Y-m-d'));
    }

    public function testAOneOffEventIsUpcomingUntilItEnds(): void
    {
        $today = $this->day('2026-10-11');

        self::assertNull(EventCalendar::nextOccurrence($this->event('2026-10-01'), $today), 'Over.');
        self::assertSame('2026-10-15', EventCalendar::nextOccurrence($this->event('2026-10-15'), $today)?->format('Y-m-d'));
        self::assertSame('2026-10-09', EventCalendar::nextOccurrence($this->event('2026-10-09', end: '2026-10-12'), $today)?->format('Y-m-d'), 'Still going.');
    }

    public function testTheReminderIsDueFromItsDayUntilTheEventAndOnlyOnce(): void
    {
        $event = $this->event('1966-10-20', yearly: true, remind: 3);

        self::assertNull(EventCalendar::reminderDueFor($event, $this->day('2026-10-16')), 'Too early.');
        self::assertSame('2026-10-20', EventCalendar::reminderDueFor($event, $this->day('2026-10-17'))?->format('Y-m-d'));
        self::assertSame('2026-10-20', EventCalendar::reminderDueFor($event, $this->day('2026-10-19'))?->format('Y-m-d'), 'A missed day still sends it.');

        $event->setLastRemindedFor($this->day('2026-10-20'));
        self::assertNull(EventCalendar::reminderDueFor($event, $this->day('2026-10-19')), 'Already sent for this occurrence.');
        self::assertSame('2027-10-20', EventCalendar::reminderDueFor($event, $this->day('2027-10-18'))?->format('Y-m-d'), 'Next year is a new occurrence.');
    }

    public function testNoReminderWithoutOneAsked(): void
    {
        self::assertNull(EventCalendar::reminderDueFor($this->event('2026-10-12'), $this->day('2026-10-12')));
        self::assertSame('2026-10-12', EventCalendar::reminderDueFor($this->event('2026-10-12', remind: 0), $this->day('2026-10-12'))?->format('Y-m-d'), 'On the day.');
    }

    private function event(string $date, bool $yearly = false, ?string $end = null, ?int $remind = null): Event
    {
        return (new Event())->setEventDate($this->day($date))->setRepeatsYearly($yearly)
            ->setEndDate($end === null ? null : $this->day($end))->setRemindDaysBefore($remind);
    }

    private function day(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd);
    }
}
