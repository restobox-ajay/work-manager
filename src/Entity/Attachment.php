<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An uploaded file on a work order or an event (ADR-125). The bytes live outside the web root under a random name
 * (AttachmentStorage); this row keeps what the user called it and what it was, and is only ever served through an
 * admin-only download route. owner_type + owner_id instead of a foreign key, so one table serves both modules; the
 * owners' services remove their attachments when they are deleted.
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'attachment')]
#[ORM\Index(name: 'idx_attachment_owner', columns: ['owner_type', 'owner_id'])]
class Attachment
{
    public const OWNER_WORK_ORDER = 'work_order';
    public const OWNER_EVENT = 'event';

    /** Image types a browser shows safely (no SVG: it can carry script). */
    public const INLINE_IMAGES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $ownerType = '';

    #[ORM\Column]
    private int $ownerId = 0;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 255)]
    private string $originalName = '';

    #[ORM\Column(length: 80, unique: true)]
    private string $storedName = '';

    #[ORM\Column(length: 120)]
    private string $mimeType = 'application/octet-stream';

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column]
    private int $uploadedAt = 0;

    #[ORM\Column(nullable: true)]
    private ?int $uploadedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerType(): string
    {
        return $this->ownerType;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function setOwner(string $type, int $id): static
    {
        $this->ownerType = $type;
        $this->ownerId = $id;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getStoredName(): string
    {
        return $this->storedName;
    }

    public function setStoredName(string $storedName): static
    {
        $this->storedName = $storedName;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getUploadedAt(): int
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(int $uploadedAt): static
    {
        $this->uploadedAt = $uploadedAt;

        return $this;
    }

    public function getUploadedBy(): ?int
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?int $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    /** What to show for it: its label, else the file's own name. */
    public function getDisplayName(): string
    {
        return $this->label ?? $this->originalName;
    }

    /** PDFs and images open in the browser; everything else downloads. */
    public function isViewableInline(): bool
    {
        return $this->mimeType === 'application/pdf' || in_array($this->mimeType, self::INLINE_IMAGES, true);
    }
}
