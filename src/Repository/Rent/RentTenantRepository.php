<?php

declare(strict_types=1);

namespace App\Repository\Rent;

use App\Entity\Rent\RentTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RentTenant>
 */
class RentTenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentTenant::class);
    }

    /** @return RentTenant[] active first, then by property and name */
    public function findAllOrdered(?bool $active = null): array
    {
        $qb = $this->createQueryBuilder('t')->addSelect('p')->join('t.property', 'p')
            ->orderBy('t.isActive', 'DESC')->addOrderBy('p.name', 'ASC')->addOrderBy('t.name', 'ASC');
        if ($active !== null) {
            $qb->andWhere('t.isActive = :active')->setParameter('active', $active);
        }

        return $qb->getQuery()->getResult();
    }
}
