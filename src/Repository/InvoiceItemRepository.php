<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvoiceItem;
use App\Enum\InvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceItem>
 */
class InvoiceItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceItem::class);
    }

    /**
     * Which of these tasks are already on an invoice that is not cancelled — the double-billing check (ADR-077).
     *
     * @param int[] $taskIds
     *
     * @return array<int, string> task id => invoice number
     */
    public function findLiveInvoiceNumbersForTasks(array $taskIds, ?int $exceptInvoiceId = null): array
    {
        if ($taskIds === []) {
            return [];
        }
        $qb = $this->createQueryBuilder('item')
            ->select('item.taskId AS taskId', 'invoice.number AS number')
            ->join('item.invoice', 'invoice')
            ->where('item.taskId IN (:taskIds)')
            ->andWhere('invoice.status <> :cancelled')
            ->setParameter('taskIds', $taskIds)
            ->setParameter('cancelled', InvoiceStatus::Cancelled);
        if ($exceptInvoiceId !== null) {
            $qb->andWhere('invoice.id <> :except')->setParameter('except', $exceptInvoiceId);
        }

        $numbers = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $numbers[(int) $row['taskId']] = (string) $row['number'];
        }

        return $numbers;
    }
}
