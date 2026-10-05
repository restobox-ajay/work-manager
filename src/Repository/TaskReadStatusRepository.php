<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaskReadStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskReadStatus>
 */
class TaskReadStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskReadStatus::class);
    }

    public function findOneForUserAndTask(int $userId, int $taskId): ?TaskReadStatus
    {
        return $this->findOneBy(['userId' => $userId, 'taskId' => $taskId]);
    }

    /**
     * @return array<int, TaskReadStatus> keyed by taskId, for every row
     *         that already exists for $userId among $taskIds.
     */
    public function findForUserAndTasks(int $userId, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.userId = :userId')
            ->andWhere('r.taskId IN (:taskIds)')
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
     * Ports the pre-pass at the top of TaskController::actionManager():
     * every task in $taskIds gets a READ_NO row for $userId if it doesn't
     * have one yet, so the "unread" highlight/filter has something to key
     * off of the first time a manager ever sees a given task.
     */
    public function ensureRowsExist(int $userId, array $taskIds): void
    {
        if ($taskIds === []) {
            return;
        }

        $existing = $this->findForUserAndTasks($userId, $taskIds);
        $em = $this->getEntityManager();
        $now = time();

        foreach ($taskIds as $taskId) {
            if (isset($existing[$taskId])) {
                continue;
            }

            $row = new TaskReadStatus();
            $row->setUserId($userId);
            $row->setTaskId($taskId);
            $row->setIsRead(TaskReadStatus::READ_NO);
            $row->setCreatedAt($now);
            $row->setUpdatedAt($now);
            $em->persist($row);
        }

        $em->flush();
    }

    public function markRead(int $userId, int $taskId): void
    {
        $row = $this->findOneForUserAndTask($userId, $taskId);
        if ($row === null) {
            $row = new TaskReadStatus();
            $row->setUserId($userId);
            $row->setTaskId($taskId);
            $row->setCreatedAt(time());
        }

        $row->setIsRead(TaskReadStatus::READ_YES);
        $row->setUpdatedAt(time());

        $em = $this->getEntityManager();
        $em->persist($row);
        $em->flush();
    }
}
