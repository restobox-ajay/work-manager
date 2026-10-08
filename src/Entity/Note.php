<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A note (ADR-101): on at most one client, project or task, or on none — an independent note, private to its
 * author. Which one it is about is NoteService's rule; the three links are plain nullable foreign keys so each
 * record page can list its notes with one indexed query.
 */
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'note')]
#[ORM\Index(name: 'idx_note_author', columns: ['author_id'])]
class Note
{
    public const TYPE_INDEPENDENT = 'independent';
    public const TYPE_CLIENT = 'client';
    public const TYPE_PROJECT = 'project';
    public const TYPE_TASK = 'task';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\Column]
    private bool $pinned = false;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: true, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', nullable: true, onDelete: 'CASCADE')]
    private ?Task $task = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $author = null;

    #[ORM\Column]
    private int $createdAt = 0;

    #[ORM\Column]
    private int $updatedAt = 0;

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

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): static
    {
        $this->pinned = $pinned;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    /** Points the note at one record, or at none (independent); the other two links are cleared. */
    public function attachTo(Client|Project|Task|null $subject): static
    {
        $this->client = $subject instanceof Client ? $subject : null;
        $this->project = $subject instanceof Project ? $subject : null;
        $this->task = $subject instanceof Task ? $subject : null;

        return $this;
    }

    public function getSubject(): Client|Project|Task|null
    {
        return $this->client ?? $this->project ?? $this->task;
    }

    public function getType(): string
    {
        return self::typeOf($this->getSubject());
    }

    /** The TYPE_* a note on this record has (null: independent). */
    public static function typeOf(Client|Project|Task|null $subject): string
    {
        return match (true) {
            $subject instanceof Client  => self::TYPE_CLIENT,
            $subject instanceof Project => self::TYPE_PROJECT,
            $subject instanceof Task    => self::TYPE_TASK,
            default                     => self::TYPE_INDEPENDENT,
        };
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(int $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
