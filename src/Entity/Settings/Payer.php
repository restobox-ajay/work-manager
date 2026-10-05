<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use App\Enum\PayerType;
use App\Repository\Settings\PayerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PayerRepository::class)]
#[ORM\Table(name: 'payer')]
class Payer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'company_name', length: 255)]
    #[Assert\NotBlank(message: 'Company name is required.')]
    #[Assert\Length(max: 255)]
    private string $companyName = '';

    #[ORM\Column(name: 'contact_name', length: 255)]
    #[Assert\NotBlank(message: 'Contact name is required.')]
    #[Assert\Length(max: 255)]
    private string $contactName = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Length(max: 255)]
    #[Assert\Email(message: 'Please enter a valid email address.')]
    private string $email = '';

    #[ORM\Column(type: 'boolean')]
    private bool $status = true;

    #[ORM\Column(length: 50, enumType: PayerType::class)]
    private PayerType $type;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getContactName(): string
    {
        return $this->contactName;
    }

    public function setContactName(string $contactName): static
    {
        $this->contactName = $contactName;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getStatus(): bool
    {
        return $this->status;
    }

    public function setStatus(bool $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getType(): PayerType
    {
        return $this->type;
    }

    public function setType(PayerType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function getCreatedAt(): ?int
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?int
    {
        return $this->updatedAt;
    }
}
