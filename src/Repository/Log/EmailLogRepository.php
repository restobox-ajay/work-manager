<?php

declare(strict_types=1);

namespace App\Repository\Log;

use App\Entity\Log\EmailLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailLog>
 */
class EmailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailLog::class);
    }

    /**
     * @param array{status?: string, q?: string, from?: int, to?: int} $filters
     *
     * @return array{items: EmailLog[], total: int}
     */
    public function search(array $filters, int $page, int $limit): array
    {
        $qb = $this->filtered($filters);
        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('e.id', 'DESC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, int> status => count */
    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('e')->select('e.status, COUNT(e.id) AS n')->groupBy('e.status')->getQuery()->getArrayResult() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    public function deleteOlderThan(int $timestamp): int
    {
        return (int) $this->createQueryBuilder('e')->delete()->where('e.createdAt < :t')->setParameter('t', $timestamp)->getQuery()->execute();
    }

    /** @param array{status?: string, q?: string, from?: int, to?: int} $filters */
    private function filtered(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');
        if (isset($filters['status'])) {
            $qb->andWhere('e.status = :status')->setParameter('status', $filters['status']);
        }
        if (isset($filters['q'])) {
            $qb->andWhere('e.recipients LIKE :q OR e.subject LIKE :q OR e.cc LIKE :q')->setParameter('q', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
        if (isset($filters['from'])) {
            $qb->andWhere('e.createdAt >= :from')->setParameter('from', $filters['from']);
        }
        if (isset($filters['to'])) {
            $qb->andWhere('e.createdAt < :to')->setParameter('to', $filters['to']);
        }

        return $qb;
    }
}
