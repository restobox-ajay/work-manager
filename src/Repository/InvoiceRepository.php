<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Enum\InvoiceKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * One page of the invoice list, newest first.
     *
     * @param array{term?: ?string, clientId?: ?int, status?: ?string, year?: ?int, month?: ?int} $filters
     *
     * @return Invoice[]
     */
    public function search(?InvoiceKind $kind, array $filters, int $page, int $pageSize): array
    {
        return $this->filtered($kind, $filters)
            ->orderBy('i.invoiceDate', 'DESC')->addOrderBy('i.id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize)
            ->getQuery()->getResult();
    }

    /** @param array{term?: ?string, clientId?: ?int, status?: ?string, year?: ?int, month?: ?int} $filters */
    public function countSearch(?InvoiceKind $kind, array $filters): int
    {
        return (int) $this->filtered($kind, $filters)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Every match, no paging (a filtered list shows all its rows), oldest month last.
     *
     * @param array{term?: ?string, clientId?: ?int, status?: ?string, year?: ?int, month?: ?int} $filters
     *
     * @return Invoice[]
     */
    public function findAllMatching(?InvoiceKind $kind, array $filters): array
    {
        return $this->filtered($kind, $filters)
            ->orderBy('i.invoiceDate', 'DESC')->addOrderBy('i.id', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return int[] the years invoices are dated in, newest first (the Year filter's options) */
    public function findYears(?InvoiceKind $kind): array
    {
        $qb = $this->createQueryBuilder('i')->select('i.invoiceDate AS d')->where('i.invoiceDate IS NOT NULL');
        if ($kind !== null) {
            $qb->andWhere('i.kind = :kind')->setParameter('kind', $kind);
        }
        $years = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $years[(int) $row['d']->format('Y')] = true;
        }
        krsort($years);

        return array_keys($years);
    }

    public function numberExists(string $number): bool
    {
        return $this->count(['number' => $number]) > 0;
    }

    /** @param array{term?: ?string, clientId?: ?int, status?: ?string, year?: ?int, month?: ?int} $filters */
    /** $kind null = both kinds (the List Invoices page). */
    private function filtered(?InvoiceKind $kind, array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i');
        if ($kind !== null) {
            $qb->andWhere('i.kind = :kind')->setParameter('kind', $kind);
        }
        if (($filters['term'] ?? null) !== null) {
            $qb->andWhere('i.number LIKE :term OR i.toName LIKE :term OR i.fromName LIKE :term')
                ->setParameter('term', '%'.addcslashes($filters['term'], '%_\\').'%');
        }
        if (($filters['clientId'] ?? null) !== null) {
            $qb->andWhere('i.clientId = :clientId')->setParameter('clientId', $filters['clientId']);
        }
        if (($filters['status'] ?? null) !== null) {
            $qb->andWhere('i.status = :status')->setParameter('status', $filters['status']);
        }
        // Year (and month) filter the invoice date as a range; a month without a year is applied by the caller.
        $year = $filters['year'] ?? null;
        if ($year !== null) {
            $month = $filters['month'] ?? null;
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month ?? 1));
            $qb->andWhere('i.invoiceDate >= :from AND i.invoiceDate < :to')
                ->setParameter('from', $from, 'date_immutable')
                ->setParameter('to', $from->modify($month !== null ? '+1 month' : '+1 year'), 'date_immutable');
        }

        return $qb;
    }
}
