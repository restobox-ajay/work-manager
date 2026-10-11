<?php

declare(strict_types=1);

namespace App\Entity\Event;

use App\Repository\Event\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An important date to keep (ADR-126): a birth, a death, an anniversary, a legal or health record — anything. It can
 * repeat every year (birthdays) and send a reminder email some days before (app:events:remind). The table is
 * life_event: EVENT is a MySQL keyword.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'life_event')]
#[ORM\Index(name: 'idx_life_event_date', columns: ['event_date'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\ManyToOne(targetEntity: EventCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'SET NULL')]
    private ?EventCategory $category = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $eventDate;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $eventTime = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $people = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $place = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $repeatsYearly = false;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $remindDaysBefore = null;

    /** The occurrence the last reminder email was for, so a reminder is never sent twice. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRemindedFor = null;

    #[ORM\Column]
    private int $createdAt = 0;

    #[ORM\Column]
    private int $updatedAt = 0;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    public function __construct()
    {
        $this->eventDate = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCategory(): ?EventCategory
    {
        return $this->category;
    }

    public function setCategory(?EventCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getEventDate(): \DateTimeImmutable
    {
        return $this->eventDate;
    }

    public function setEventDate(\DateTimeImmutable $d): static
    {
        $this->eventDate = $d;

        return $this;
    }

    public function getEventTime(): ?\DateTimeImmutable
    {
        return $this->eventTime;
    }

    public function setEventTime(?\DateTimeImmutable $t): static
    {
        $this->eventTime = $t;

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

    public function getPeople(): ?string
    {
        return $this->people;
    }

    public function setPeople(?string $v): static
    {
        $this->people = $v;

        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(?string $v): static
    {
        $this->place = $v;

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

    public function isRepeatsYearly(): bool
    {
        return $this->repeatsYearly;
    }

    public function setRepeatsYearly(bool $v): static
    {
        $this->repeatsYearly = $v;

        return $this;
    }

    public function getRemindDaysBefore(): ?int
    {
        return $this->remindDaysBefore;
    }

    public function setRemindDaysBefore(?int $days): static
    {
        $this->remindDaysBefore = $days;

        return $this;
    }

    public function getLastRemindedFor(): ?\DateTimeImmutable
    {
        return $this->lastRemindedFor;
    }

    public function setLastRemindedFor(?\DateTimeImmutable $d): static
    {
        $this->lastRemindedFor = $d;

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
}
