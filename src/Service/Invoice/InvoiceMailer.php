<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\Invoice;
use App\Entity\User;
use App\Repository\Settings\EmailTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Emails an invoice with its PDF attached (ADR-077). The message starts from the "invoice" email template (Config ›
 * Email Templates) when there is one, and can be edited before sending. Replies go to the Billed By email.
 */
final class InvoiceMailer
{
    public const TEMPLATE_MODULE = 'invoice';
    private const MAX_RECIPIENTS = 10;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly InvoicePdfRenderer $pdf,
        private readonly EmailTemplateRepository $templates,
        private readonly InvoiceHistory $history,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{to: string, cc: string, subject: string, message: string} the form's starting values */
    public function draft(Invoice $invoice): array
    {
        $template = $this->templates->findByModule(self::TEMPLATE_MODULE);
        $subject = $template?->getSubject() ?? 'Invoice %invoice_number% from %billed_by%';
        $message = $template?->getBody() ?? "Hello %client_name%,\n\nPlease find attached invoice %invoice_number% dated %invoice_date% for %total%.\n\nThank you,\n%billed_by%";
        $replacements = [
            '%invoice_number%' => $invoice->getNumber(),
            '%client_name%'    => $invoice->getToName(),
            '%billed_by%'      => $invoice->getFromName(),
            '%total%'          => InvoiceMoney::display($invoice->getTotal(), $invoice->getCurrency()),
            '%invoice_date%'   => $invoice->getInvoiceDate()?->format('M j, Y') ?? '',
            '%due_date%'       => $invoice->getDueDate()?->format('M j, Y') ?? '',
        ];

        return [
            'to'      => (string) $invoice->getToEmail(),
            'cc'      => '',
            'subject' => strtr($subject, $replacements),
            'message' => strtr($message, $replacements),
        ];
    }

    /**
     * @param array{to: string, cc: string, subject: string, message: string} $values
     *
     * @return list<string> errors; empty when sent
     */
    public function send(Invoice $invoice, array $values, User $actor): array
    {
        if ($invoice->isCancelled()) {
            return ['A cancelled invoice cannot be emailed.'];
        }
        $to = $this->addresses($values['to']);
        $cc = $this->addresses($values['cc']);
        $errors = [];
        if ($to === []) {
            $errors[] = 'Enter at least one To address.';
        }
        foreach ([...$to, ...$cc] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = sprintf('"%s" is not a valid email address.', $address);
            }
        }
        if (count($to) + count($cc) > self::MAX_RECIPIENTS) {
            $errors[] = sprintf('Send to at most %d addresses.', self::MAX_RECIPIENTS);
        }
        if ($values['subject'] === '' || mb_strlen($values['subject']) > 255) {
            $errors[] = 'Enter a subject of at most 255 characters.';
        }
        if ($values['message'] === '') {
            $errors[] = 'Enter a message.';
        }
        if (!$this->pdf->isAvailable()) {
            $errors[] = (new InvoicePdfUnavailable())->getMessage();
        }
        if ($errors !== []) {
            return $errors;
        }

        $email = (new Email())
            ->to(...$to)
            ->subject($values['subject'])
            ->text($values['message'])
            ->attach($this->pdf->render($invoice), $this->pdf->fileName($invoice), 'application/pdf');
        if ($cc !== []) {
            $email->cc(...$cc);
        }
        if ($invoice->getFromEmail() !== null) {
            $email->replyTo(new Address($invoice->getFromEmail(), $invoice->getFromName()));
        }
        $recipients = 'to '.implode(', ', $to).($cc !== [] ? '; cc '.implode(', ', $cc) : '');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Invoice email failed', ['invoice' => $invoice->getId(), 'error' => $exception->getMessage()]);
            $this->history->record($invoice, InvoiceHistory::EMAIL_FAILED, $recipients, $actor);

            return ['The email could not be sent. Check the mail settings and try again.'];
        }

        $invoice->setEmailedAt(time());
        $this->em->flush();
        $this->history->record($invoice, InvoiceHistory::EMAILED, $recipients.' — '.$values['subject'], $actor);

        return [];
    }

    /** @return list<string> "a@x.com, b@y.com" → the addresses, de-duplicated */
    private function addresses(string $list): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', $list) ?: []))));
    }
}
