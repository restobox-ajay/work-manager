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
    ) {
    }

    /** @return list<ConfigField> */
    public function listedFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ConfigField $field) => $field->listed));
    }
}
