<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InvoiceKind;
use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An invoice (ADR-077): independent (lines typed by hand) or for approved tasks (lines made from tasks).
 * The From and To blocks are copied onto the invoice when it is saved, so editing a billing profile or a client
 * later never changes an invoice already sent.
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $number = '';

    #[ORM\Column(length: 20, enumType: InvoiceKind::class)]
    private InvoiceKind $kind = InvoiceKind::Independent;

    #[ORM\Column(length: 20, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status = InvoiceStatus::Issued;

    #[ORM\Column(nullable: true)]
    private ?int $billingProfileId = null;

    #[ORM\Column(nullable: true)]
    private ?int $clientId = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $invoiceDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(length: 10)]
    private string $currency = 'USD';

    #[ORM\Column(length: 255)]
    private string $fromName = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $fromAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromEmail = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $fromPhone = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $fromTaxNumber = null;

    #[ORM\Column(length: 255)]
    private string $toName = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $toAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toEmail = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $toPhone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $cgstTotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $sgstTotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(nullable: true)]
    private ?int $emailedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $cancelledAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    /** @var Collection<int, InvoiceItem> */
    #[ORM\OneToMany(targetEntity: InvoiceItem::class, mappedBy: 'invoice', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getKind(): InvoiceKind
    {
        return $this->kind;
    }

    public function setKind(InvoiceKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    public function setStatus(InvoiceStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getBillingProfileId(): ?int
    {
        return $this->billingProfileId;
    }

    public function setBillingProfileId(?int $billingProfileId): static
    {
        $this->billingProfileId = $billingProfileId;

        return $this;
    }

    public function getClientId(): ?int
    {
        return $this->clientId;
    }

    public function setClientId(?int $clientId): static
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getInvoiceDate(): ?\DateTimeImmutable
    {
        return $this->invoiceDate;
    }

    public function setInvoiceDate(?\DateTimeImmutable $invoiceDate): static
    {
        $this->invoiceDate = $invoiceDate;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function setFromName(string $fromName): static
    {
        $this->fromName = $fromName;

        return $this;
    }

    public function getFromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(?string $fromAddress): static
    {
        $this->fromAddress = $fromAddress;

        return $this;
    }

    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(?string $fromEmail): static
    {
        $this->fromEmail = $fromEmail;

        return $this;
    }

    public function getFromPhone(): ?string
    {
        return $this->fromPhone;
    }

    public function setFromPhone(?string $fromPhone): static
    {
        $this->fromPhone = $fromPhone;

        return $this;
    }

    public function getFromTaxNumber(): ?string
    {
        return $this->fromTaxNumber;
    }

    public function setFromTaxNumber(?string $fromTaxNumber): static
    {
        $this->fromTaxNumber = $fromTaxNumber;

        return $this;
    }

    public function getToName(): string
    {
        return $this->toName;
    }

    public function setToName(string $toName): static
    {
        $this->toName = $toName;

        return $this;
    }

    public function getToAddress(): ?string
    {
        return $this->toAddress;
    }

    public function setToAddress(?string $toAddress): static
    {
        $this->toAddress = $toAddress;

        return $this;
    }

    public function getToEmail(): ?string
    {
        return $this->toEmail;
    }

    public function setToEmail(?string $toEmail): static
    {
        $this->toEmail = $toEmail;

        return $this;
    }

    public function getToPhone(): ?string
    {
        return $this->toPhone;
    }

    public function setToPhone(?string $toPhone): static
    {
        $this->toPhone = $toPhone;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSubtotal(): string
    {
        return $this->subtotal;
    }

    public function setSubtotal(string $subtotal): static
    {
        $this->subtotal = $subtotal;

        return $this;
    }

    public function getCgstTotal(): string
    {
        return $this->cgstTotal;
    }

    public function setCgstTotal(string $cgstTotal): static
    {
        $this->cgstTotal = $cgstTotal;

        return $this;
    }

    public function getSgstTotal(): string
    {
        return $this->sgstTotal;
    }

    public function setSgstTotal(string $sgstTotal): static
    {
        $this->sgstTotal = $sgstTotal;

        return $this;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getEmailedAt(): ?int
    {
        return $this->emailedAt;
    }

    public function setEmailedAt(?int $emailedAt): static
    {
        $this->emailedAt = $emailedAt;

        return $this;
    }

    public function getCancelledAt(): ?int
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?int $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): ?int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?int $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?int $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getUpdatedAt(): ?int
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?int $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /** @return Collection<int, InvoiceItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** Replaces every line (an edit re-posts them all). */
    public function replaceItems(InvoiceItem ...$items): static
    {
        $this->items->clear();
        foreach (array_values($items) as $position => $item) {
            $this->items->add($item->setInvoice($this)->setPosition($position + 1));
        }

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    /** Emailed to the client at least once (ADR-097): a sent invoice is a record the client holds and is never deleted. */
    public function isSent(): bool
    {
        return $this->emailedAt !== null;
    }

    /** @return int[] the tasks this invoice bills */
    public function taskIds(): array
    {
        return array_values(array_filter(array_map(static fn (InvoiceItem $item) => $item->getTaskId(), $this->items->toArray())));
    }
}
