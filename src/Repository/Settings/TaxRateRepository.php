<?php

declare(strict_types=1);

namespace App\Repository\Settings;

use App\Entity\Settings\TaxRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaxRate>
 */
class TaxRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxRate::class);
    }

    /** @return TaxRate[] every rate, in display order */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'rate' => 'ASC', 'id' => 'ASC']);
    }

    /** @return TaxRate[] the rates offered on invoice lines */
    public function findActiveOrdered(): array
    {
        return $this->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'rate' => 'ASC', 'id' => 'ASC']);
    }
}
