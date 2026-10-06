<?php

declare(strict_types=1);

namespace App\Entity\Log;

use App\Repository\Log\EmailLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One outbound email (ADR-093), written by EmailLogSubscriber from Symfony Mailer's events: queued when handed to
 * Messenger, then sent or failed. Bodies are deliberately not kept: password-reset, magic-link and invitation
 * emails carry sign-in tokens.
 */
#[ORM\Entity(repositoryClass: EmailLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'email_log')]
#[ORM\Index(name: 'idx_email_log_created_at', columns: ['created_at'])]
class EmailLog
{
    public const STATUSES = ['queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $status = 'queued';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sender = null;

    #[ORM\Column(type: 'text')]
    private string $recipients = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cc = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bcc = null;

    #[ORM\Column(length: 255)]
    private string $subject = '';

    /** JSON list of attachment file names */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $attachments = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private int $createdAt = 0;

    #[ORM\Column]
    private int $updatedAt = 0;

    public function getId(): ?int { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function getSender(): ?string { return $this->sender; }
    public function getRecipients(): string { return $this->recipients; }
    public function getCc(): ?string { return $this->cc; }
    public function getBcc(): ?string { return $this->bcc; }
    public function getSubject(): string { return $this->subject; }
    public function getMessageId(): ?string { return $this->messageId; }
    public function getError(): ?string { return $this->error; }
    public function getCreatedAt(): int { return $this->createdAt; }
    public function getUpdatedAt(): int { return $this->updatedAt; }

    /** @return list<string> */
    public function getAttachmentNames(): array
    {
        $names = json_decode((string) $this->attachments, true);

        return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }
}
