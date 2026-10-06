<?php

declare(strict_types=1);

namespace App\Repository\Rent;

use App\Entity\Rent\RentPayment;
use App\Entity\Rent\RentTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RentPayment>
 */
class RentPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentPayment::class);
    }

    /** @return RentPayment[] oldest first */
    public function findForTenant(RentTenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['paidOn' => 'ASC', 'id' => 'ASC']);
    }

    /** @return RentPayment[] every payment, for the summaries */
    public function findAllWithTenants(): array
    {
        return $this->createQueryBuilder('p')->addSelect('t')->join('p.tenant', 't')->orderBy('p.paidOn', 'ASC')->getQuery()->getResult();
    }
}
