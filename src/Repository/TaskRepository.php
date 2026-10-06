<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Entity\TaskPriorityOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Tasks (the work-platform `task` table, ADR-070).
 *
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    /**
     * The "(no client)" / "(no project)" filter choices: tasks with no project at all. They are not a named record,
     * so they do not count as asking for archived work (see buildListQuery()).
     */
    public const NO_CLIENT_ID = -999;
    public const NO_PROJECT_ID = -999;

    private const SORTABLE_COLUMNS = [
        'client'       => 'c.name',
        'project'      => 'p.name',
        'name'         => 't.name',
        'dueDate'      => 't.dueDate',
        'createdAt'    => 't.createdAt',
        'timeBudget'   => 't.timeBudget',
        'billableDate' => 't.billableDate',
        'totalAmount'  => 't.totalAmount',
    ];

    private const PENDING_PAYMENT_SORTABLE_COLUMNS = [
        'client'       => 'c.name',
        'project'      => 'p.name',
        'name'         => 't.name',
        'createdAt'    => 't.createdAt',
        'timeBudget'   => 't.timeBudget',
        'billableDate' => 't.billableDate',
        'totalAmount'  => 't.totalAmount',
        'approvedDate' => 't.approvedDate',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * @param array<string, mixed> $criteria see buildListQuery()
     *
     * @return Task[]
     */
    public function searchList(array $criteria, int $page, int $pageSize, ?string $sort): array
    {
        [$column, $direction] = SortParam::resolve($sort, self::SORTABLE_COLUMNS, '-createdAt');

        return $this->buildListQuery($criteria)
            ->addSelect('a')
            ->leftJoin('t.assignee', 'a')
            ->orderBy($column, $direction)
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
    }

    /** @param array<string, mixed> $criteria */
    public function countList(array $criteria): int
    {
        return (int) $this->buildListQuery($criteria)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Task[] the live tasks of one project, newest first (project page) */
    public function findForProject(int $projectId): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('a')
            ->leftJoin('t.assignee', 'a')
            ->andWhere('t.project = :projectId')
            ->andWhere('t.isDeleted = 0 AND t.isActive = 1')
            ->setParameter('projectId', $projectId)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // ── Payments (ADR-071) ───────────────────────────────────────────────────

    /**
     * Approved tasks no payment batch has taken yet. Archived work is included: archiving a project does not
     * cancel what is owed for it (owner-settled rule).
     *
     * @param ?int $assigneeId null = every contractor
     *
     * @return Task[]
     */
    public function findPendingPayments(?int $assigneeId, ?string $term, ?string $sort): array
    {
        [$column, $direction] = SortParam::resolve($sort, self::PENDING_PAYMENT_SORTABLE_COLUMNS, '-createdAt');

        $qb = $this->createQueryBuilder('t')
            ->addSelect('p', 'c', 'a')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.client', 'c')
            ->leftJoin('t.assignee', 'a')
            ->andWhere('t.taskStatusId = :approved')
            ->andWhere('t.isActive = 1 AND t.isDeleted = 0')
            ->andWhere("t.paymentId IS NULL OR t.paymentId = ''")
            ->setParameter('approved', TaskStatus::APPROVED_ID)
            ->orderBy($column, $direction)
            ->addOrderBy('t.id', 'DESC');

        if ($assigneeId !== null) {
            $qb->andWhere('t.assignee = :assignee')->setParameter('assignee', $assigneeId);
        }
        if ($term !== null) {
            $qb->andWhere('t.name LIKE :term')->setParameter('term', '%'.addcslashes($term, '%_').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * One page of payment batch ids that have tasks, newest payment first.
     *
     * @return array{ids: list<string>, total: int}
     */
    public function findPastPaymentBatchIds(?int $assigneeId, ?string $term, int $page, int $pageSize): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.paymentId')
            ->andWhere("t.paymentId IS NOT NULL AND t.paymentId <> ''")
            ->andWhere('t.isDeleted = 0')
            ->groupBy('t.paymentId')
            ->orderBy('MAX(t.paidDate)', 'DESC');

        if ($assigneeId !== null) {
            $qb->andWhere('t.assignee = :assignee')->setParameter('assignee', $assigneeId);
        }
        if ($term !== null) {
            $qb->andWhere('t.name LIKE :term')->setParameter('term', '%'.addcslashes($term, '%_').'%');
        }

        $all = array_map('strval', array_column($qb->getQuery()->getScalarResult(), 'paymentId'));

        return ['ids' => array_slice($all, max(0, $page - 1) * $pageSize, $pageSize), 'total' => count($all)];
    }

    /**
     * What is in each payment batch. A soft-deleted task still counts — it was paid for.
     *
     * @param list<string> $paymentIds
     *
     * @return array<string, Task[]>
     */
    public function findByPaymentIds(array $paymentIds): array
    {
        $result = array_fill_keys($paymentIds, []);
        if ($paymentIds === []) {
            return $result;
        }

        foreach ($this->createQueryBuilder('t')
            ->addSelect('p', 'c', 'a')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.client', 'c')
            ->leftJoin('t.assignee', 'a')
            ->andWhere('t.paymentId IN (:ids)')
            ->setParameter('ids', $paymentIds)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult() as $task) {
            $result[(string) $task->getPaymentId()][] = $task;
        }

        return $result;
    }

    public function batchHasLiveTaskAssignedTo(string $paymentId, int $userId): bool
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.paymentId = :paymentId AND t.assignee = :userId AND t.isDeleted = 0')
            ->setParameter('paymentId', $paymentId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Live tasks of the given contractors, grouped by contractor then project (contractor summary page).
     *
     * @param int[] $assigneeIds
     *
     * @return array<int, array<int, Task[]>> assignee id => project id => tasks
     */
    public function findGroupedByAssigneeAndProject(array $assigneeIds): array
    {
        if ($assigneeIds === []) {
            return [];
        }

        $grouped = [];
        foreach ($this->createQueryBuilder('t')
            ->addSelect('p', 'c', 'a')
            ->join('t.project', 'p')
            ->join('p.client', 'c')
            ->join('t.assignee', 'a')
            ->andWhere('a.id IN (:ids)')
            ->andWhere('t.isActive = 1 AND t.isDeleted = 0')
            ->setParameter('ids', $assigneeIds)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult() as $task) {
            $grouped[(int) $task->getAssignee()->getId()][(int) $task->getProject()->getId()][] = $task;
        }

        return $grouped;
    }

    /**
     * A user's own tasks in one status, for the dashboard widgets.
     *
     * $includeArchived: the "approved but not paid" widget lists money owed, and archiving a project does not
     * cancel what is owed for the work under it — so it shows archived work. The to-do widgets do not.
     *
     * @return Task[]
     */
    public function findAssignedInStatus(int $userId, int $statusId, int $limit, bool $includeArchived, string $orderBy): array
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('p', 'c')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.client', 'c')
            ->andWhere('t.assignee = :userId')
            ->andWhere('t.taskStatusId = :statusId')
            ->andWhere('t.isDeleted = 0 AND t.isActive = 1')
            ->setParameter('userId', $userId)
            ->setParameter('statusId', $statusId)
            ->setMaxResults($limit);

        if (!$includeArchived) {
            $qb->andWhere('(p.id IS NULL) OR (c.isDeleted = 0 AND c.isActive = 1 AND p.isDeleted = 0 AND p.status = 1)');
        }

        return match ($orderBy) {
            // Soonest due first; tasks with no due date after them, oldest first.
            'due'    => $qb->addSelect('CASE WHEN t.dueDate IS NULL THEN 1 ELSE 0 END AS HIDDEN noDue')
                ->orderBy('noDue', 'ASC')->addOrderBy('t.dueDate', 'ASC')->addOrderBy('t.createdAt', 'ASC')
                ->getQuery()->getResult(),
            default  => $qb->orderBy('t.createdAt', 'DESC')->getQuery()->getResult(),
        };
    }

    /**
     * The task list query.
     *
     * Archived visibility (owner-settled rule, carried over from work-platform — do not re-derive): "archived" is
     * a project with status = 0 or is_deleted = 1, or a client with is_active = 0 or is_deleted = 1. With no
     * client or project named in the filters, tasks under archived work are hidden. Naming a client shows
     * everything under it, archived projects included; naming a project shows everything in it. The rows that
     * surface this way are tagged "Archived" by the page (TaskListService). The NO_* sentinels do not count as
     * naming something. A task's own is_deleted/is_active flags are never relaxed.
     *
     * Criteria (all optional):
     *  - visibility: null for "every task", else {userId, taskIds, projectIds, clientIds} — a task is visible when
     *    the user is its assignee, reviewer or creator, or its id/project/client is in the matching set
     *  - term, clientId, projectId, assigneeId, reviewerUserId, createdBy, notCreatedBy, taskStatusId, taskTypeId
     *  - taskIds (only these; [] = none), queue (see applyQueueCriteria()), includeArchived
     *  - dateField ('billableDate'|'creationDate') with dateFrom/dateTo (inclusive days)
     *
     * @param array<string, mixed> $criteria
     */
    private function buildListQuery(array $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('p', 'c')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.client', 'c')
            ->andWhere('t.isDeleted = 0')
            ->andWhere('t.isActive = 1');

        $clientId = $criteria['clientId'] ?? null;
        $projectId = $criteria['projectId'] ?? null;
        $named = ($projectId !== null && $projectId !== self::NO_PROJECT_ID)
            || ($clientId !== null && $clientId !== self::NO_CLIENT_ID);

        // includeArchived: the by-date report is a billing report — archiving a project does not cancel what is owed.
        if (!$named && !($criteria['includeArchived'] ?? false)) {
            $qb->andWhere('(p.id IS NULL) OR (c.isDeleted = 0 AND c.isActive = 1 AND p.isDeleted = 0 AND p.status = 1)');
        }

        if (($criteria['visibility'] ?? null) !== null) {
            $this->applyVisibility($qb, $criteria['visibility']);
        }

        if (($criteria['term'] ?? null) !== null) {
            $qb->andWhere('t.name LIKE :term OR p.name LIKE :term OR c.name LIKE :term')
                ->setParameter('term', '%'.addcslashes((string) $criteria['term'], '%_').'%');
        }

        if ($clientId === self::NO_CLIENT_ID || $projectId === self::NO_PROJECT_ID) {
            $qb->andWhere('t.project IS NULL');
        }
        if ($clientId !== null && $clientId !== self::NO_CLIENT_ID) {
            $qb->andWhere('c.id = :clientId OR t.clientId = :clientId')->setParameter('clientId', $clientId);
        }
        if ($projectId !== null && $projectId !== self::NO_PROJECT_ID) {
            $qb->andWhere('t.project = :projectId')->setParameter('projectId', $projectId);
        }

        $this->applyQueueCriteria($qb, $criteria);

        if (($criteria['dateField'] ?? null) !== null) {
            $field = match ($criteria['dateField']) {
                'billableDate' => 't.billableDate',
                'creationDate' => 't.creationDate',
            };
            $qb->andWhere("$field BETWEEN :dateFrom AND :dateTo")
                ->setParameter('dateFrom', $criteria['dateFrom'], 'date_immutable')
                ->setParameter('dateTo', $criteria['dateTo'], 'date_immutable');
        }
        if (($criteria['notCreatedBy'] ?? null) !== null) {
            $qb->andWhere('t.createdBy IS NULL OR t.createdBy <> :notCreatedBy')->setParameter('notCreatedBy', $criteria['notCreatedBy']);
        }
        if (array_key_exists('taskIds', $criteria) && $criteria['taskIds'] !== null) {
            $criteria['taskIds'] === []
                ? $qb->andWhere('1 = 0')
                : $qb->andWhere('t.id IN (:onlyTaskIds)')->setParameter('onlyTaskIds', $criteria['taskIds']);
        }

        foreach ([
            'createdBy'      => 't.createdBy',
            'assigneeId'     => 't.assignee',
            'reviewerUserId' => 't.reviewerUserId',
            'taskStatusId'   => 't.taskStatusId',
            'taskTypeId'     => 't.taskTypeId',
        ] as $key => $field) {
            if (($criteria[$key] ?? null) !== null) {
                $qb->andWhere("$field = :$key")->setParameter($key, $criteria[$key]);
            }
        }

        return $qb;
    }

    /**
     * queue = 'none': normal work only (`authorized` = Task::AUTHORIZED_NO). Tasks are never queued any more (ADR-084);
     * this still keeps the denied requests from the old authorization queue out of every list.
     */
    private function applyQueueCriteria(QueryBuilder $qb, array $criteria): void
    {
        match ($criteria['queue'] ?? null) {
            'none'    => $qb->andWhere('t.authorized = :notQueued')->setParameter('notQueued', Task::AUTHORIZED_NO),
            default   => null,
        };
    }

    /**
     * One tab of the Task Priority page: the target user's tasks, in their own priority order, then newest.
     *
     * @param 'assignee'|'reviewerReviewing'|'reviewerPending' $tab
     *
     * @return Task[]
     */
    public function findForPriorityTab(string $tab, int $userId): array
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('p', 'c', 'a')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.client', 'c')
            ->leftJoin('t.assignee', 'a')
            ->leftJoin(TaskPriorityOrder::class, 'o', 'WITH', 'o.taskId = t.id AND o.userId = :userId')
            ->andWhere('t.isDeleted = 0 AND t.isActive = 1')
            ->andWhere('t.authorized = :notQueued')
            ->setParameter('notQueued', Task::AUTHORIZED_NO)
            ->setParameter('userId', $userId)
            // Unordered tasks (no row yet) after the ordered ones.
            ->addSelect('CASE WHEN o.sortOrder IS NULL THEN 1 ELSE 0 END AS HIDDEN unordered')
            ->orderBy('unordered', 'ASC')
            ->addOrderBy('o.sortOrder', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC');

        [$statusId, $field] = match ($tab) {
            'assignee'          => [TaskStatus::PENDING_ID, 't.assignee'],
            'reviewerReviewing' => [TaskStatus::REVIEWING_INTERNAL_ID, 't.reviewerUserId'],
            'reviewerPending'   => [TaskStatus::PENDING_ID, 't.reviewerUserId'],
        };

        return $qb->andWhere('t.taskStatusId = :statusId')->andWhere("$field = :userId")
            ->setParameter('statusId', $statusId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everyone assigned a task the viewer can see — the Tasks By Contractor side list.
     *
     * @param array{userId: int, taskIds: int[], projectIds: int[], clientIds: int[]}|null $visibility
     *
     * @return int[]
     */
    public function findDistinctAssigneeIds(?array $visibility): array
    {
        $qb = $this->buildListQuery(['visibility' => $visibility, 'queue' => 'none'])
            ->select('DISTINCT IDENTITY(t.assignee) AS assigneeId')
            ->andWhere('t.assignee IS NOT NULL');

        return array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'assigneeId'));
    }

    /**
     * Every task matching the criteria, unpaged (by-date report, manager-created page).
     *
     * @param array<string, mixed> $criteria see buildListQuery()
     *
     * @return Task[]
     */
    public function findAllMatching(array $criteria, string $orderBy = 't.createdAt', string $direction = 'DESC', ?int $limit = null): array
    {
        $qb = $this->buildListQuery($criteria)
            ->addSelect('a')
            ->leftJoin('t.assignee', 'a')
            ->orderBy($orderBy, $direction)
            ->addOrderBy('t.id', 'DESC');
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array{userId: int, taskIds: int[], projectIds: int[], clientIds: int[]} $visibility
     */
    private function applyVisibility(QueryBuilder $qb, array $visibility): void
    {
        $or = ['t.assignee = :visUser', 't.reviewerUserId = :visUser', 't.createdBy = :visUser'];
        $qb->setParameter('visUser', $visibility['userId']);

        if ($visibility['taskIds'] !== []) {
            $or[] = 't.id IN (:visTasks)';
            $qb->setParameter('visTasks', $visibility['taskIds']);
        }
        if ($visibility['projectIds'] !== []) {
            $or[] = 'p.id IN (:visProjects)';
            $qb->setParameter('visProjects', $visibility['projectIds']);
        }
        if ($visibility['clientIds'] !== []) {
            $or[] = 'c.id IN (:visClients)';
            $or[] = 't.clientId IN (:visClients)';
            $qb->setParameter('visClients', $visibility['clientIds']);
        }

        $qb->andWhere(implode(' OR ', $or));
    }
}
