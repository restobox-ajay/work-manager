<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Messenger\EmailLogStamp;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope as MessengerEnvelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Email Log (ADR-093): every email the app sends, from Symfony Mailer's and Messenger's own events, so no sender has
 * to remember to log.
 *
 *  - Queued (Mailer hands it to Messenger): the row is written as "queued" and its id rides along as an
 *    EmailLogStamp. (Mailer fires this event on a copy of the email, so a header added here would be lost.)
 *  - Sent by the worker: the worker events read the stamp; while that message is handled, the transport's own
 *    MessageEvent tags the email it is about to send with an X-Email-Log-Id header, and SentMessageEvent /
 *    FailedMessageEvent (which see that same email object) update the row. A retry that later succeeds ends "sent".
 *  - Sent directly (no queue): the transport's MessageEvent writes the row and tags the email the same way.
 *
 * Plain DBAL, so the log never flushes someone else's pending entities. Bodies are not stored (sign-in tokens).
 */
final class EmailLogSubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-Email-Log-Id';

    /** The log row of the queued email the worker is handling right now, if any. */
    private ?int $workerLogId = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After other MessageEvent listeners (which may still set recipients or subject).
        return [
            MessageEvent::class              => ['onMessage', -255],
            SentMessageEvent::class          => 'onSent',
            FailedMessageEvent::class        => 'onFailed',
            WorkerMessageReceivedEvent::class => 'onWorkerReceived',
            WorkerMessageHandledEvent::class  => 'onWorkerDone',
            WorkerMessageFailedEvent::class   => 'onWorkerFailed',
        ];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Message || $message->getHeaders()->has(self::HEADER)) {
            return;
        }
        $headers = $message->getHeaders();
        if (!$event->isQueued() && $this->workerLogId !== null) {
            $headers->addTextHeader(self::HEADER, (string) $this->workerLogId); // the queued row: Sent/Failed update it

            return;
        }
        $email = $message instanceof Email ? $message : null;
        $this->safely(function () use ($headers, $email, $event) {
            $now = time();
            $this->connection->insert('email_log', [
                'status'      => 'queued',
                'sender'      => self::cut(self::addresses($email !== null && $email->getFrom() !== [] ? $email->getFrom() : [$event->getEnvelope()->getSender()]), 255),
                'recipients'  => self::addresses($email !== null && $email->getTo() !== [] ? $email->getTo() : $event->getEnvelope()->getRecipients()),
                'cc'          => $email !== null && $email->getCc() !== [] ? self::addresses($email->getCc()) : null,
                'bcc'         => $email !== null && $email->getBcc() !== [] ? self::addresses($email->getBcc()) : null,
                'subject'     => self::cut((string) ($email?->getSubject() ?? $headers->getHeaderBody('Subject') ?? '(no subject)'), 255),
                'attachments' => $email !== null && $email->getAttachments() !== []
                    ? json_encode(array_map(static fn ($part) => (string) ($part->getFilename() ?? 'attachment'), $email->getAttachments())) : null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $id = (int) $this->connection->lastInsertId();
            if ($event->isQueued()) {
                $event->addStamp(new EmailLogStamp($id));
            } else {
                $headers->addTextHeader(self::HEADER, (string) $id);
            }
        });
    }

    public function onSent(SentMessageEvent $event): void
    {
        $sent = $event->getMessage();
        $id = self::logId($sent->getOriginalMessage());
        if ($id !== null) {
            $this->safely(fn () => $this->connection->update('email_log',
                ['status' => 'sent', 'message_id' => self::cut($sent->getMessageId(), 255), 'error' => null, 'updated_at' => time()], ['id' => $id]));
        }
    }

    public function onFailed(FailedMessageEvent $event): void
    {
        $id = self::logId($event->getMessage());
        if ($id !== null) {
            $this->safely(fn () => $this->connection->update('email_log',
                ['status' => 'failed', 'error' => self::cut($event->getError()->getMessage(), 2000), 'updated_at' => time()], ['id' => $id]));
        }
    }

    public function onWorkerReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->workerLogId = self::stampedId($event->getEnvelope());
    }

    public function onWorkerDone(WorkerMessageHandledEvent $event): void
    {
        $this->workerLogId = null;
    }

    /** The worker gave up on (or will retry) a queued email; SentMessage/FailedMessage events may not have run. */
    public function onWorkerFailed(WorkerMessageFailedEvent $event): void
    {
        $this->workerLogId = null;
        $id = self::stampedId($event->getEnvelope());
        if ($id === null) {
            return;
        }
        $error = $event->getThrowable();
        $text = ($event->willRetry() ? 'Will retry: ' : '').($error->getPrevious()?->getMessage() ?? $error->getMessage());
        $this->safely(fn () => $this->connection->update('email_log',
            ['status' => $event->willRetry() ? 'queued' : 'failed', 'error' => self::cut($text, 2000), 'updated_at' => time()], ['id' => $id]));
    }

    private static function stampedId(MessengerEnvelope $envelope): ?int
    {
        $stamp = $envelope->getMessage() instanceof SendEmailMessage ? $envelope->last(EmailLogStamp::class) : null;

        return $stamp instanceof EmailLogStamp ? $stamp->logId : null;
    }

    private static function logId(RawMessage $message): ?int
    {
        $value = $message instanceof Message ? $message->getHeaders()->getHeaderBody(self::HEADER) : null;

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** @param iterable<Address> $addresses */
    private static function addresses(iterable $addresses): string
    {
        $list = [];
        foreach ($addresses as $address) {
            $list[] = $address->toString();
        }

        return implode(', ', $list);
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /** Logging must never stop an email from going out. */
    private function safely(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $failure) {
            error_log('[email_log] could not record an email: '.$failure->getMessage());
        }
    }
}
