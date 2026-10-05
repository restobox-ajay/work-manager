<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Task;
use App\Entity\TaskReadStatus;
use App\Entity\User;
use App\Repository\TaskPriorityOrderRepository;
use App\Repository\TaskReadStatusRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Security\Work\WorkAccess;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The other task pages of work-platform's Tasks menu (ADR-072): Tasks By Contractor, Task By Date, Task Created By
 * Manager, Authorization Queue and Task Priority. Each is the task list query with different criteria, scoped by
 * WorkAccess exactly as the main list is.
 */
final class TaskReportService
{
    public const PRIORITY_TABS = [
        'assignee'          => 'Assignee (Pending)',
        'reviewerReviewing' => 'Reviewer (Reviewing)',
        'reviewerPending'   => 'Reviewer (Pending)',
    ];

    /** work-platform listed the newest 50 on the manager page. */
    private const MANAGER_PAGE_LIMIT = 50;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskReadStatusRepository $readStatuses,
        private readonly TaskPriorityOrderRepository $priorityOrders,
        private readonly UserRepository $users,
        private readonly WorkAccess $access,
        private readonly EntityManagerInterface $em,
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
     * Tasks someone else filed that wait in the authorization queue (work-platform's "Task Created By Manager"),
     * newest first, with whether the viewer has marked each read.
     *
     * @param bool|null $read null = both
     *
     * @return array{tasks: Task[], read: array<int, bool>}
     */
    public function createdByOthers(User $viewer, ?int $createdBy, ?bool $read): array
    {
        $viewerId = (int) $viewer->getId();
        $criteria = [
            'visibility'   => $this->access->taskVisibility($viewer),
            'queue'        => 'pending',
            'notCreatedBy' => $viewerId,
            'createdBy'    => $createdBy,
        ];
        $tasks = $this->tasks->findAllMatching($criteria, limit: self::MANAGER_PAGE_LIMIT);
        $readByTask = $this->readMap($viewerId, $tasks);

        if ($read !== null) {
            $tasks = array_values(array_filter($tasks, static fn (Task $task) => ($readByTask[(int) $task->getId()] ?? false) === $read));
        }

        return ['tasks' => $tasks, 'read' => $readByTask];
    }

    public function setRead(User $viewer, Task $task, bool $read): void
    {
        $this->readStatuses->ensureRowsExist((int) $viewer->getId(), [(int) $task->getId()]);
        $row = $this->readStatuses->findOneForUserAndTask((int) $viewer->getId(), (int) $task->getId());
        $row?->setIsRead($read ? TaskReadStatus::READ_YES : TaskReadStatus::READ_NO)->setUpdatedAt(time());
        $this->em->flush();
    }

    /**
     * The authorization queue: 'pending' = waiting for a decision, 'decided' = every decision made.
     *
     * @return Task[]
     */
    public function queue(User $viewer, string $which, ?int $clientId, ?string $term): array
    {
        return $this->tasks->findAllMatching([
            'visibility' => $this->access->taskVisibility($viewer),
            'queue'      => $which === 'decided' ? 'decided' : 'pending',
            'clientId'   => $clientId,
            'term'       => $term,
        ]);
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

    /**
     * @param Task[] $tasks
     *
     * @return array<int, bool>
     */
    private function readMap(int $viewerId, array $tasks): array
    {
        $ids = array_map(static fn (Task $task) => (int) $task->getId(), $tasks);
        $read = [];
        foreach ($this->readStatuses->findForUserAndTasks($viewerId, $ids) as $taskId => $row) {
            $read[(int) $taskId] = $row->isRead();
        }

        return $read;
    }
}
