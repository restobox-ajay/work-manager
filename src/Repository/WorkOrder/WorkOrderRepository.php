<?php

declare(strict_types=1);

namespace App\Repository\WorkOrder;

use App\Entity\WorkOrder\WorkOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkOrder>
 */
class WorkOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkOrder::class);
    }

    /**
     * @param list<string>|null $statuses null = every status
     *
     * @return WorkOrder[] newest first
     */
    public function findFiltered(?array $statuses, ?int $clientId, ?string $term): array
    {
        $qb = $this->createQueryBuilder('w')->addSelect('c')->leftJoin('w.client', 'c')
            ->orderBy('w.createdAt', 'DESC')->addOrderBy('w.id', 'DESC');
        if ($statuses !== null) {
            $qb->andWhere('w.status IN (:statuses)')->setParameter('statuses', $statuses);
        }
        if ($clientId !== null) {
            $qb->andWhere('c.id = :clientId')->setParameter('clientId', $clientId);
        }
        if ($term !== null && $term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $qb->andWhere('w.title LIKE :t OR w.number LIKE :t OR w.clientName LIKE :t OR c.name LIKE :t OR w.reference LIKE :t OR w.contactName LIKE :t')
                ->setParameter('t', $like);
        }

        return $qb->getQuery()->getResult();
    }

    /** The highest sequence used in a year's numbers ("WO-2026-007" → 7), 0 when none. */
    public function lastSequenceForYear(string $prefix): int
    {
        $numbers = $this->createQueryBuilder('w')->select('w.number')
            ->andWhere('w.number LIKE :p')->setParameter('p', $prefix.'%')
            ->getQuery()->getSingleColumnResult();
        $max = 0;
        foreach ($numbers as $number) {
            if (preg_match('/(\d+)$/', (string) $number, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }

    /** @return WorkOrder[] the work orders made into this project */
    public function findForProject(int $projectId): array
    {
        return $this->findBy(['project' => $projectId], ['id' => 'ASC']);
    }
}
