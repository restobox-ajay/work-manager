<?php

declare(strict_types=1);

namespace App\Repository\Settings;

use App\Entity\Settings\TaskType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskType>
 */
class TaskTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskType::class);
    }

    /** @return TaskType[] every row, in display order */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.name', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();
    }
}
