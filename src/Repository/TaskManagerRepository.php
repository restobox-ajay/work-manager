<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaskManager;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * `task_manager`: a user granted manage (and optionally fee) rights on one task.
 *
 * @extends ServiceEntityRepository<TaskManager>
 */
class TaskManagerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskManager::class);
    }

    /**
     * One user's task-manager grants, for the per-request access map.
     *
     * @return array<int, bool> task id => may see the fee
     */
    public function findFeeAccessByTaskForUser(int $userId): array
    {
        $grants = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT task_id, can_access_task_fee FROM task_manager WHERE user_id = ?',
            [$userId],
        ) as $row) {
            $grants[(int) $row['task_id']] = (bool) $row['can_access_task_fee'];
        }

        return $grants;
    }
}
