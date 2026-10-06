<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskPriorityOrderRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Security\Work\WorkAccess;

/**
 * The other task pages of work-platform's Tasks menu (ADR-072): Tasks By Contractor, Task By Date and Task Priority. Each is the task list query with different criteria, scoped by
 * WorkAccess exactly as the main list is.
 */
final class TaskReportService
{
    public const PRIORITY_TABS = [
        'assignee'          => 'Assignee (Pending)',
        'reviewerReviewing' => 'Reviewer (Reviewing)',
        'reviewerPending'   => 'Reviewer (Pending)',
    ];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskPriorityOrderRepository $priorityOrders,
        private readonly UserRepository $users,
        private readonly WorkAccess $access,
    ) {
    }

    /** @return User[] people assigned a task the viewer can see */
    public function contractors(User $viewer): array
    {
        $ids = $this->tasks->findDistinctAssigneeIds($this->access->taskVisibility($viewer));

        return $ids === [] ? [] : $this->users->findBy(['id' => $ids], ['name' => 'ASC']);
    }

    /**
     * Tasks whose billable (or creation) date falls in the range, by day, with each day's totals over the tasks
     * whose fee the viewer may see. Archived work is included: it is a billing report.
     *
     * @return list<array{date: string, tasks: Task[], minutes: int, amount: string}>
     */
    public function byDate(User $viewer, string $field, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $tasks = $this->tasks->findAllMatching([
            'visibility'      => $this->access->taskVisibility($viewer),
            'includeArchived' => true,
            'dateField'       => $field,
            'dateFrom'        => $from,
            'dateTo'          => $to,
        ], $field === 'billableDate' ? 't.billableDate' : 't.creationDate', 'ASC');

        $byDay = [];
        foreach ($tasks as $task) {
            $date = $field === 'billableDate' ? $task->getBillableDate() : $task->getCreationDate();
            $byDay[$date?->format('Y-m-d') ?? ''][] = $task;
        }
        ksort($byDay);

        $groups = [];
        foreach ($byDay as $date => $dayTasks) {
            $minutes = 0;
            $cents = 0;
            foreach ($dayTasks as $task) {
                if ($this->access->canAccessFee($viewer, $task)) {
                    $minutes += (int) $task->getTimeBudget();
                    // Summed in whole cents so a day's total never drifts by floating-point error.
                    $cents += (int) round((float) ($task->getTotalAmount() ?? '0') * 100);
                }
            }
            $groups[] = ['date' => (string) $date, 'tasks' => $dayTasks, 'minutes' => $minutes, 'amount' => number_format($cents / 100, 2, '.', '')];
        }

        return $groups;
    }

    /**
     * The three priority tabs for one person.
     *
     * @return array<string, array{label: string, tasks: Task[]}>
     */
    public function priorityTabs(int $targetUserId): array
    {
        $tabs = [];
        foreach (self::PRIORITY_TABS as $tab => $label) {
            $tabs[$tab] = ['label' => $label, 'tasks' => $this->tasks->findForPriorityTab($tab, $targetUserId)];
        }

        return $tabs;
    }

    /**
     * Whose priorities the viewer may look at: themselves always; an admin anyone; someone who manages work, the
     * people assigned their tasks.
     *
     * @return User[]
     */
    public function priorityPeople(User $viewer): array
    {
        if ($this->access->isAdmin($viewer)) {
            return $this->users->findActiveOrderedByName();
        }

        return $this->access->managesAnyWork($viewer) ? $this->contractors($viewer) : [];
    }

    public function canViewPriorities(User $viewer, int $targetUserId): bool
    {
        return $targetUserId === (int) $viewer->getId()
            || in_array($targetUserId, array_map(static fn (User $u) => (int) $u->getId(), $this->priorityPeople($viewer)), true);
    }

    /** Reordering someone's priorities is an admin's call (work-platform's canReorder). */
    public function canReorder(User $viewer): bool
    {
        return $this->access->isAdmin($viewer);
    }

    /**
     * @param int[] $orderedTaskIds the tab's current order
     * @param 'top'|'bottom'|null $move
     */
    public function reorder(int $targetUserId, array $orderedTaskIds, ?int $taskId, ?string $move): void
    {
        $this->priorityOrders->reorder($targetUserId, $orderedTaskIds, $taskId, $move);
    }
}
