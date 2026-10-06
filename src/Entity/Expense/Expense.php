<?php

declare(strict_types=1);

namespace App\Entity\Expense;

use App\Entity\Rent\RentProperty;
use App\Repository\Expense\ExpenseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money spent (ADR-089): when, how much, on what category (optional), how it was paid, and optionally for which rent property.
 */
#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
#[ORM\Table(name: 'expense')]
#[ORM\Index(name: 'idx_expense_spent_on', columns: ['spent_on'])]
class Expense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $spentOn = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 20)]
    private string $method = 'cash';

    #[ORM\Column(length: 255)]
    private string $description = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** Optional reference for how it was paid: UPI id / transaction no., cheque no. and bank… (ADR-097). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentDetails = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: ExpenseCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true)]
    private ?ExpenseCategory $category = null;

    #[ORM\ManyToOne(targetEntity: RentProperty::class)]
    #[ORM\JoinColumn(name: 'property_id', nullable: true, onDelete: 'SET NULL')]
    private ?RentProperty $property = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSpentOn(): ?\DateTimeImmutable
    {
        return $this->spentOn;
    }

    public function setSpentOn(?\DateTimeImmutable $spentOn): static
    {
        $this->spentOn = $spentOn;

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

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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

    public function getPaymentDetails(): ?string
    {
        return $this->paymentDetails;
    }

    public function setPaymentDetails(?string $paymentDetails): static
    {
        $this->paymentDetails = $paymentDetails;

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

    public function getUpdatedAt(): ?int
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?int $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getCategory(): ?ExpenseCategory
    {
        return $this->category;
    }

    public function setCategory(?ExpenseCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getProperty(): ?RentProperty
    {
        return $this->property;
    }

    public function setProperty(?RentProperty $property): static
    {
        $this->property = $property;

        return $this;
    }
}
