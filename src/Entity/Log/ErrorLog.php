<?php

declare(strict_types=1);

namespace App\Entity\Log;

use App\Repository\Log\ErrorLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One error (ADR-093): a server exception that became a 5xx, a console command failure, or a JavaScript error a
 * signed-in user's browser reported. Written by ErrorLogWriter with plain DBAL so logging works even when the
 * EntityManager is closed by the error being logged. Query strings and trace arguments are never kept.
 */
#[ORM\Entity(repositoryClass: ErrorLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'error_log')]
#[ORM\Index(name: 'idx_error_log_created_at', columns: ['created_at'])]
class ErrorLog
{
    public const SOURCES = ['server' => 'Server', 'browser' => 'Browser', 'console' => 'Console'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $source = 'server';

    #[ORM\Column(length: 10)]
    private string $level = 'error';

    #[ORM\Column(nullable: true)]
    private ?int $statusCode = null;

    #[ORM\Column(type: 'text')]
    private string $message = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exceptionClass = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $file = null;

    #[ORM\Column(nullable: true)]
    private ?int $line = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $trace = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $path = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $referrer = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $userEmail = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private int $createdAt = 0;

    public function getId(): ?int { return $this->id; }
    public function getSource(): string { return $this->source; }
    public function getLevel(): string { return $this->level; }
    public function getStatusCode(): ?int { return $this->statusCode; }
    public function getMessage(): string { return $this->message; }
    public function getExceptionClass(): ?string { return $this->exceptionClass; }
    public function getFile(): ?string { return $this->file; }
    public function getLine(): ?int { return $this->line; }
    public function getTrace(): ?string { return $this->trace; }
    public function getMethod(): ?string { return $this->method; }
    public function getPath(): ?string { return $this->path; }
    public function getReferrer(): ?string { return $this->referrer; }
    public function getUserEmail(): ?string { return $this->userEmail; }
    public function getIp(): ?string { return $this->ip; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getCreatedAt(): int { return $this->createdAt; }
}
