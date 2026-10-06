<?php

declare(strict_types=1);

namespace App\Service\Rent;

use App\Service\Invoice\InvoiceMoney;

/**
 * Rent amounts and meter readings, in whole hundredths like invoices (ADR-085): never floats, so a ledger never drifts.
 */
final class RentMoney
{
    public const CURRENCY = 'INR';
    public const KINDS = ['rent' => 'Rent', 'electricity' => 'Electricity', 'other' => 'Other'];
    public const METHODS = ['cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank transfer', 'cheque' => 'Cheque', 'other' => 'Other'];

    public static function h(?string $decimal): int
    {
        return (int) InvoiceMoney::toHundredths($decimal ?? '0');
    }

    public static function d(int $hundredths): string
    {
        return InvoiceMoney::toDecimal($hundredths);
    }

    /** units × rate, both in hundredths, rounded to the paisa */
    public static function times(int $units, int $rate): int
    {
        return InvoiceMoney::multiply($units, $rate);
    }

    /** a ÷ b in hundredths (e.g. electricity paid ÷ rate = units paid), rounded; 0 when b is 0 */
    public static function divide(int $a, int $b): int
    {
        return $b === 0 ? 0 : (int) round($a * 100 / $b);
    }
}
