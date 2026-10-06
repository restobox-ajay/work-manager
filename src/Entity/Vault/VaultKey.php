<?php

declare(strict_types=1);

namespace App\Entity\Vault;

use App\Entity\User;
use App\Repository\Vault\VaultKeyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One admin's Password Manager vault key, wrapped (ADR-092). The browser derives a key from the master password
 * (PBKDF2-SHA256 with this salt and iteration count) and uses it to unwrap the vault key (AES-256-GCM). The master
 * password and the unwrapped key never reach the server, so this row alone cannot open the vault.
 */
#[ORM\Entity(repositoryClass: VaultKeyRepository::class)]
#[ORM\Table(name: 'vault_key')]
class VaultKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 30)]
    private string $kdf = 'PBKDF2-SHA256';

    #[ORM\Column]
    private int $iterations = 0;

    /** base64 */
    #[ORM\Column(length: 64)]
    private string $salt = '';

    /** base64 AES-GCM ciphertext + tag of the 32-byte vault key */
    #[ORM\Column(length: 128)]
    private string $wrappedKey = '';

    /** base64 12-byte IV used to wrap the vault key */
    #[ORM\Column(length: 32)]
    private string $wrapIv = '';

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

    public function getKdf(): string
    {
        return $this->kdf;
    }

    public function getIterations(): int
    {
        return $this->iterations;
    }

    public function getSalt(): string
    {
        return $this->salt;
    }

    public function getWrappedKey(): string
    {
        return $this->wrappedKey;
    }

    public function getWrapIv(): string
    {
        return $this->wrapIv;
    }

    /** A new master password: the same vault key wrapped under a new salt / iteration count. */
    public function wrap(int $iterations, string $salt, string $wrappedKey, string $wrapIv): static
    {
        $this->iterations = $iterations;
        $this->salt = $salt;
        $this->wrappedKey = $wrappedKey;
        $this->wrapIv = $wrapIv;
        $this->updatedAt = time();
        if ($this->createdAt === 0) {
            $this->createdAt = $this->updatedAt;
        }

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
