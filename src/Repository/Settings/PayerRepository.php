<?php

declare(strict_types=1);

namespace App\Repository\Settings;

use App\Entity\Settings\Payer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payer>
 */
class PayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payer::class);
    }

    /**
     * Maps the grid's sortable column keys (?sort=) to their queryable
     * expression.
     */
    private const SORTABLE_COLUMNS = [
        'companyName' => 'p.companyName',
        'contactName' => 'p.contactName',
        'email' => 'p.email',
        'type' => 'p.type',
        'status' => 'p.status',
    ];

    /**
     * Backs the Payer list page's own filter row -- company/contact/email
     * are partial (LIKE) matches, type/status are exact, mirroring Yii2's
     * PayerSearch::search() (never actually reachable there since its
     * GridView has 'filterModel' commented out -- this is the first place
     * these filters are live).
     *
     * @return Payer[]
     */
    public function search(?string $companyName, ?string $contactName, ?string $email, ?string $type, ?bool $status, int $page = 1, int $pageSize = 20, ?string $sort = null): array
    {
        $qb = $this->buildSearchQuery($companyName, $contactName, $email, $type, $status, $sort)
            ->setFirstResult(max(0, $page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return $qb->getQuery()->getResult();
    }

    public function countSearch(?string $companyName, ?string $contactName, ?string $email, ?string $type, ?bool $status): int
    {
        $qb = $this->buildSearchQuery($companyName, $contactName, $email, $type, $status, null)
            ->select('COUNT(p.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function buildSearchQuery(?string $companyName, ?string $contactName, ?string $email, ?string $type, ?bool $status, ?string $sort): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p');

        $sortField = ltrim((string) $sort, '-');
        $sortDirection = str_starts_with((string) $sort, '-') ? 'DESC' : 'ASC';
        if ($sort !== null && $sort !== '' && isset(self::SORTABLE_COLUMNS[$sortField])) {
            $qb->orderBy(self::SORTABLE_COLUMNS[$sortField], $sortDirection);
        } else {
            $qb->orderBy('p.companyName', 'ASC');
        }

        if ($companyName !== null && $companyName !== '') {
            $qb->andWhere('p.companyName LIKE :companyName')->setParameter('companyName', '%'.$companyName.'%');
        }

        if ($contactName !== null && $contactName !== '') {
            $qb->andWhere('p.contactName LIKE :contactName')->setParameter('contactName', '%'.$contactName.'%');
        }

        if ($email !== null && $email !== '') {
            $qb->andWhere('p.email LIKE :email')->setParameter('email', '%'.$email.'%');
        }

        if ($type !== null && $type !== '') {
            $qb->andWhere('p.type = :type')->setParameter('type', $type);
        }

        if ($status !== null) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }

    /** @return Payer[] every row, in display order */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.companyName', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();
    }
}
