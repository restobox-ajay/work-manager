<?php

declare(strict_types=1);

namespace App\Entity\Vault;

use App\Entity\User;
use App\Repository\Vault\VaultEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One Password Manager entry (ADR-092): a login, app PIN, licence key or note, encrypted in the owner's browser with
 * their vault key (AES-256-GCM). Everything about it — type, title, site, username, password — is inside the
 * ciphertext; the server only knows whose it is and when it changed.
 */
#[ORM\Entity(repositoryClass: VaultEntryRepository::class)]
#[ORM\Table(name: 'vault_entry')]
class VaultEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** base64 AES-GCM ciphertext + tag */
    #[ORM\Column(type: 'text')]
    private string $ciphertext = '';

    /** base64 12-byte IV, fresh for every save */
    #[ORM\Column(length: 32)]
    private string $iv = '';

    /** Bumped on every save; an update must name the version it edited, so a stale tab cannot overwrite. */
    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column]
    private int $createdAt = 0;

    #[ORM\Column]
    private int $updatedAt = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCiphertext(): string
    {
        return $this->ciphertext;
    }

    public function getIv(): string
    {
        return $this->iv;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function seal(string $ciphertext, string $iv): static
    {
        $now = time();
        if ($this->createdAt === 0) {
            $this->createdAt = $now;
        } else {
            ++$this->version;
        }
        $this->ciphertext = $ciphertext;
        $this->iv = $iv;
        $this->updatedAt = $now;

        return $this;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }
}
