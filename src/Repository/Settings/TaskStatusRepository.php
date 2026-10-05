<?php

declare(strict_types=1);

namespace App\Repository\Settings;

use App\Entity\Settings\TaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskStatus>
 */
class TaskStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskStatus::class);
    }

    /** @return TaskStatus[] every row, in display order */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.order', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();
    }
}
