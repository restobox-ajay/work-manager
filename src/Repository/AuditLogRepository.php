<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Service\Log\ActivityAreas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function countFiltered(array $filters): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return AuditLog[] */
    public function findPaginated(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.createdAt', 'DESC');
        $this->applyFilters($qb, $filters);

        return $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> every action recorded so far, for the Activity Log's filter */
    public function findDistinctActions(): array
    {
        return array_column($this->createQueryBuilder('a')->select('DISTINCT a.action')->orderBy('a.action', 'ASC')->getQuery()->getArrayResult(), 'action');
    }

    /** @return iterable<AuditLog> matching entries, newest first, at most $limit — for the CSV export */
    public function iterateFiltered(array $filters, int $limit): iterable
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.createdAt', 'DESC')->addOrderBy('a.id', 'DESC')->setMaxResults($limit);
        $this->applyFilters($qb, $filters);

        return $qb->getQuery()->toIterable();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\AuditLog a WHERE a.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }

    /** Dry-run mirror of {@see deleteOlderThan()} for app:prune (FEATURE-147). Mutates nothing. */
    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['actor'])) {
            $qb->andWhere('a.actor LIKE :actor')
               ->setParameter('actor', '%' . $filters['actor'] . '%');
        }

        if (!empty($filters['action'])) {
            $qb->andWhere('a.action = :action')
               ->setParameter('action', $filters['action']);
        }

        if (!empty($filters['area'])) {
            // Sign-in & security actions have no "area." prefix; the others start with theirs (ADR-093).
            if ($filters['area'] === ActivityAreas::SECURITY) {
                $qb->andWhere('a.action NOT LIKE :dotted')->setParameter('dotted', '%.%');
            } else {
                $qb->andWhere('a.action LIKE :area')->setParameter('area', $filters['area'].'.%');
            }
        }

        if (!empty($filters['outcome'])) {
            $qb->andWhere('a.outcome = :outcome')->setParameter('outcome', $filters['outcome']);
        }

        // date_from/date_to are already validated \DateTimeImmutable objects
        // (parsed by AuditLogFilterParser); no unparsed strings reach here.
        if (!empty($filters['date_from'])) {
            $qb->andWhere('a.createdAt >= :date_from')
               ->setParameter('date_from', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $qb->andWhere('a.createdAt <= :date_to')
               ->setParameter('date_to', $filters['date_to']);
        }
    }
}
