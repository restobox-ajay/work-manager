<?php

declare(strict_types=1);

namespace App\Repository\Settings;

use App\Entity\Settings\BillingProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingProfile>
 */
class BillingProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingProfile::class);
    }

    /** @return BillingProfile[] the profiles an invoice can be from; $keepId stays listed even if switched off */
    public function findSelectable(?int $keepId = null): array
    {
        $qb = $this->createQueryBuilder('p')->where('p.isActive = true')->orderBy('p.name', 'ASC');
        if ($keepId !== null) {
            $qb->orWhere('p.id = :keep')->setParameter('keep', $keepId);
        }

        return $qb->getQuery()->getResult();
    }
}
