<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Entity\Settings\Currency;
use App\Entity\Settings\TaskStatus;
use App\Entity\Settings\TaskType;
use App\Entity\User;
use App\Repository\Settings\CurrencyRepository;
use App\Repository\Settings\TaskStatusRepository;
use App\Repository\Settings\TaskTypeRepository;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteValidator;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The task reference lists an admin maintains (ADR-070): statuses, types and currencies. Rows are never deleted —
 * tasks point at them by id — only switched off (statuses, types) or edited.
 */
final class WorkSettingsService
{
    /** Each list's names, as the page, the tabs and the popup say them. */
    public const LABELS = [
        'task-statuses' => ['plural' => 'Task Statuses', 'singular' => 'Task status'],
        'task-types'    => ['plural' => 'Task Types', 'singular' => 'Task type'],
        'currencies'    => ['plural' => 'Currencies', 'singular' => 'Currency'],
    ];

    public function __construct(
        private readonly TaskStatusRepository $statuses,
        private readonly TaskTypeRepository $types,
        private readonly CurrencyRepository $currencies,
        private readonly WriteValidator $validator,
        private readonly WorkAuditTrail $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<TaskStatus|TaskType|Currency> */
    public function rows(string $kind): array
    {
        return match ($kind) {
            'task-statuses' => $this->statuses->findAllOrdered(),
            'task-types'    => $this->types->findAllOrdered(),
            'currencies'    => $this->currencies->findAllOrdered(),
        };
    }

    public function find(string $kind, int $id): TaskStatus|TaskType|Currency|null
    {
        return match ($kind) {
            'task-statuses' => $this->statuses->find($id),
            'task-types'    => $this->types->find($id),
            'currencies'    => $this->currencies->find($id),
        };
    }

    /**
     * A row in the popup form's field shape.
     *
     * @return array<string, mixed>
     */
    public function valuesFrom(TaskStatus|TaskType|Currency $row): array
    {
        return match (true) {
            $row instanceof TaskStatus => ['name' => $row->getName(), 'order' => $row->getOrder(), 'isActive' => $row->isActive()],
            $row instanceof TaskType   => ['name' => $row->getName(), 'description' => $row->getDescription(), 'isActive' => $row->isStatus()],
            default                    => ['name' => $row->getShortName(), 'fxRate' => $row->getFxRate()],
        };
    }

    /**
     * An empty popup's starting values: active, and a new status goes last.
     *
     * @return array<string, mixed>
     */
    public function defaults(string $kind): array
    {
        return match ($kind) {
            'task-statuses' => [
                'isActive' => true,
                'order'    => array_reduce($this->statuses->findAllOrdered(), static fn (int $max, TaskStatus $s) => max($max, (int) $s->getOrder()), 0) + 1,
            ],
            'task-types'    => ['isActive' => true],
            'currencies'    => ['fxRate' => '1.00'],
        };
    }

    /**
     * Creates ($row null) or updates a row from the posted fields.
     *
     * @param array<string, mixed> $values
     *
     * @return list<string> errors; empty when saved
     */
    public function save(string $kind, TaskStatus|TaskType|Currency|null $row, array $values, User $actor): array
    {
        $name = InputValue::text($values['name'] ?? null) ?? '';

        $errors = match ($kind) {
            'task-statuses' => $this->validator->checkFields(['name' => $name], ['name' => [
                new Assert\NotBlank(message: 'Name is required.'),
                new Assert\Length(max: 50, maxMessage: 'Name cannot be longer than {{ limit }} characters.'),
            ]]),
            'task-types' => $this->validator->checkFields(
                ['name' => $name, 'description' => InputValue::text($values['description'] ?? null)],
                [
                    'name'        => [new Assert\NotBlank(message: 'Name is required.'), new Assert\Length(max: 60, maxMessage: 'Name cannot be longer than {{ limit }} characters.')],
                    'description' => [new Assert\Length(max: 255, maxMessage: 'Description cannot be longer than {{ limit }} characters.')],
                ],
            ),
            'currencies' => $this->validator->checkFields(
                ['name' => $name, 'fxRate' => InputValue::text($values['fxRate'] ?? null)],
                [
                    'name'   => [new Assert\NotBlank(message: 'Short name is required.'), new Assert\Length(max: 10, maxMessage: 'Short name cannot be longer than {{ limit }} characters.')],
                    // The FX rate multiplies every converted amount: a non-number must never reach the column.
                    'fxRate' => [new Assert\NotBlank(message: 'FX rate is required.'), new Assert\Type(type: 'numeric', message: 'FX rate must be a number.'), new Assert\PositiveOrZero(message: 'FX rate cannot be negative.')],
                ],
            ),
        };
        if ($errors !== []) {
            return $errors;
        }

        $isNew = $row === null;
        $row ??= match ($kind) {
            'task-statuses' => new TaskStatus(),
            'task-types'    => new TaskType(),
            'currencies'    => new Currency(),
        };

        if ($row instanceof TaskStatus) {
            $row->setName($name)
                ->setIsActive(InputValue::flag($values['isActive'] ?? false))
                ->setOrder(InputValue::int($values['order'] ?? null) ?? 0);
        } elseif ($row instanceof TaskType) {
            $row->setName($name)
                ->setDescription(InputValue::text($values['description'] ?? null))
                ->setStatus(InputValue::flag($values['isActive'] ?? false));
        } else {
            $row->setShortName(strtoupper($name))
                ->setFxRate(number_format((float) InputValue::text($values['fxRate'] ?? null), 2, '.', ''));
        }

        if ($isNew) {
            $this->em->persist($row);
        }
        $this->em->flush();

        $this->audit->record($actor, sprintf('settings.%s_%s', $kind, $isNew ? 'create' : 'update'), sprintf('#%d %s', (int) $row->getId(), $name));

        return [];
    }
}
