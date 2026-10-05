<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\InvoiceItem;
use App\Service\Validation\InputValue;

/**
 * One posted invoice line, as typed (strings), plus the task it was made from, if any.
 */
final class InvoiceLineInput
{
    public function __construct(
        public ?int $taskId = null,
        public string $name = '',
        public string $description = '',
        public string $gstRate = '0',
        public string $quantity = '1',
        public string $rate = '',
        /** Typed by hand, it wins over Quantity × Rate; blank = worked out. */
        public string $amount = '',
    ) {
    }

    /** @param array<string, mixed> $posted */
    public static function fromPosted(array $posted): self
    {
        return new self(
            InputValue::int($posted['taskId'] ?? null),
            InputValue::text($posted['name'] ?? null) ?? '',
            is_scalar($posted['description'] ?? null) ? trim((string) $posted['description']) : '',
            InputValue::text($posted['gstRate'] ?? null) ?? '0',
            InputValue::text($posted['quantity'] ?? null) ?? '',
            InputValue::text($posted['rate'] ?? null) ?? '',
            InputValue::text($posted['amount'] ?? null) ?? '',
        );
    }

    public static function fromItem(InvoiceItem $item): self
    {
        return new self($item->getTaskId(), $item->getName(), (string) $item->getDescription(), self::trim($item->getGstRate()),
            self::trim($item->getQuantity()), $item->getRate(), $item->getAmount());
    }

    /** A row the person left completely empty is dropped rather than reported. */
    public function isBlank(): bool
    {
        return $this->taskId === null && $this->name === '' && $this->description === '' && $this->rate === '' && $this->amount === '';
    }

    /** "1.00" → "1", "18.50" → "18.5": how a whole quantity or rate reads on the form. */
    private static function trim(?string $decimal): string
    {
        $decimal ??= '';

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
