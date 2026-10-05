<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\Invoice;
use App\Service\Validation\InputValue;

/**
 * The invoice form as posted (ADR-077): header, From and To blocks, notes and lines. Values stay strings until
 * InvoiceService validates them, so a rejected form comes back exactly as typed.
 */
final class InvoiceInput
{
    /** @var list<InvoiceLineInput> */
    public array $lines = [];

    public ?int $billingProfileId = null;
    public ?int $clientId = null;
    public string $invoiceDate = '';
    public string $dueDate = '';
    public string $currency = 'USD';
    public string $fromName = '';
    public string $fromAddress = '';
    public string $fromEmail = '';
    public string $fromPhone = '';
    public string $fromTaxNumber = '';
    public string $toName = '';
    public string $toAddress = '';
    public string $toEmail = '';
    public string $toPhone = '';
    public string $description = '';

    /** @param array<string, mixed> $posted */
    public static function fromPosted(array $posted): self
    {
        $input = new self();
        $input->billingProfileId = InputValue::int($posted['billingProfileId'] ?? null);
        $input->clientId = InputValue::int($posted['clientId'] ?? null);
        foreach (['invoiceDate', 'dueDate', 'currency', 'fromName', 'fromEmail', 'fromPhone', 'fromTaxNumber', 'toName', 'toEmail', 'toPhone'] as $field) {
            $input->{$field} = InputValue::text($posted[$field] ?? null) ?? '';
        }
        // Multi-line blocks keep their line breaks.
        foreach (['fromAddress', 'toAddress', 'description'] as $field) {
            $input->{$field} = is_scalar($posted[$field] ?? null) ? trim(str_replace("\r\n", "\n", (string) $posted[$field])) : '';
        }
        $input->currency = strtoupper($input->currency);
        foreach (is_array($posted['lines'] ?? null) ? $posted['lines'] : [] as $line) {
            if (is_array($line)) {
                $lineInput = InvoiceLineInput::fromPosted($line);
                if (!$lineInput->isBlank()) {
                    $input->lines[] = $lineInput;
                }
            }
        }

        return $input;
    }

    public static function fromInvoice(Invoice $invoice): self
    {
        $input = new self();
        $input->billingProfileId = $invoice->getBillingProfileId();
        $input->clientId = $invoice->getClientId();
        $input->invoiceDate = $invoice->getInvoiceDate()?->format('Y-m-d') ?? '';
        $input->dueDate = $invoice->getDueDate()?->format('Y-m-d') ?? '';
        $input->currency = $invoice->getCurrency();
        $input->fromName = $invoice->getFromName();
        $input->fromAddress = (string) $invoice->getFromAddress();
        $input->fromEmail = (string) $invoice->getFromEmail();
        $input->fromPhone = (string) $invoice->getFromPhone();
        $input->fromTaxNumber = (string) $invoice->getFromTaxNumber();
        $input->toName = $invoice->getToName();
        $input->toAddress = (string) $invoice->getToAddress();
        $input->toEmail = (string) $invoice->getToEmail();
        $input->toPhone = (string) $invoice->getToPhone();
        $input->description = (string) $invoice->getDescription();
        foreach ($invoice->getItems() as $item) {
            $input->lines[] = InvoiceLineInput::fromItem($item);
        }

        return $input;
    }

    /** @return int[] */
    public function taskIds(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (InvoiceLineInput $line) => $line->taskId, $this->lines))));
    }
}
