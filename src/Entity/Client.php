<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto the existing `client` table (models/Client.php + models/base/Client.php
 * in the Yii2 app) -- no schema changes.
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'client')]
class Client
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_ARCHIVE = 0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    /** The registered business name, printed as Billed To on invoices when set (ADR-080). */
    #[ORM\Column(name: 'company_name', length: 255, nullable: true)]
    private ?string $companyName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'client_code', length: 10, nullable: true)]
    private ?string $clientCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'street_address_1', length: 255, nullable: true)]
    private ?string $streetAddress1 = null;

    #[ORM\Column(name: 'street_address_2', length: 255, nullable: true)]
    private ?string $streetAddress2 = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $province = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(name: 'zip_code', length: 10, nullable: true)]
    private ?string $zipCode = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'is_active', type: 'smallint')]
    private int $isActive = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'is_deleted', type: 'smallint')]
    private int $isDeleted = 0;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedBy = null;

    /** @var Collection<int, ClientAdmin> */
    #[ORM\OneToMany(targetEntity: ClientAdmin::class, mappedBy: 'client', orphanRemoval: true)]
    private Collection $clientAdmins;

    public function __construct()
    {
        $this->clientAdmins = new ArrayCollection();
    }

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getClientCode(): ?string
    {
        return $this->clientCode;
    }

    public function setClientCode(?string $clientCode): static
    {
        $this->clientCode = $clientCode;

        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

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

    public function getProvince(): ?string
    {
        return $this->province;
    }

    public function setProvince(?string $province): static
    {
        $this->province = $province;

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

    public function getIsActive(): int
    {
        return $this->isActive;
    }

    public function setIsActive(int $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive === self::STATUS_ACTIVE;
    }

    public function getIsDeleted(): int
    {
        return $this->isDeleted;
    }

    public function setIsDeleted(int $isDeleted): static
    {
        $this->isDeleted = $isDeleted;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted === 1;
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

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): static
    {
        $this->createdBy = $createdBy;

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

    /**
     * @return Collection<int, ClientAdmin>
     */
    public function getClientAdmins(): Collection
    {
        return $this->clientAdmins;
    }
}
