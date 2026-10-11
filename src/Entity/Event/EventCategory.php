<?php

declare(strict_types=1);

namespace App\Entity\Event;

use App\Repository\Event\EventCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What kind of event an Events entry is (ADR-126): Birth, Death, Anniversary… maintained under Config › Event
 * Categories. "Repeats yearly" is the category's suggestion for new events (birthdays), not a rule.
 */
#[ORM\Entity(repositoryClass: EventCategoryRepository::class)]
#[ORM\Table(name: 'event_category')]
class EventCategory
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
