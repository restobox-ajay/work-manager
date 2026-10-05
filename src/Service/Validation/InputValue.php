<?php

declare(strict_types=1);

namespace App\Service\Validation;

/**
 * Reading a submitted value, once.
 *
 * Every write path starts by turning what a browser or a JSON body sent
 * into the type the column holds, and the same four questions came up at
 * each one: is a blank text input the empty string or NULL, is an absent
 * checkbox false, is "2026-13-45" a date or a fatal. Nine classes answered
 * them with their own `nullableString()`/`nullableInt()`, and they had not
 * all answered the same way.
 *
 * Static because these are pure conversions with no collaborators -- the
 * exception CLAUDE.md's "avoid static methods unless genuinely
 * appropriate" leaves room for, and the shape TaskWriteData already used.
 */
final class InputValue
{
    /**
     * '' means "cleared" on every surface in this app: the value the web
     * forms have always stored for an emptied text input is NULL, and it
     * was the API writing the empty string straight through that made the
     * same cleared field read back differently depending on the door.
     *
     * A non-scalar (an array where a string was expected, which only a
     * crafted JSON body produces) is "no value" rather than a TypeError on
     * the cast -- ClientWriteService already read it that way and the
     * others fatalled.
     */
    public static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    public static function int(mixed $value): ?int
    {
        $value = self::text($value);

        return $value !== null ? (int) $value : null;
    }

    /**
     * @return int[]
     */
    public static function ints(mixed $value): array
    {
        return array_values(array_map('intval', (array) ($value ?? [])));
    }

    /**
     * filter_var's reading, which is also ParameterBag::getBoolean()'s, so
     * a form post and a JSON body agree on what "1"/"true"/"on" mean.
     */
    public static function flag(mixed $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    /**
     * An unparseable date is dropped rather than fatal: a form that used
     * strtotime() straight into an int setter made junk a 500 on the web
     * and a null on the API. A surface that must *report* an unparseable
     * date keeps the raw string and validates it (DocumentInput's shape) --
     * this is for the ones that have always dropped it.
     */
    public static function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? $timestamp : null;
    }

    public static function date(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
