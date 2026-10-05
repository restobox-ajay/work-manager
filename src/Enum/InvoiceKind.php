<?php

declare(strict_types=1);

namespace App\Enum;

/** Backs `invoice`.`kind` (ADR-077): lines typed by hand, or lines made from approved tasks. */
enum InvoiceKind: string
{
    case Independent = 'independent';
    case Tasks = 'tasks';

    public function label(): string
    {
        return match ($this) {
            self::Independent => 'Independent Invoice',
            self::Tasks       => 'Approved Tasks Invoice',
        };
    }
}
