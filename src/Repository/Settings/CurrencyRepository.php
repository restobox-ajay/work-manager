<?php

declare(strict_types=1);

namespace App\Repository\Settings;

use App\Entity\Settings\Currency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Currency>
 */
class CurrencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Currency::class);
    }

    /** @return Currency[] every row, in display order */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.shortName', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();
    }
}
