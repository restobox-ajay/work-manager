<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskPriorityOrderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto `task_priority_order` -- one row per (user, task) pair holding
 * that user's manual sort position on the "Task Priority" page. Plain
 * scalar FKs, matching Task's own convention for everything that isn't
 * project/assignee.
 */
#[ORM\Entity(repositoryClass: TaskPriorityOrderRepository::class)]
#[ORM\Table(name: 'task_priority_order')]
#[ORM\Index(name: 'idx_task_priority_order_user_task', columns: ['user_id', 'task_id'])]
class TaskPriorityOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column]
    private int $taskId;

    #[ORM\Column(nullable: true)]
    private ?int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(?int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
