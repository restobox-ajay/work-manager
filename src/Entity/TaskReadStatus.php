<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskReadStatusRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto `task_read_status` -- one row per (user, task) pair tracking
 * whether that user has seen the task on the "Task Created By Manager"
 * grid yet. Plain scalar FKs (matching Task's own convention for
 * everything that isn't project/assignee), since this table is only ever
 * queried by raw id, never traversed as an object graph.
 */
#[ORM\Entity(repositoryClass: TaskReadStatusRepository::class)]
#[ORM\Table(name: 'task_read_status')]
#[ORM\Index(name: 'idx_task_read_status_user_task', columns: ['user_id', 'task_id'])]
class TaskReadStatus
{
    public const READ_NO = 0;
    public const READ_YES = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $taskId = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $isRead = self::READ_NO;

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

    public function getTaskId(): ?int
    {
        return $this->taskId;
    }

    public function setTaskId(?int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function getIsRead(): int
    {
        return $this->isRead;
    }

    public function setIsRead(int $isRead): static
    {
        $this->isRead = $isRead;

        return $this;
    }

    public function isRead(): bool
    {
        return $this->isRead === self::READ_YES;
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
