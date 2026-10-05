<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Backs `invoice`.`status` (ADR-077). A cancelled invoice stays on record (its number is never reused) but no longer
 * holds its tasks, so they can be invoiced again.
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued    => 'Issued',
            self::Cancelled => 'Cancelled',
        };
    }
}
