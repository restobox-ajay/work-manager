<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaskPriorityOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskPriorityOrder>
 */
class TaskPriorityOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskPriorityOrder::class);
    }

    public function findOneForUserAndTask(int $userId, int $taskId): ?TaskPriorityOrder
    {
        return $this->findOneBy(['userId' => $userId, 'taskId' => $taskId]);
    }

    /**
     * @return array<int, TaskPriorityOrder> keyed by taskId
     */
    public function findForUserAndTasks(int $userId, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.userId = :userId')
            ->andWhere('o.taskId IN (:taskIds)')
            ->setParameter('userId', $userId)
            ->setParameter('taskIds', $taskIds)
            ->getQuery()
            ->getResult();

        $byTaskId = [];
        foreach ($rows as $row) {
            $byTaskId[$row->getTaskId()] = $row;
        }

        return $byTaskId;
    }

    /**
     * Direct port of the seed-on-view logic in TaskController::beforeAction():
     * the first time a task with an assignee is viewed, it gets appended to
     * the end of that assignee's priority list. A no-op if a row already
     * exists.
     */
    public function ensureExists(int $userId, int $taskId): void
    {
        if ($this->findOneForUserAndTask($userId, $taskId) !== null) {
            return;
        }

        $count = (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        $row = new TaskPriorityOrder();
        $row->setUserId($userId);
        $row->setTaskId($taskId);
        $row->setSortOrder($count + 1);

        $em = $this->getEntityManager();
        $em->persist($row);
        $em->flush();
    }

    /**
     * Direct port of TaskController::actionSort(): given the tab's full
     * task-id list in its current displayed order, optionally relocate
     * $moveTaskId to the top/bottom first, then persist 1-based
     * sort_order for every id in the resulting sequence (skipping ids
     * that don't have a priority-order row yet for this user, same as
     * Yii2's `if (!empty($model))` guard).
     */
    public function reorder(int $userId, array $orderedTaskIds, ?int $moveTaskId, ?string $sortType): void
    {
        if ($sortType !== null && $moveTaskId !== null) {
            $index = array_search($moveTaskId, $orderedTaskIds, true);
            if ($index !== false) {
                unset($orderedTaskIds[$index]);
            }
            $orderedTaskIds = array_values($orderedTaskIds);

            if ($sortType === 'top') {
                array_unshift($orderedTaskIds, $moveTaskId);
            } elseif ($sortType === 'bottom') {
                $orderedTaskIds[] = $moveTaskId;
            }
        }

        if ($orderedTaskIds === []) {
            return;
        }

        $rowsByTaskId = $this->findForUserAndTasks($userId, $orderedTaskIds);
        $em = $this->getEntityManager();

        $position = 0;
        foreach ($orderedTaskIds as $taskId) {
            // A task listed on the page but never ordered yet gets its row now, so moving it always sticks.
            if (!isset($rowsByTaskId[$taskId])) {
                $rowsByTaskId[$taskId] = (new TaskPriorityOrder())->setUserId($userId)->setTaskId($taskId);
                $em->persist($rowsByTaskId[$taskId]);
            }

            ++$position;
            $rowsByTaskId[$taskId]->setSortOrder($position);
        }

        $em->flush();
    }
}
