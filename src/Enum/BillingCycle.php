<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How often a subscription is billed (ADR-091). Recurring cycles know their length in months, which drives the
 * monthly / yearly cost equivalents and moving the renewal date forward when a renewal is paid.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';
    case OneTime = 'one_time';
    case Lifetime = 'lifetime';

    public function label(): string
    {
        return match ($this) {
            self::Monthly    => 'Monthly',
            self::Quarterly  => 'Quarterly',
            self::HalfYearly => 'Every 6 months',
            self::Yearly     => 'Yearly',
            self::OneTime    => 'One-time',
            self::Lifetime   => 'Lifetime',
        };
    }

    /** Months between renewals; null when it never renews. */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly    => 1,
            self::Quarterly  => 3,
            self::HalfYearly => 6,
            self::Yearly     => 12,
            default          => null,
        };
    }

    public function isRecurring(): bool
    {
        return $this->months() !== null;
    }

    /** @return array<string, string> value => label, for selects */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->label();
        }

        return $choices;
    }
}
