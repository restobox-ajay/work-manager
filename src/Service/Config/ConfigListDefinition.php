<?php

declare(strict_types=1);

namespace App\Service\Config;

/**
 * One Config list (ADR-075): its entity, names, fields and order. ConfigListRegistry holds them all; the controller,
 * the service and the two templates are the same for every list.
 */
final class ConfigListDefinition
{
    /**
     * @param class-string           $entityClass
     * @param list<ConfigField>      $fields
     * @param array<string, string>  $order       entity property => ASC|DESC
     * @param ?\Closure              $check       extra rule across fields: fn (array $values): list<string>
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $entityClass,
        public readonly string $plural,
        public readonly string $singular,
        public readonly array $fields,
        public readonly array $order,
        public readonly bool $deletable,
        public readonly string $lead,
        public readonly ?\Closure $check = null,
        /** @var array<string, mixed> starting values of an empty form */
        public readonly array $defaults = [],
        /** Adjusts the normalized values before they are checked and written: fn (array $values): array */
        public readonly ?\Closure $prepare = null,
        /** Few short fields: the form shows them side by side on one row instead of two columns */
        public readonly bool $singleRow = false,
    ) {
    }

    /** @return list<ConfigField> */
    public function listedFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ConfigField $field) => $field->listed));
    }

    /**
     * The whole-number field the list is ordered by first (Order / Sort Order), when there is one: such a list is
     * reordered by dragging its rows (ADR-127). Null for lists ordered by name, module and so on.
     */
    public function sortField(): ?ConfigField
    {
        $first = array_key_first($this->order);
        foreach ($this->fields as $field) {
            if ($field->name === $first && $field->type === ConfigField::INTEGER) {
                return $field;
            }
        }

        return null;
    }
}
