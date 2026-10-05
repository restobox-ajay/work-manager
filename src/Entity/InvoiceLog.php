<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvoiceLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of an invoice's own history (ADR-077): created, updated, PDF downloaded, emailed, cancelled — who and when.
 * Written only by InvoiceHistory; rows are never changed.
 */
#[ORM\Entity(repositoryClass: InvoiceLogRepository::class)]
#[ORM\Table(name: 'invoice_log')]
#[ORM\Index(name: 'idx_invoice_log_invoice', columns: ['invoice_id'])]
class InvoiceLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $invoiceId = 0;

    #[ORM\Column(length: 30)]
    private string $action = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $detail = null;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column]
    private int $createdAt = 0;

    public function __construct(int $invoiceId, string $action, ?string $detail, ?int $userId, int $createdAt)
    {
        $this->invoiceId = $invoiceId;
        $this->action = $action;
        $this->detail = $detail;
        $this->userId = $userId;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoiceId(): int
    {
        return $this->invoiceId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }
}
