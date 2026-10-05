<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use App\Repository\Settings\TaxRateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tax rate (Settings › Tax Rates, ADR-078), as in the Maxeme Auto settings: a code, a name and a rate. An invoice line's
 * GST % is chosen from the active ones; the line keeps the rate it was saved with.
 */
#[ORM\Entity(repositoryClass: TaxRateRepository::class)]
#[ORM\Table(name: 'tax_rate')]
class TaxRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 60)]
    private string $name = '';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $rate = '0.00';

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
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

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): static
    {
        $this->rate = $rate;

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

    public function getUpdatedAt(): ?int
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?int $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

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
}
