<?php

declare(strict_types=1);

namespace App\Entity\Expense;

use App\Repository\Expense\ExpenseCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What an expense was for (ADR-089): maintained under Config › Expense Categories.
 */
#[ORM\Entity(repositoryClass: ExpenseCategoryRepository::class)]
#[ORM\Table(name: 'expense_category')]
class ExpenseCategory
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
