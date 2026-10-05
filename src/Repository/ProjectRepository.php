<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Projects (the work-platform `project` table, ADR-070). A project removed from the list (is_deleted = 1) is never
 * listed; one merely archived (status = 0) is, and the Status filter narrows to either.
 *
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    private const SORTABLE_COLUMNS = [
        'name'      => 'p.name',
        'client'    => 'c.name',
        'status'    => 'p.status',
        'createdAt' => 'p.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * @param array{term?: ?string, clientId?: ?int, status?: ?int} $filters
     * @param int[]|null                                            $visibleIds null = every project
     *
     * @return Project[]
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
     * @param array{term?: ?string, clientId?: ?int, status?: ?int} $filters
     * @param int[]|null                                            $visibleIds
     */
    public function countSearch(array $filters, ?array $visibleIds): int
    {
        if ($visibleIds === []) {
            return 0;
        }

        return (int) $this->searchQuery($filters, $visibleIds, null)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Projects for a picker (task form, task filters), grouped by client name.
     *
     * @param int[]|null $visibleIds
     *
     * @return Project[]
     */
    public function findSelectable(?array $visibleIds, ?int $clientId = null): array
    {
        if ($visibleIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.client', 'c')
            ->andWhere('p.isDeleted = 0')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('p.name', 'ASC');
        if ($visibleIds !== null) {
            $qb->andWhere('p.id IN (:ids)')->setParameter('ids', $visibleIds);
        }
        if ($clientId !== null) {
            $qb->andWhere('c.id = :clientId')->setParameter('clientId', $clientId);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Project[] the client's listed projects, for the client page */
    public function findForClient(int $clientId): array
    {
        return $this->findSelectable(null, $clientId);
    }

    /**
     * Project counts for many clients at once (client list page).
     *
     * @param int[] $clientIds
     *
     * @return array<int, int> client id => listed project count
     */
    public function countByClientIds(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT client_id, COUNT(*) AS n FROM project WHERE is_deleted = 0 AND client_id IN (?) GROUP BY client_id',
            [$clientIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['client_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return int[] ids of every project of the given clients (removed ones too: access follows the client, not the list) */
    public function findIdsByClientIds(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM project WHERE client_id IN (?)',
            [$clientIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @param array{term?: ?string, clientId?: ?int, status?: ?int} $filters
     * @param int[]|null                                            $visibleIds
     */
    private function searchQuery(array $filters, ?array $visibleIds, ?string $sort): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.client', 'c')
            ->andWhere('p.isDeleted = 0');

        if ($visibleIds !== null) {
            $qb->andWhere('p.id IN (:ids)')->setParameter('ids', $visibleIds);
        }
        if (($filters['clientId'] ?? null) !== null) {
            $qb->andWhere('c.id = :clientId')->setParameter('clientId', $filters['clientId']);
        }
        if (($filters['status'] ?? null) !== null) {
            $qb->andWhere('p.status = :status')->setParameter('status', $filters['status']);
        }
        if (($filters['term'] ?? null) !== null) {
            $qb->andWhere('p.name LIKE :term OR c.name LIKE :term')
                ->setParameter('term', '%'.addcslashes($filters['term'], '%_').'%');
        }

        [$column, $direction] = SortParam::resolve($sort, self::SORTABLE_COLUMNS, 'name');

        return $qb->orderBy($column, $direction)->addOrderBy('p.id', 'ASC');
    }
}
