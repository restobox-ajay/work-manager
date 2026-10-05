<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto the existing Yii2 `task` table. Foreign keys stay plain scalar
 * columns (matching how User/Payer map their FKs elsewhere in this app),
 * except `project` and `assignee`, which are traversed constantly across
 * the task list/view pages and get real Doctrine associations.
 */
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(name: 'idx_task_client', columns: ['client_id'])]
#[ORM\Index(name: 'idx_task_status', columns: ['task_status_id'])]
#[ORM\Index(name: 'idx_task_reviewer', columns: ['reviewer_user_id'])]
#[ORM\Index(name: 'idx_task_created_by', columns: ['created_by'])]
class Task
{
    /**
     * Yii2's naming is inverted from what it reads like: AUTHORIZED_YES
     * (0) means "still sitting in the authorization queue", AUTHORIZED_NO
     * (1) means "already authorized, a regular task now" -- kept as-is
     * for fidelity rather than renamed, since the DB column's actual
     * values are 0/1 exactly matching this.
     */
    public const AUTHORIZED_YES = 0;
    public const AUTHORIZED_NO = 1;

    public const AUTHORIZED_STATUS_PENDING = 'Pending';
    public const AUTHORIZED_STATUS_APPROVE = 'Approve';
    public const AUTHORIZED_STATUS_DENY = 'Deny';
    public const AUTHORIZED_STATUS_MODIFY = 'Modify';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $assignee = null;

    #[ORM\Column(nullable: true)]
    private ?int $clientId = null;

    #[ORM\Column(nullable: true)]
    private ?int $taskTypeId = null;

    #[ORM\Column(nullable: true)]
    private ?int $currencyId = 1;

    #[ORM\Column(nullable: true)]
    private ?int $rbxOrderId = null;

    #[ORM\Column(length: 5000)]
    private string $name = '';

    #[ORM\Column(nullable: true)]
    private ?int $reviewerUserId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $isRead = 0;

    #[ORM\Column(nullable: true)]
    private ?int $taskStatusId = 1;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statusDetail = null;

    #[ORM\Column(nullable: true)]
    private ?int $approvedDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $approvedBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $dueDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $paidDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $paidBy = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $paymentId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $totalAmount = '0.00';

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $isActive = 1;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $isDeleted = 0;

    #[ORM\Column(nullable: true)]
    private ?int $payerId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dependentTaskList = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $authorized = 1;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $authorizedStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authorizedDescription = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $priority = 0;

    #[ORM\Column(nullable: true)]
    private ?int $duplicatedTaskId = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $isActiveOnTemplateGrid = 1;

    #[ORM\Column(nullable: true)]
    private ?int $templateSortOrder = 999;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tutorial = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $creationDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $timeBudget = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $billableDate = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function getClientId(): ?int
    {
        return $this->clientId;
    }

    public function setClientId(?int $clientId): static
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getTaskTypeId(): ?int
    {
        return $this->taskTypeId;
    }

    public function setTaskTypeId(?int $taskTypeId): static
    {
        $this->taskTypeId = $taskTypeId;

        return $this;
    }

    public function getCurrencyId(): ?int
    {
        return $this->currencyId;
    }

    public function setCurrencyId(?int $currencyId): static
    {
        $this->currencyId = $currencyId;

        return $this;
    }

    public function getRbxOrderId(): ?int
    {
        return $this->rbxOrderId;
    }

