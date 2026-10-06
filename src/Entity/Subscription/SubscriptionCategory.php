<?php

declare(strict_types=1);

namespace App\Entity\Subscription;

use App\Repository\Subscription\SubscriptionCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What kind of app or service a subscription is (ADR-091): maintained under Config › Subscription Categories.
 */
#[ORM\Entity(repositoryClass: SubscriptionCategoryRepository::class)]
#[ORM\Table(name: 'subscription_category')]
class SubscriptionCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $name = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}
