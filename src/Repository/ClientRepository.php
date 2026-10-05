<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Clients (the work-platform `client` table, ADR-070). Soft-deleted rows ("Archive and remove from the list") are
 * never listed.
 *
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    /** ?sort= keys the list page offers, mapped to what they order by; a leading '-' means descending. */
    private const SORTABLE_COLUMNS = [
        'name'      => 'c.name',
        'clientCode' => 'c.clientCode',
        'email'     => 'c.email',
        'isActive'  => 'c.isActive',
        'createdAt' => 'c.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * @param array{term?: ?string, isActive?: ?int} $filters
     * @param int[]|null                             $visibleIds null = every client
     *
     * @return Client[]
     */
    public function search(array $filters, ?array $visibleIds, int $page, int $pageSize, ?string $sort): array
    {
        if ($visibleIds === []) {
            return [];
        }

        return $this->searchQuery($filters, $visibleIds, $sort)
            ->setFirstResult(max(0, $page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{term?: ?string, isActive?: ?int} $filters
     * @param int[]|null                             $visibleIds
     */
    public function countSearch(array $filters, ?array $visibleIds): int
    {
        if ($visibleIds === []) {
            return 0;
        }

        return (int) $this->searchQuery($filters, $visibleIds, null)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Clients for a picker (project form, task filters): live ones only, by name.
     *
     * @param int[]|null $visibleIds
     *
     * @return Client[]
     */
    public function findSelectable(?array $visibleIds): array
    {
        if ($visibleIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.isDeleted = 0')
            ->orderBy('c.name', 'ASC');
        if ($visibleIds !== null) {
            $qb->andWhere('c.id IN (:ids)')->setParameter('ids', $visibleIds);
        }

        return $qb->getQuery()->getResult();
    }

    /** Case-insensitive under utf8mb4_unicode_ci, which is what "already exists" should mean for a name/email. */
    public function fieldValueExists(string $field, string $value, ?int $excludeId): bool
    {
        if (!in_array($field, ['name', 'email'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported field "%s".', $field));
        }

        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere("c.$field = :value")
            ->setParameter('value', $value);
        if ($excludeId !== null) {
            $qb->andWhere('c.id <> :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function clientCodeExists(string $code, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.clientCode = :code')
            ->setParameter('code', $code);
        if ($excludeId !== null) {
            $qb->andWhere('c.id <> :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @param array{term?: ?string, isActive?: ?int} $filters
     * @param int[]|null                             $visibleIds
     */
    private function searchQuery(array $filters, ?array $visibleIds, ?string $sort): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')->andWhere('c.isDeleted = 0');

        if ($visibleIds !== null) {
            $qb->andWhere('c.id IN (:ids)')->setParameter('ids', $visibleIds);
        }
        if (($filters['term'] ?? null) !== null) {
            $qb->andWhere('c.name LIKE :term OR c.email LIKE :term OR c.clientCode LIKE :term')
                ->setParameter('term', '%'.addcslashes($filters['term'], '%_').'%');
        }
        if (($filters['isActive'] ?? null) !== null) {
            $qb->andWhere('c.isActive = :active')->setParameter('active', $filters['isActive']);
        }

        [$column, $direction] = SortParam::resolve($sort, self::SORTABLE_COLUMNS, 'name');

        return $qb->orderBy($column, $direction)->addOrderBy('c.id', 'ASC');
    }
}
