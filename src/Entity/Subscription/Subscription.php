<?php

declare(strict_types=1);

namespace App\Entity\Subscription;

use App\Enum\BillingCycle;
use App\Repository\Subscription\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A paid app or service (ADR-091) — Claude, PhpStorm, Microsoft 365, Windows…: what it costs, how often, when it next
 * renews, which account and seats it covers and how it is paid. Renewal payments are SubscriptionPayment rows.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\Index(name: 'idx_subscription_next_renewal', columns: ['next_renewal'])]
class Subscription
{
    public const STATUSES = ['active' => 'Active', 'paused' => 'Paused', 'cancelled' => 'Cancelled'];
    public const STATUS_ACTIVE = 'active';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $vendor = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $plan = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'INR';

    #[ORM\Column(length: 20)]
    private string $billingCycle = 'monthly';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextRenewal = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $autoRenew = true;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $accountEmail = null;


    #[ORM\Column(nullable: true)]
    private ?int $seats = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $assignedTo = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $paymentMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $websiteUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $billingUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: SubscriptionCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'SET NULL')]
    private ?SubscriptionCategory $category = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getVendor(): ?string
    {
        return $this->vendor;
    }

    public function setVendor(?string $vendor): static
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function getPlan(): ?string
    {
        return $this->plan;
    }

    public function setPlan(?string $plan): static
    {
        $this->plan = $plan;

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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getBillingCycle(): string
    {
        return $this->billingCycle;
    }

    public function setBillingCycle(string $billingCycle): static
    {
        $this->billingCycle = $billingCycle;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getNextRenewal(): ?\DateTimeImmutable
    {
        return $this->nextRenewal;
    }

    public function setNextRenewal(?\DateTimeImmutable $nextRenewal): static
    {
        $this->nextRenewal = $nextRenewal;

        return $this;
    }

    public function isAutoRenew(): bool
    {
        return $this->autoRenew;
    }

    public function setAutoRenew(bool $autoRenew): static
    {
        $this->autoRenew = $autoRenew;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAccountEmail(): ?string
    {
        return $this->accountEmail;
    }

    public function setAccountEmail(?string $accountEmail): static
    {
        $this->accountEmail = $accountEmail;

        return $this;
    }

    public function getSeats(): ?int
    {
        return $this->seats;
    }

    public function setSeats(?int $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    public function getAssignedTo(): ?string
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?string $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;

        return $this;
    }

    public function getBillingUrl(): ?string
    {
        return $this->billingUrl;
    }

    public function setBillingUrl(?string $billingUrl): static
    {
        $this->billingUrl = $billingUrl;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

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

    public function getCategory(): ?SubscriptionCategory
    {
        return $this->category;
    }

    public function setCategory(?SubscriptionCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function cycle(): BillingCycle
    {
        return BillingCycle::tryFrom($this->billingCycle) ?? BillingCycle::Monthly;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
