<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectStaffRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto `project_staff` -- the actively-used staff/ownership table
 * (unlike the legacy `project_admin`, which the Yii2 app barely touches).
 * `permission = 'Project Manager'` is what Project::isProjectManager()
 * actually checks. `Client Manager` and `Contractor` are the other two
 * values the app itself ever writes (auto-derived, see ProjectStaffService);
 * anything else stored in `permission` is just a free-text label (e.g. a
 * `/*`-holder's flat profile role) used for display only.
 */
#[ORM\Entity(repositoryClass: ProjectStaffRepository::class)]
#[ORM\Table(name: 'project_staff')]
class ProjectStaff
{
    public const PERMISSION_PROJECT_MANAGER = 'Project Manager';
    public const PERMISSION_CLIENT_MANAGER = 'Client Manager';
    public const PERMISSION_CONTRACTOR = 'Contractor';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'projectStaff')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $permission = null;

    #[ORM\Column(name: 'can_access_task_fee', type: Types::SMALLINT, nullable: true)]
    private ?int $canAccessTaskFee = 0;

    /**
     * A JSON array of Tag names (e.g. `["Billing","Urgent"]`), mapped as
     * plain text rather than Doctrine's `json` type -- same reasoning as
     * Message::$oldMetaData/$newMetaData: callers store/read a JSON-encoded
     * string directly and use decodeRoles() below to parse it back out
     * defensively rather than trusting a strict type mapping.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $roles = null;

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

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPermission(): ?string
    {
        return $this->permission;
    }

    public function setPermission(?string $permission): static
    {
        $this->permission = $permission;

        return $this;
    }

    public function getCanAccessTaskFee(): ?int
    {
        return $this->canAccessTaskFee;
    }

    public function setCanAccessTaskFee(?int $canAccessTaskFee): static
    {
        $this->canAccessTaskFee = $canAccessTaskFee;

        return $this;
    }

    public function getRoles(): ?string
    {
        return $this->roles;
    }

    public function setRoles(?string $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getRolesDecoded(): array
    {
        return self::decodeRoles($this->roles);
    }

    /**
     * @return string[]
     */
    public static function decodeRoles(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
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
