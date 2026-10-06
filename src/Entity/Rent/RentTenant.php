<?php

declare(strict_types=1);

namespace App\Entity\Rent;

use App\Repository\Rent\RentTenantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tenancy (ADR-085): who rents which property, for how much a month, the deposit held, and the electricity meter
 * reading when they moved in (the first bill's previous reading).
 */
#[ORM\Entity(repositoryClass: RentTenantRepository::class)]
#[ORM\Table(name: 'rent_tenant')]
class RentTenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $monthlyRent = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $deposit = '0.00';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $openingMeter = '0.00';

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: RentProperty::class)]
    #[ORM\JoinColumn(name: 'property_id', nullable: false)]
    private ?RentProperty $property = null;

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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getMonthlyRent(): string
    {
        return $this->monthlyRent;
    }

    public function setMonthlyRent(string $monthlyRent): static
    {
        $this->monthlyRent = $monthlyRent;

        return $this;
    }

    public function getDeposit(): string
    {
        return $this->deposit;
    }

    public function setDeposit(string $deposit): static
    {
        $this->deposit = $deposit;

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

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getOpeningMeter(): string
    {
        return $this->openingMeter;
    }

    public function setOpeningMeter(string $openingMeter): static
    {
        $this->openingMeter = $openingMeter;

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

    public function getProperty(): ?RentProperty
    {
        return $this->property;
    }

    public function setProperty(RentProperty $property): static
    {
        $this->property = $property;

        return $this;
    }
}
