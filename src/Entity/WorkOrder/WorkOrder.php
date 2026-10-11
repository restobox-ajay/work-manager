<?php

declare(strict_types=1);

namespace App\Entity\WorkOrder;

use App\Entity\Client;
use App\Entity\Project;
use App\Repository\WorkOrder\WorkOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A contract or job won (ADR-125): who it is for, what it is worth and when, the scope (rich text), milestones and
 * its documents (attachments). A client from the Clients list can be linked, or a name typed for someone who is not
 * a client yet; "Convert to project" creates a project for the linked client and remembers it here.
 */
#[ORM\Entity(repositoryClass: WorkOrderRepository::class)]
#[ORM\Table(name: 'work_order')]
class WorkOrder
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [
        self::STATUS_DRAFT     => 'Draft',
        self::STATUS_ACTIVE    => 'Active',
        self::STATUS_ON_HOLD   => 'On hold',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];
    /** Statuses still being worked on: the list's default filter. */
    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ON_HOLD];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $number = '';

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: true, onDelete: 'SET NULL')]
    private ?Client $client = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $clientName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'INR';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentTerms = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $advanceReceived = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signedDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\Column]
    private int $createdAt = 0;

    #[ORM\Column]
    private int $updatedAt = 0;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function setClientName(?string $clientName): static
    {
        $this->clientName = $clientName;

        return $this;
    }

    /** The linked client's name, else the typed one. */
    public function getCustomerName(): ?string
    {
        return $this->client?->getName() ?? $this->clientName;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $v): static
    {
        $this->contactName = $v;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $v): static
    {
        $this->contactPhone = $v;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $v): static
    {
        $this->contactEmail = $v;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $v): static
    {
        $this->reference = $v;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getPaymentTerms(): ?string
    {
        return $this->paymentTerms;
    }

    public function setPaymentTerms(?string $v): static
    {
        $this->paymentTerms = $v;

        return $this;
    }

    public function getAdvanceReceived(): ?string
    {
        return $this->advanceReceived;
    }

    public function setAdvanceReceived(?string $v): static
    {
        $this->advanceReceived = $v;

        return $this;
    }

    public function getSignedDate(): ?\DateTimeImmutable
    {
        return $this->signedDate;
    }

    public function setSignedDate(?\DateTimeImmutable $d): static
    {
        $this->signedDate = $d;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $d): static
    {
        $this->startDate = $d;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $d): static
    {
        $this->endDate = $d;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $v): static
    {
        $this->description = $v;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $v): static
    {
        $this->notes = $v;

        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $t): static
    {
        $this->createdAt = $t;

        return $this;
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(int $t): static
    {
        $this->updatedAt = $t;

        return $this;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $id): static
    {
        $this->createdBy = $id;

        return $this;
    }

    /** Days until the end date (negative once past); null without one. */
    public function getDaysLeft(): ?int
    {
        if ($this->endDate === null) {
            return null;
        }

        return (int) (new \DateTimeImmutable('today'))->diff($this->endDate)->format('%r%a');
    }
}
