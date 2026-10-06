<?php

declare(strict_types=1);

namespace App\Entity\Rent;

use App\Repository\Rent\RentPaymentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money received from a tenant (ADR-085), marked as paying rent, electricity or other charges.
 */
#[ORM\Entity(repositoryClass: RentPaymentRepository::class)]
#[ORM\Table(name: 'rent_payment')]
#[ORM\Index(name: 'idx_rent_payment_tenant_paid', columns: ['tenant_id', 'paid_on'])]
class RentPayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidOn = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 20)]
    private string $kind = 'rent';

    #[ORM\Column(length: 20)]
    private string $method = 'cash';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\ManyToOne(targetEntity: RentTenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
    private ?RentTenant $tenant = null;

    /** Set when the payment was made with "Mark paid" for one month's bill (ADR-088): it pays that bill first. */
    #[ORM\ManyToOne(targetEntity: RentBill::class)]
    #[ORM\JoinColumn(name: 'bill_id', nullable: true, onDelete: 'SET NULL')]
    private ?RentBill $bill = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPaidOn(): ?\DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function setPaidOn(?\DateTimeImmutable $paidOn): static
    {
        $this->paidOn = $paidOn;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

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

    public function getTenant(): ?RentTenant
    {
        return $this->tenant;
    }

    public function setTenant(RentTenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getBill(): ?RentBill
    {
        return $this->bill;
    }

    public function setBill(?RentBill $bill): static
    {
        $this->bill = $bill;

        return $this;
    }
}
