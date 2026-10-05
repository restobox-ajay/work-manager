<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Settings\Currency;
use App\Entity\Settings\TaskStatus;
use App\Entity\Settings\TaskType;
use App\Entity\User;
use App\Repository\Settings\CurrencyRepository;
use App\Repository\Settings\TaskStatusRepository;
use App\Repository\Settings\TaskTypeRepository;
use App\Repository\UserRepository;

/**
 * The reference lists every task page shows — statuses, types, currencies, people — loaded once per request
 * and handed to templates as id => label maps, so a table of tasks resolves its ids without a query per row.
 */
final class TaskLookups
{
    /** @var array<string, array<int, string>> */
    private array $maps = [];

    public function __construct(
        private readonly TaskStatusRepository $statuses,
        private readonly TaskTypeRepository $types,
        private readonly CurrencyRepository $currencies,
        private readonly UserRepository $users,
    ) {
    }

    /** @return array<int, string> every status, in order (filters list them all, retired ones included) */
    public function statuses(): array
    {
        return $this->maps['statuses'] ??= $this->map(
            $this->statuses->findAllOrdered(),
            static fn (TaskStatus $status) => $status->getName(),
        );
    }

    /**
     * The statuses a task form may set: active ones, minus those the payment flow owns, plus the task's own
     * current status so editing never silently changes it.
     *
     * @return array<int, string>
     */
    public function assignableStatuses(?int $currentStatusId = null): array
    {
        $assignable = [];
        foreach ($this->statuses->findAllOrdered() as $status) {
            $id = (int) $status->getId();
            if (($status->isActive() && !in_array($id, TaskService::PAYMENT_MANAGED_STATUS_IDS, true)) || $id === $currentStatusId) {
                $assignable[$id] = $status->getName();
            }
        }

        return $assignable;
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return $this->maps['types'] ??= $this->map(
            $this->types->findAllOrdered(),
            static fn (TaskType $type) => $type->getName(),
        );
    }

    /** @return array<int, string> active types, for forms */
    public function activeTypes(?int $currentTypeId = null): array
    {
        $active = [];
        foreach ($this->types->findAllOrdered() as $type) {
            if ($type->isStatus() || $type->getId() === $currentTypeId) {
                $active[(int) $type->getId()] = $type->getName();
            }
        }

        return $active;
    }

    /** @return array<int, string> */
    public function currencies(): array
    {
        return $this->maps['currencies'] ??= $this->map(
            $this->currencies->findAllOrdered(),
            static fn (Currency $currency) => $currency->getShortName(),
        );
    }

    /** @return array<int, string> every account, by name — reviewers/creators are shown by id on old rows too */
    public function people(): array
    {
        return $this->maps['people'] ??= $this->map(
            $this->users->findBy([], ['name' => 'ASC']),
            static fn (User $user) => $user->getName() !== '' ? $user->getName() : $user->getEmail(),
        );
    }

    /** @return array<int, string> active accounts, for the contractor/reviewer pickers */
    public function activePeople(): array
    {
        return $this->maps['activePeople'] ??= $this->map(
            $this->users->findActiveOrderedByName(),
            static fn (User $user) => $user->getName() !== '' ? $user->getName() : $user->getEmail(),
        );
    }

    /**
     * @template T of object
     *
     * @param iterable<T>          $rows
     * @param callable(T): string  $label
     *
     * @return array<int, string>
     */
    private function map(iterable $rows, callable $label): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->getId()] = $label($row);
        }

        return $map;
    }
}
