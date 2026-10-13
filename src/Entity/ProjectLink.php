<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One of a project's links (ADR-129): a name ("Local", "Dev", "Prod", "Figma"… anything), the URL and an optional
 * description, in the order they were entered. Replaces the fixed local/dev/prod/doc/mockup URL columns.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_link')]
class ProjectLink
{
    private const TONE_COUNT = 8;
    /** Name prefix → tone, checked in order. */
    private const KNOWN_TONES = [
        'local' => 0, 'dev' => 1, 'stag' => 2, 'uat' => 2, 'test' => 2, 'qa' => 2, 'prod' => 3, 'live' => 3,
        'doc' => 4, 'spec' => 4, 'mockup' => 5, 'figma' => 5, 'design' => 5, 'repo' => 6, 'git' => 6, 'admin' => 7,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'links')]
    #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 60)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $url = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * The colour of the name's label on the project page (CSS .pl-tone-0 … .pl-tone-7): the usual environments get
     * fixed colours (Prod green, Dev blue…); any other name always gets the same colour from its text.
     */
    public function getTone(): int
    {
        $key = strtolower(trim($this->name));
        foreach (self::KNOWN_TONES as $prefix => $tone) {
            if (str_starts_with($key, $prefix)) {
                return $tone;
            }
        }

        return crc32($key) % self::TONE_COUNT;
    }

    /** Only an http(s) URL is ever rendered as a clickable link. */
    public function isClickable(): bool
    {
        return (bool) preg_match('#^https?://#i', $this->url);
    }
}
