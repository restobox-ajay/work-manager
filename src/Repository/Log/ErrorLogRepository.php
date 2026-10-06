<?php

declare(strict_types=1);

namespace App\Repository\Log;

use App\Entity\Log\ErrorLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErrorLog>
 */
class ErrorLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErrorLog::class);
    }

    /**
     * @param array{source?: string, q?: string, from?: int, to?: int} $filters
     *
     * @return array{items: ErrorLog[], total: int}
     */
    public function search(array $filters, int $page, int $limit): array
    {
        $qb = $this->filtered($filters);
        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('e.id', 'DESC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, int> source => count in the last $seconds */
    public function countBySourceSince(int $since): array
    {
        $counts = [];
        $rows = $this->createQueryBuilder('e')->select('e.source, COUNT(e.id) AS n')->where('e.createdAt >= :since')->setParameter('since', $since)
            ->groupBy('e.source')->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['source']] = (int) $row['n'];
        }

        return $counts;
    }

    public function deleteOlderThan(int $timestamp): int
    {
        return (int) $this->createQueryBuilder('e')->delete()->where('e.createdAt < :t')->setParameter('t', $timestamp)->getQuery()->execute();
    }

    /** @param array{source?: string, q?: string, from?: int, to?: int} $filters */
    private function filtered(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');
        if (isset($filters['source'])) {
            $qb->andWhere('e.source = :source')->setParameter('source', $filters['source']);
        }
        if (isset($filters['q'])) {
            $qb->andWhere('e.message LIKE :q OR e.path LIKE :q OR e.exceptionClass LIKE :q OR e.userEmail LIKE :q')
                ->setParameter('q', '%'.addcslashes($filters['q'], '%_\\').'%');
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
