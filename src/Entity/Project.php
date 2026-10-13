<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto the existing `project` table (models/Project.php +
 * models/base/Project.php in the Yii2 app) -- no schema changes.
 *
 * Deliberately NOT mapped: `is_template`, `parent_template_id`,
 * `template_grid_sort_order`, `duplicated_project_id`. Project Templates
 * are being dropped in favor of the (not-yet-built) Task Grid module per
 * the rewrite's own spec, and every one of those columns has a DB-level
 * default -- Doctrine simply never touches them, MySQL fills the default.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
class Project
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_ARCHIVE = 0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    // A project may have no client (ADR-128): internal work, or a job for someone not in the Clients list yet.
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: true)]
    private ?Client $client = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, ProjectLink> named URLs (ADR-129) */
    #[ORM\OneToMany(targetEntity: ProjectLink::class, mappedBy: 'project', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $links;

    public function __construct()
    {
        $this->links = new ArrayCollection();
    }






    #[ORM\Column(type: Types::SMALLINT)]
    private int $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'is_deleted', type: Types::SMALLINT)]
    private int $isDeleted = 0;

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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }






    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getIsDeleted(): int
    {
        return $this->isDeleted;
    }

    public function setIsDeleted(int $isDeleted): static
    {
        $this->isDeleted = $isDeleted;

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

    /** @return Collection<int, ProjectLink> */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    /**
     * Swaps the whole list for these links, in this order (the form edits them all at once).
     *
     * @param list<ProjectLink> $links
     */
    public function replaceLinks(array $links): static
    {
        $this->links->clear();
        foreach (array_values($links) as $i => $link) {
            $this->links->add($link->setProject($this)->setSortOrder(($i + 1) * 10));
        }

        return $this;
    }
}
