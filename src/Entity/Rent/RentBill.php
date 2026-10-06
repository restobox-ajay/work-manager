<?php

declare(strict_types=1);

namespace App\Entity\Rent;

use App\Repository\Rent\RentBillRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One month's charges for a tenancy (ADR-085): rent, electricity (meter units used × rate, stored as billed) and any
 * other charge. One bill per tenancy per month (period = the month's first day).
 */
#[ORM\Entity(repositoryClass: RentBillRepository::class)]
#[ORM\Table(name: 'rent_bill')]
#[ORM\UniqueConstraint(name: 'uniq_rent_bill_tenant_period', columns: ['tenant_id', 'period'])]
class RentBill
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $period = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $rentAmount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $meterPrevious = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $meterCurrent = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $units = '0.00';

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $rate = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $electricityAmount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $otherAmount = '0.00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $otherNote = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: RentTenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
    private ?RentTenant $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriod(): ?\DateTimeImmutable
    {
        return $this->period;
    }

    public function setPeriod(?\DateTimeImmutable $period): static
    {
        $this->period = $period;

        return $this;
    }

    public function getRentAmount(): string
    {
        return $this->rentAmount;
    }

    public function setRentAmount(string $rentAmount): static
    {
        $this->rentAmount = $rentAmount;

        return $this;
    }

    public function getMeterPrevious(): string
    {
        return $this->meterPrevious;
    }

    public function setMeterPrevious(string $meterPrevious): static
    {
        $this->meterPrevious = $meterPrevious;

        return $this;
    }

    public function getMeterCurrent(): string
    {
        return $this->meterCurrent;
    }

    public function setMeterCurrent(string $meterCurrent): static
    {
        $this->meterCurrent = $meterCurrent;

        return $this;
    }

    public function getUnits(): string
    {
        return $this->units;
    }

    public function setUnits(string $units): static
    {
        $this->units = $units;

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

    public function getElectricityAmount(): string
    {
        return $this->electricityAmount;
    }

    public function setElectricityAmount(string $electricityAmount): static
    {
        $this->electricityAmount = $electricityAmount;

        return $this;
    }

    public function getOtherAmount(): string
    {
        return $this->otherAmount;
    }

    public function setOtherAmount(string $otherAmount): static
    {
        $this->otherAmount = $otherAmount;

        return $this;
    }

    public function getOtherNote(): ?string
    {
        return $this->otherNote;
    }

    public function setOtherNote(?string $otherNote): static
    {
        $this->otherNote = $otherNote;

        return $this;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): static
    {
        $this->total = $total;

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

    public function getTenant(): ?RentTenant
    {
        return $this->tenant;
    }

    public function setTenant(RentTenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }
}
