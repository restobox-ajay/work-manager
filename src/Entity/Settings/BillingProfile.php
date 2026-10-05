<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use App\Repository\Settings\BillingProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who an invoice is from (ADR-077): one of your own businesses, with the address printed as "Billed By" and its own
 * invoice numbering — prefix plus the next running number (phpINV1057 = prefix "phpINV", number 1057).
 */
#[ORM\Entity(repositoryClass: BillingProfileRepository::class)]
#[ORM\Table(name: 'billing_profile')]
class BillingProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $streetAddress1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $streetAddress2 = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $zipCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $taxNumber = null;

    #[ORM\Column(length: 20)]
    private string $invoicePrefix = 'INV';

    #[ORM\Column(options: ['default' => 1])]
    private int $nextNumber = 1;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

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

    public function getStreetAddress1(): ?string
    {
        return $this->streetAddress1;
    }

    public function setStreetAddress1(?string $streetAddress1): static
    {
        $this->streetAddress1 = $streetAddress1;

        return $this;
    }

    public function getStreetAddress2(): ?string
    {
        return $this->streetAddress2;
    }

    public function setStreetAddress2(?string $streetAddress2): static
    {
        $this->streetAddress2 = $streetAddress2;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getZipCode(): ?string
    {
        return $this->zipCode;
    }

    public function setZipCode(?string $zipCode): static
    {
        $this->zipCode = $zipCode;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getTaxNumber(): ?string
    {
        return $this->taxNumber;
    }

    public function setTaxNumber(?string $taxNumber): static
    {
        $this->taxNumber = $taxNumber;

        return $this;
    }

    public function getInvoicePrefix(): string
    {
        return $this->invoicePrefix;
    }

    public function setInvoicePrefix(string $invoicePrefix): static
    {
        $this->invoicePrefix = $invoicePrefix;

        return $this;
    }

    public function getNextNumber(): int
    {
        return $this->nextNumber;
    }

    public function setNextNumber(int $nextNumber): static
    {
        $this->nextNumber = $nextNumber;

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

    /** The address block as printed: street lines, then "City, State", then "Country - Zip". */
    public function addressText(): string
    {
        $cityLine = implode(', ', array_filter([$this->city, $this->state], static fn (?string $part) => $part !== null && $part !== ''));
        $countryLine = implode(' - ', array_filter([$this->country, $this->zipCode], static fn (?string $part) => $part !== null && $part !== ''));

        return implode("\n", array_filter([$this->streetAddress1, $this->streetAddress2, $cityLine, $countryLine], static fn (?string $line) => $line !== null && $line !== ''));
    }
}
