<?php

declare(strict_types=1);

namespace App\Entity\Subscription;

use App\Repository\Subscription\SubscriptionPaymentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One payment made for a subscription (ADR-091): its renewal history. The currency is copied from the subscription
 * at the time, so history stays right if the subscription later changes currency.
 */
#[ORM\Entity(repositoryClass: SubscriptionPaymentRepository::class)]
#[ORM\Table(name: 'subscription_payment')]
class SubscriptionPayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(name: 'subscription_id', nullable: false, onDelete: 'CASCADE')]
    private ?Subscription $subscription = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $paidOn = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'INR';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(Subscription $subscription): static
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getPaidOn(): ?\DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function setPaidOn(\DateTimeImmutable $paidOn): static
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

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
}
