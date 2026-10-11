<?php

declare(strict_types=1);

namespace App\Entity\WorkOrder;

use App\Repository\WorkOrder\WorkOrderMilestoneRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A deliverable or payment milestone of a work order (ADR-125): what, by when, and whether it is done. */
#[ORM\Entity(repositoryClass: WorkOrderMilestoneRepository::class)]
#[ORM\Table(name: 'work_order_milestone')]
class WorkOrderMilestone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkOrder::class)]
    #[ORM\JoinColumn(name: 'work_order_id', nullable: false, onDelete: 'CASCADE')]
    private ?WorkOrder $workOrder = null;

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column]
    private bool $done = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneOn = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkOrder(): ?WorkOrder
    {
        return $this->workOrder;
    }

    public function setWorkOrder(WorkOrder $workOrder): static
    {
        $this->workOrder = $workOrder;

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

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $d): static
    {
        $this->dueDate = $d;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function setDone(bool $done): static
    {
        $this->done = $done;
        $this->doneOn = $done ? new \DateTimeImmutable('today') : null;

        return $this;
    }

    public function getDoneOn(): ?\DateTimeImmutable
    {
        return $this->doneOn;
    }

    public function isOverdue(): bool
    {
        return !$this->done && $this->dueDate !== null && $this->dueDate < new \DateTimeImmutable('today');
    }
}
