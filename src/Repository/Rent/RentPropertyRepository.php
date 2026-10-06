<?php

declare(strict_types=1);

namespace App\Repository\Rent;

use App\Entity\Rent\RentProperty;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RentProperty>
 */
class RentPropertyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentProperty::class);
    }

    /** @return RentProperty[] by name; switched-off ones last */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['isActive' => 'DESC', 'name' => 'ASC']);
    }
}
