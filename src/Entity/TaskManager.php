<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskManagerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto the existing Yii2 `task_manager` table -- grants a specific
 * user manage/fee-visibility rights on one task, independent of their
 * project/client role. Backs Task::isTaskAdmin()/canUserAccessFee()/
 * canUserUpdateTask() in the Yii2 source.
 */
#[ORM\Entity(repositoryClass: TaskManagerRepository::class)]
#[ORM\Table(name: 'task_manager')]
#[ORM\Index(name: 'idx_task_manager_task', columns: ['task_id'])]
#[ORM\Index(name: 'idx_task_manager_user', columns: ['user_id'])]
class TaskManager
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $taskId;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(type: 'boolean')]
    private bool $canAccessTaskFee = false;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function canAccessTaskFee(): bool
    {
        return $this->canAccessTaskFee;
    }

    public function setCanAccessTaskFee(bool $canAccessTaskFee): static
    {
        $this->canAccessTaskFee = $canAccessTaskFee;

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
}
