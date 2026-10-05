<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use App\Repository\Settings\WalletEntityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WalletEntityRepository::class)]
#[ORM\Table(name: 'wallet_entity')]
class WalletEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(name: 'owner_name', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $ownerName = null;

    // Legacy columns -- string typed in the DB, never set/exposed by this app.
    #[ORM\Column(name: 'created_at', length: 255, nullable: true)]
    private ?string $createdAt = null;

    #[ORM\Column(name: 'updated_at', length: 255, nullable: true)]
    private ?string $updatedAt = null;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'updated_by', length: 255, nullable: true)]
    private ?string $updatedBy = null;

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

    public function getOwnerName(): ?string
    {
        return $this->ownerName;
    }

    public function setOwnerName(?string $ownerName): static
    {
        $this->ownerName = $ownerName;

        return $this;
    }
}
