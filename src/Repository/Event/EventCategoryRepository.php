<?php

declare(strict_types=1);

namespace App\Repository\Event;

use App\Entity\Event\EventCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventCategory>
 */
class EventCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventCategory::class);
    }

    /** @return EventCategory[] in display order */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    /** @return EventCategory[] the ones offered on the form; $keepId stays listed even if switched off */
    public function findSelectable(?int $keepId = null): array
    {
        return array_values(array_filter($this->findAllOrdered(), static fn (EventCategory $c) => $c->isActive() || $c->getId() === $keepId));
    }
}
