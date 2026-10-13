<?php

declare(strict_types=1);

namespace App\Service\Config;

/**
 * One field of a Config list (ADR-075), after work-platform's lookup-CRUD field arrays: what the form shows, how the
 * value is read from and written to the entity (getter/setter names), and the checks the form adds on top of the
 * entity's own constraints.
 */
final class ConfigField
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const INTEGER = 'integer';
    public const DECIMAL = 'decimal';
    public const CHECKBOX = 'checkbox';
    public const CHOICE = 'choice';
    public const USER = 'user';

    /**
     * @param array<string, string> $choices CHOICE only: stored value => label
     * @param ?string               $unique  message when another row already holds this value; null = not unique
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $getter,
        public readonly string $setter,
        public readonly string $type = self::TEXT,
        public readonly bool $required = false,
        public readonly ?int $maxLength = null,
        public readonly bool $listed = true,
        public readonly array $choices = [],
        public readonly ?string $unique = null,
        public readonly ?string $help = null,
        /** Converts the stored value to the form value (e.g. an enum to its string); identity when null. */
        public readonly ?\Closure $toForm = null,
        /** Converts the form value to what the setter takes (e.g. a string to an enum); identity when null. */
        public readonly ?\Closure $fromForm = null,
    ) {
    }
}
