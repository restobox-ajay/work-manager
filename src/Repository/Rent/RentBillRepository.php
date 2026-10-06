<?php

declare(strict_types=1);

namespace App\Repository\Rent;

use App\Entity\Rent\RentBill;
use App\Entity\Rent\RentTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RentBill>
 */
class RentBillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentBill::class);
    }

    /** @return RentBill[] oldest first */
    public function findForTenant(RentTenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['period' => 'ASC']);
    }

    public function findOneForPeriod(RentTenant $tenant, \DateTimeImmutable $period): ?RentBill
    {
        return $this->findOneBy(['tenant' => $tenant, 'period' => $period]);
    }

    /** The latest bill before $period (its current reading is the next bill's previous reading). */
    public function findPreviousBill(RentTenant $tenant, \DateTimeImmutable $period): ?RentBill
    {
        return $this->createQueryBuilder('b')
            ->where('b.tenant = :tenant AND b.period < :period')
            ->setParameter('tenant', $tenant)->setParameter('period', $period, 'date_immutable')
            ->orderBy('b.period', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return RentBill[] every bill, for the summaries */
    public function findAllWithTenants(): array
    {
        return $this->createQueryBuilder('b')->addSelect('t')->join('b.tenant', 't')->orderBy('b.period', 'ASC')->getQuery()->getResult();
    }
}
