<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
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
    public function findForProject(int $projectId, int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('a')
            ->leftJoin('t.assignee', 'a')
            ->andWhere('t.project = :projectId')
            ->andWhere('t.isDeleted = 0 AND t.isActive = 1')
            ->setParameter('projectId', $projectId)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
     *  - term, clientId, projectId, assigneeId, reviewerUserId, taskStatusId, taskTypeId
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

        if (!$named) {
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

        foreach ([
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
