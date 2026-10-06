<?php

declare(strict_types=1);

namespace App\Repository\Expense;

use App\Entity\Expense\Expense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Expense>
 */
class ExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    /**
     * Expenses dated in [$from, $to), oldest first, optionally of one category.
     *
     * @return Expense[]
     */
    public function findBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $categoryId = null): array
    {
        $qb = $this->createQueryBuilder('e')->addSelect('c')->leftJoin('e.category', 'c')
            ->where('e.spentOn >= :from AND e.spentOn < :to')
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->orderBy('e.spentOn', 'ASC')->addOrderBy('e.id', 'ASC');
        if ($categoryId !== null) {
            $qb->andWhere('c.id = :category')->setParameter('category', $categoryId);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return int[] years with expenses, newest first */
    public function findYears(): array
    {
        $years = [];
        foreach ($this->createQueryBuilder('e')->select('e.spentOn AS d')->getQuery()->getArrayResult() as $row) {
            if ($row['d'] instanceof \DateTimeInterface) {
                $years[(int) $row['d']->format('Y')] = true;
            }
        }
        krsort($years);

        return array_keys($years);
    }
}
