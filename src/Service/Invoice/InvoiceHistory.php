<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\Invoice;
use App\Entity\InvoiceLog;
use App\Entity\User;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The invoice audit log (ADR-077): every action on an invoice goes to its own history (shown on the invoice) and
 * to the app-wide audit log as "invoice.<action>".
 */
final class InvoiceHistory
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const PDF = 'pdf_downloaded';
    public const EMAILED = 'emailed';
    public const EMAIL_FAILED = 'email_failed';
    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::CREATED      => 'Created',
        self::UPDATED      => 'Updated',
        self::PDF          => 'PDF downloaded',
        self::EMAILED      => 'Emailed',
        self::EMAIL_FAILED => 'Email failed',
        self::CANCELLED    => 'Cancelled',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    public function record(Invoice $invoice, string $action, ?string $detail, User $actor): void
    {
        $this->em->persist(new InvoiceLog((int) $invoice->getId(), $action, $detail, $actor->getId(), time()));
        $this->em->flush();

        $this->audit->record($actor, 'invoice.'.$action, trim(sprintf('#%d %s %s', (int) $invoice->getId(), $invoice->getNumber(), (string) $detail)));
    }
}
