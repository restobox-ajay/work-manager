<?php

declare(strict_types=1);

namespace App\Repository\Subscription;

use App\Entity\Subscription\SubscriptionCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionCategory>
 */
class SubscriptionCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionCategory::class);
    }

    /** @return SubscriptionCategory[] in display order */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    /** @return SubscriptionCategory[] the ones offered on the form; $keepId stays listed even if switched off */
    public function findSelectable(?int $keepId = null): array
    {
        return array_values(array_filter($this->findAllOrdered(), static fn (SubscriptionCategory $c) => $c->isActive() || $c->getId() === $keepId));
    }
}
