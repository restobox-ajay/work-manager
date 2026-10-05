<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use App\Repository\Settings\TaskStatusRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaskStatusRepository::class)]
#[ORM\Table(name: 'task_status')]
class TaskStatus
{
    /**
     * The seeded rows other modules name by id -- stable rows, hardcoded
     * the same way Yii2's own TaskStatus model hardcodes them, not derived
     * at runtime. Collected here because three services and three templates
     * each carried their own copy of the same integers; the full id -> name
     * list is in App\Twig\TaskStatusExtension's colour map.
     */
    public const PENDING_ID = 1;
    public const APPROVED_ID = 2;
    public const PAID_ID = 3;
    /** Row 4 is "Reviewing - Internal". It was called REVIEWING_ID, which
     *  read as though it were the only reviewing status; row 8 is
     *  "Reviewing - Client" and had no constant here at all, so the
     *  submission module kept private copies of both. */
    public const REVIEWING_INTERNAL_ID = 4;
    public const WAITING_FOR_ANOTHER_TASK_ID = 7;
    public const REVIEWING_CLIENT_ID = 8;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 50)]
    private string $name = '';

    #[ORM\Column(type: 'boolean', name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(name: '`order`', type: 'integer', nullable: true)]
    private ?int $order = 0;

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getOrder(): ?int
    {
        return $this->order;
    }

    public function setOrder(?int $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function getCreatedAt(): ?int
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?int
    {
        return $this->updatedAt;
    }
}
