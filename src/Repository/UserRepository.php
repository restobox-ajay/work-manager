<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /** Active accounts that hold the given role themselves (not merely through the role hierarchy). */
    public function countActiveWithRole(Role $role): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.status = :status')
            ->setParameter('status', AccountStatus::Active->value);
        $this->whereHoldsRole($qb, $role->value, 'held_role');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Active accounts by name — the people a client manager, project staff member, contractor or reviewer
     * can be picked from (ADR-070).
     *
     * @return User[]
     */
    public function findActiveOrderedByName(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.status = :active')
            ->setParameter('active', AccountStatus::Active->value)
            ->orderBy('u.name', 'ASC')
            ->addOrderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPaginated(int $page, int $limit): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count users matching the given filters (FEATURE-117).
     *
     * @param array{email?: string, status?: string, excluded_roles?: list<string>} $filters
     */
    public function countFiltered(array $filters): int
    {
        $qb = $this->createQueryBuilder('u')->select('COUNT(u.id)');
        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Page through users matching the given filters, newest first (FEATURE-117).
     *
     * @param array{email?: string, status?: string, excluded_roles?: list<string>} $filters
     *
     * @return list<User>
     */
    public function findFilteredPaginated(array $filters, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);
        $this->applyFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array{email?: string, status?: string, excluded_roles?: list<string>} $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['email']) && $filters['email'] !== '') {
            $qb->andWhere('LOWER(u.email) LIKE :email')
                ->setParameter('email', '%' . strtolower($filters['email']) . '%');
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $qb->andWhere('u.status = :status')
                ->setParameter('status', $filters['status']);
        }

        // Accounts the viewer may not see (App\Security\AccountManagementPolicy::hiddenRoles()) are left out of
        // the query itself, so paging and totals stay right.
        foreach (array_values($filters['excluded_roles'] ?? []) as $i => $role) {
            $qb->andWhere(sprintf('u.roles NOT LIKE :excluded_role_%d', $i))
                ->setParameter(sprintf('excluded_role_%d', $i), self::roleNeedle($role));
        }
    }

    private function whereHoldsRole(QueryBuilder $qb, string $role, string $parameter): void
    {
        $qb->andWhere(sprintf('u.roles LIKE :%s', $parameter))->setParameter($parameter, self::roleNeedle($role));
    }

    /**
     * `roles` is a JSON array of role strings; matching the quoted string keeps ROLE_ADMIN from also matching
     * ROLE_SUPER_ADMIN.
     */
    private static function roleNeedle(string $role): string
    {
        return '%"' . addcslashes($role, '%_') . '"%';
    }
}
