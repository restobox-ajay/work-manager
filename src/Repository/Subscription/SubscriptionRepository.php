<?php

declare(strict_types=1);

namespace App\Repository\Subscription;

use App\Entity\Subscription\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * Subscriptions with their category, soonest renewal first (no renewal date last), optionally filtered.
     *
     * @return Subscription[]
     */
    public function findFiltered(?string $status, ?int $categoryId): array
    {
        $qb = $this->createQueryBuilder('s')->addSelect('c')->leftJoin('s.category', 'c')
            ->addSelect('CASE WHEN s.nextRenewal IS NULL THEN 1 ELSE 0 END AS HIDDEN noDate')
            ->orderBy('noDate', 'ASC')->addOrderBy('s.nextRenewal', 'ASC')->addOrderBy('s.name', 'ASC');
        if ($status !== null) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }
        if ($categoryId !== null) {
            $qb->andWhere('c.id = :category')->setParameter('category', $categoryId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Active subscriptions renewing on or before $until (overdue ones included), soonest first.
     *
     * @return Subscription[]
     */
    public function findRenewingBy(\DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('s')->addSelect('c')->leftJoin('s.category', 'c')
            ->where('s.status = :active')->andWhere('s.nextRenewal IS NOT NULL')->andWhere('s.nextRenewal <= :until')
            ->setParameter('active', Subscription::STATUS_ACTIVE)->setParameter('until', $until, 'date_immutable')
            ->orderBy('s.nextRenewal', 'ASC')->addOrderBy('s.name', 'ASC')
            ->getQuery()->getResult();
    }
}
