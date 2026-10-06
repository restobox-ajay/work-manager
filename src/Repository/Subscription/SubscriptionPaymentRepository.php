<?php

declare(strict_types=1);

namespace App\Repository\Subscription;

use App\Entity\Subscription\Subscription;
use App\Entity\Subscription\SubscriptionPayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionPayment>
 */
class SubscriptionPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionPayment::class);
    }

    /** @return SubscriptionPayment[] newest first */
    public function findForSubscription(Subscription $subscription): array
    {
        return $this->findBy(['subscription' => $subscription], ['paidOn' => 'DESC', 'id' => 'DESC']);
    }
}
