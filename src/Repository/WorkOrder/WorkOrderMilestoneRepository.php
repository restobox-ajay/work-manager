<?php

declare(strict_types=1);

namespace App\Repository\WorkOrder;

use App\Entity\WorkOrder\WorkOrder;
use App\Entity\WorkOrder\WorkOrderMilestone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkOrderMilestone>
 */
class WorkOrderMilestoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkOrderMilestone::class);
    }

    /** @return WorkOrderMilestone[] by due date (undated last), then as added */
    public function findForWorkOrder(WorkOrder $workOrder): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('CASE WHEN m.dueDate IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->andWhere('m.workOrder = :wo')->setParameter('wo', $workOrder)
            ->orderBy('undated', 'ASC')->addOrderBy('m.dueDate', 'ASC')->addOrderBy('m.id', 'ASC')
            ->getQuery()->getResult();
    }
}
