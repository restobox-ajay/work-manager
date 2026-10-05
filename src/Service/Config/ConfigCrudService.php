<?php

declare(strict_types=1);

namespace App\Service\Config;

use App\Entity\User;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteValidator;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one CRUD behind every Config list (ADR-075), after work-platform's LookupCrudService: list, read a row into
 * form values, validate (the form's field rules, then the entity's own constraints), save, delete.
 */
final class ConfigCrudService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WriteValidator $validator,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    /** @return list<object> */
    public function rows(ConfigListDefinition $list): array
    {
        return $this->em->getRepository($list->entityClass)->findBy([], $list->order);
    }

    public function find(ConfigListDefinition $list, int $id): ?object
    {
        return $this->em->getRepository($list->entityClass)->find($id);
    }

    /** @return array<string, mixed> */
    public function valuesFrom(ConfigListDefinition $list, object $row): array
    {
        $values = [];
        foreach ($list->fields as $field) {
            $value = $row->{$field->getter}();
            $values[$field->name] = $field->toForm !== null ? ($field->toForm)($value) : $value;
        }

        return $values;
    }

    /**
     * Creates ($row null) or updates a row from the posted fields.
     *
     * @param array<string, mixed> $posted
     *
     * @return list<string> errors; empty when saved
     */
    public function save(ConfigListDefinition $list, ?object $row, array $posted, User $actor): array
    {
        $values = $this->normalize($list, $posted);
        if ($list->prepare !== null) {
            $values = ($list->prepare)($values);
        }
        $errors = $this->checkFields($list, $values, $row);
        if ($list->check !== null) {
            $errors = [...$errors, ...($list->check)($values)];
        }
        if ($errors !== []) {
            return $errors;
        }

        $isNew = $row === null;
        $row ??= new ($list->entityClass)();
        foreach ($list->fields as $field) {
            $value = $values[$field->name];
            $row->{$field->setter}($field->fromForm !== null ? ($field->fromForm)($value) : $value);
        }

        // The entity's own constraints (work-platform's #[Assert] rules) have the last word.
        $errors = $this->validator->checkObject($row);
        if ($errors !== []) {
            if (!$isNew) {
                $this->em->refresh($row);
            }

            return $errors;
        }

        if ($isNew) {
            $this->em->persist($row);
        }
        $this->em->flush();

        $this->audit->record($actor, sprintf('config.%s_%s', $list->kind, $isNew ? 'create' : 'update'), $this->describe($list, $row));

        return [];
    }

    public function delete(ConfigListDefinition $list, object $row, User $actor): void
    {
        $description = $this->describe($list, $row);
        $this->em->remove($row);
        $this->em->flush();

        $this->audit->record($actor, sprintf('config.%s_delete', $list->kind), $description);
    }

    /**
     * Posted strings → the types the setters take. A blank optional text is NULL; a blank number is 0; an absent
     * checkbox is false.
     *
     * @param array<string, mixed> $posted
     *
     * @return array<string, mixed>
     */
    private function normalize(ConfigListDefinition $list, array $posted): array
    {
        $values = [];
        foreach ($list->fields as $field) {
            $raw = $posted[$field->name] ?? null;
            $values[$field->name] = match ($field->type) {
                ConfigField::CHECKBOX => InputValue::flag($raw),
                ConfigField::INTEGER  => InputValue::int($raw) ?? 0,
                ConfigField::USER     => InputValue::int($raw),
                ConfigField::DECIMAL  => InputValue::text($raw) !== null ? str_replace(',', '', (string) InputValue::text($raw)) : null,
                // Free text keeps its line breaks; only an all-blank value counts as empty.
                ConfigField::TEXTAREA => is_scalar($raw) && trim((string) $raw) !== '' ? (string) $raw : null,
                default               => InputValue::text($raw),
            };
            if ($values[$field->name] === null && $field->required && in_array($field->type, [ConfigField::TEXT, ConfigField::TEXTAREA, ConfigField::CHOICE], true)) {
                $values[$field->name] = '';
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private function checkFields(ConfigListDefinition $list, array $values, ?object $row): array
    {
        $errors = [];
        $repository = $this->em->getRepository($list->entityClass);

        foreach ($list->fields as $field) {
            $value = $values[$field->name];
            if ($field->required && ($value === null || $value === '')) {
                $errors[] = sprintf('%s is required.', $field->label);
                continue;
            }
            if ($field->maxLength !== null && is_string($value) && mb_strlen($value) > $field->maxLength) {
                $errors[] = sprintf('%s cannot be longer than %d characters.', $field->label, $field->maxLength);
            }
            if ($field->type === ConfigField::DECIMAL && $value !== null && !is_numeric($value)) {
                $errors[] = sprintf('%s must be a number.', $field->label);
            }
            if ($field->type === ConfigField::CHOICE && $value !== '' && !array_key_exists((string) $value, $field->choices)) {
                $errors[] = sprintf('Choose a valid %s.', mb_strtolower($field->label));
            }
            if ($field->unique !== null && $value !== null && $value !== '') {
                $other = $repository->findOneBy([$field->name => $value]);
                if ($other !== null && $other !== $row) {
                    $errors[] = $field->unique;
                }
            }
        }

        return $errors;
    }

    private function describe(ConfigListDefinition $list, object $row): string
    {
        $first = $list->fields[0] ?? null;
        $label = $first !== null ? (string) (($first->toForm ?? static fn ($v) => $v)($row->{$first->getter}())) : '';
        foreach ($list->fields as $field) {
            if (in_array($field->name, ['name', 'shortName', 'companyName', 'module', 'code'], true)) {
                $label = (string) $row->{$field->getter}();
                break;
            }
        }

        return sprintf('#%d %s', (int) $row->getId(), $label);
    }
}