    public function setRbxOrderId(?int $rbxOrderId): static
    {
        $this->rbxOrderId = $rbxOrderId;

        return $this;
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

    public function getReviewerUserId(): ?int
    {
        return $this->reviewerUserId;
    }

    public function setReviewerUserId(?int $reviewerUserId): static
    {
        $this->reviewerUserId = $reviewerUserId;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getIsRead(): ?int
    {
        return $this->isRead;
    }

    public function setIsRead(?int $isRead): static
    {
        $this->isRead = $isRead;

        return $this;
    }

    public function getTaskStatusId(): ?int
    {
        return $this->taskStatusId;
    }

    public function setTaskStatusId(?int $taskStatusId): static
    {
        $this->taskStatusId = $taskStatusId;

        return $this;
    }

    public function getStatusDetail(): ?string
    {
        return $this->statusDetail;
    }

    public function setStatusDetail(?string $statusDetail): static
    {
        $this->statusDetail = $statusDetail;

        return $this;
    }

    public function getApprovedDate(): ?int
    {
        return $this->approvedDate;
    }

    public function setApprovedDate(?int $approvedDate): static
    {
        $this->approvedDate = $approvedDate;

        return $this;
    }

    public function getApprovedBy(): ?int
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?int $approvedBy): static
    {
        $this->approvedBy = $approvedBy;

        return $this;
    }

    public function getDueDate(): ?int
    {
        return $this->dueDate;
    }

    public function setDueDate(?int $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getPaidDate(): ?int
    {
        return $this->paidDate;
    }

    public function setPaidDate(?int $paidDate): static
    {
        $this->paidDate = $paidDate;

        return $this;
    }

    public function getPaidBy(): ?int
    {
        return $this->paidBy;
    }

    public function setPaidBy(?int $paidBy): static
    {
        $this->paidBy = $paidBy;

        return $this;
    }

    public function getPaymentId(): ?string
    {
        return $this->paymentId;
    }

    public function setPaymentId(?string $paymentId): static
    {
        $this->paymentId = $paymentId;

        return $this;
    }

    public function getTotalAmount(): ?string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(?string $totalAmount): static
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive === 1;
    }

    public function setIsActive(?int $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted === 1;
    }

    public function setIsDeleted(?int $isDeleted): static
    {
        $this->isDeleted = $isDeleted;

        return $this;
    }

    public function getPayerId(): ?int
    {
        return $this->payerId;
    }

    public function setPayerId(?int $payerId): static
    {
        $this->payerId = $payerId;

        return $this;
    }

    public function getDependentTaskList(): ?string
    {
        return $this->dependentTaskList;
    }

    public function setDependentTaskList(?string $dependentTaskList): static
    {
        $this->dependentTaskList = $dependentTaskList;

        return $this;
    }

    public function getAuthorized(): ?int
    {
        return $this->authorized;
    }

    public function setAuthorized(?int $authorized): static
    {
        $this->authorized = $authorized;

        return $this;
    }

    public function getAuthorizedStatus(): ?string
    {
        return $this->authorizedStatus;
    }

    public function setAuthorizedStatus(?string $authorizedStatus): static
    {
        $this->authorizedStatus = $authorizedStatus;

        return $this;
    }

    public function getAuthorizedDescription(): ?string
    {
        return $this->authorizedDescription;
    }

    public function setAuthorizedDescription(?string $authorizedDescription): static
    {
        $this->authorizedDescription = $authorizedDescription;

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

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(?int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getDuplicatedTaskId(): ?int
    {
        return $this->duplicatedTaskId;
    }

    public function setDuplicatedTaskId(?int $duplicatedTaskId): static
    {
        $this->duplicatedTaskId = $duplicatedTaskId;

        return $this;
    }

    public function getIsActiveOnTemplateGrid(): ?int
    {
        return $this->isActiveOnTemplateGrid;
    }

    public function setIsActiveOnTemplateGrid(?int $isActiveOnTemplateGrid): static
    {
        $this->isActiveOnTemplateGrid = $isActiveOnTemplateGrid;

        return $this;
    }

    public function getTemplateSortOrder(): ?int
    {
        return $this->templateSortOrder;
    }

    public function setTemplateSortOrder(?int $templateSortOrder): static
    {
        $this->templateSortOrder = $templateSortOrder;

        return $this;
    }

    public function getTutorial(): ?string
    {
        return $this->tutorial;
    }

    public function setTutorial(?string $tutorial): static
    {
        $this->tutorial = $tutorial;

        return $this;
    }

    public function getCreationDate(): ?\DateTimeInterface
    {
        return $this->creationDate;
    }

    public function setCreationDate(?\DateTimeInterface $creationDate): static
    {
        $this->creationDate = $creationDate;

        return $this;
    }

    public function getTimeBudget(): ?int
    {
        return $this->timeBudget;
    }

    public function setTimeBudget(?int $timeBudget): static
    {
        $this->timeBudget = $timeBudget;

        return $this;
    }

    public function getBillableDate(): ?\DateTimeInterface
    {
        return $this->billableDate;
    }

    public function setBillableDate(?\DateTimeInterface $billableDate): static
    {
        $this->billableDate = $billableDate;

        return $this;
    }
}
