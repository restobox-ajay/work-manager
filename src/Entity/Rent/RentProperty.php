<?php

declare(strict_types=1);

namespace App\Entity\Rent;

use App\Repository\Rent\RentPropertyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A house or flat that is let (ADR-085): its name and address, the electricity rate per meter unit, and the usual
 * monthly rent offered to a new tenant.
 */
#[ORM\Entity(repositoryClass: RentPropertyRepository::class)]
#[ORM\Table(name: 'rent_property')]
class RentProperty
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $electricityRate = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $defaultRent = '0.00';

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getElectricityRate(): string
    {
        return $this->electricityRate;
    }

    public function setElectricityRate(string $electricityRate): static
    {
        $this->electricityRate = $electricityRate;

        return $this;
    }

    public function getDefaultRent(): string
    {
        return $this->defaultRent;
    }

    public function setDefaultRent(string $defaultRent): static
    {
        $this->defaultRent = $defaultRent;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

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
}
