<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/**
 * Invoice arithmetic is done in whole hundredths (cents for money, hundredths for quantity and GST %), never in
 * floats, so a line or a total never drifts by a rounding error (ADR-077). This converts to and from the
 * two-decimal strings the form posts and the database stores.
 */
final class InvoiceMoney
{
    /** Symbols printed before an amount; any other currency prints its code. */
    private const SYMBOLS = ['USD' => '$', 'CAD' => '$', 'AUD' => '$', 'INR' => '₹', 'EUR' => '€', 'GBP' => '£'];

    /** @return list<string> the currency codes this class has a symbol for, e.g. for a currency select */
    public static function knownCurrencies(): array
    {
        return array_keys(self::SYMBOLS);
    }

    /** "1,250.5" → 125050; null when it is not a number with at most two decimals. Thousands commas are allowed. */
    public static function toHundredths(?string $value): ?int
    {
        $value = str_replace([',', ' '], '', trim((string) $value));
        if (preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }
        $hundredths = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$hundredths : $hundredths;
    }

    /** 125050 → "1250.50" (the stored form). */
    public static function toDecimal(int $hundredths): string
    {
        $sign = $hundredths < 0 ? '-' : '';
        $hundredths = abs($hundredths);

        return sprintf('%s%d.%02d', $sign, intdiv($hundredths, 100), $hundredths % 100);
    }

    /** "1250.50", "USD" → "$1,250.50". */
    public static function display(string|int|null $amount, string $currency): string
    {
        $hundredths = is_int($amount) ? $amount : (self::toHundredths($amount ?? '0') ?? 0);
        $symbol = self::SYMBOLS[strtoupper($currency)] ?? strtoupper($currency).' ';
        $sign = $hundredths < 0 ? '-' : '';

        return $sign.$symbol.number_format(abs($hundredths) / 100, 2, '.', ',');
    }

    /** a × b where both are in hundredths, rounded half away from zero to hundredths. */
    public static function multiply(int $a, int $b): int
    {
        return self::divideRounded($a * $b, 100);
    }

    /** $value × $percentHundredths % ÷ $parts, rounded to hundredths (GST split into CGST and SGST). */
    public static function percentOf(int $value, int $percentHundredths, int $parts = 1): int
    {
        return self::divideRounded($value * $percentHundredths, 10000 * $parts);
    }

    private static function divideRounded(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        if (abs($remainder) * 2 >= $denominator) {
            $quotient += $numerator < 0 ? -1 : 1;
        }

        return $quotient;
    }
}
