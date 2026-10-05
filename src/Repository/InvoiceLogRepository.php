<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvoiceLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceLog>
 */
class InvoiceLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceLog::class);
    }

    /** @return InvoiceLog[] newest first */
    public function findForInvoice(int $invoiceId): array
    {
        return $this->findBy(['invoiceId' => $invoiceId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
