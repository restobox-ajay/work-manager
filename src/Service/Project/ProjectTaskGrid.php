<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Security\Work\WorkAccess;
use App\Service\Task\TaskInput;
use App\Service\Task\TaskService;
use App\Service\Validation\InputValue;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The tasks grid on a project's page and its edit form (ADR-083), after work-platform's ProjectTaskDraftService:
 * one row per task — name, type, contractor, due date, status, status detail and, for someone who may set
 * fees, billable time, billable date, currency and payout. The edit form lists the project's tasks as rows plus new
 * ones; the project page adds new tasks only.
 *
 * - Every row is written through TaskService, so the task form's own rules (access, assignable status, fee gate)
 *   apply unchanged.
 * - An approved or paid task, or one the viewer may not update, is shown read-only and never written — recomputed
 *   from the database on every request, never trusted from the posted row.
 * - A new row needs a name, a type and a contractor (and a payout where fees can be set); a completely empty new row
 *   is ignored. An existing task's row needs its name. All rows are checked before any is written, and the writes share one transaction: all or none.
 */
final class ProjectTaskGrid
{
    /** Posted per row. Contractor, reviewer, billable time and billable date are not on the grid (ADR-100): a task keeps its values. */
    public const FIELDS = ['name', 'taskTypeId', 'dueDate', 'taskStatusId', 'statusDetail', 'currencyId', 'totalAmount'];
    private const FEE_FIELDS = ['currencyId', 'totalAmount'];
    private const MAX_ROWS = 200;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskService $taskService,
        private readonly WorkAccess $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> a new row: status Pending, currency USD */
    public function blankRow(User $viewer): array
    {
        return ['existingTaskId' => null, 'name' => '', 'taskTypeId' => null, 'dueDate' => '',
            'taskStatusId' => TaskStatus::PENDING_ID, 'statusDetail' => '',
            'currencyId' => 1, 'totalAmount' => ''];
    }

    /** @return list<array<string, mixed>> the project's live tasks as rows, oldest first */
    public function existingRows(Project $project): array
    {
        $rows = [];
        foreach ($this->tasks->findAllMatching(['projectId' => (int) $project->getId(), 'includeArchived' => true], 't.id', 'ASC') as $task) {
            $rows[] = $this->rowFor($task);
        }

        return $rows;
    }

    /**
     * The posted rows, in order, with every field present.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(mixed $posted): array
    {
        $rows = [];
        foreach (is_array($posted) ? $posted : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = ['existingTaskId' => InputValue::int($row['existingTaskId'] ?? null)];
            foreach (self::FIELDS as $field) {
                $clean[$field] = is_scalar($row[$field] ?? null) ? trim((string) $row[$field]) : '';
            }
            $rows[] = $clean;
        }

        return array_slice($rows, 0, self::MAX_ROWS);
    }

    /**
     * Task id => read-only for this viewer (approved, paid, gone, or not theirs to update).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, bool>
     */
    public function lockedTaskIds(array $rows, User $viewer): array
    {
        $locked = [];
        foreach ($this->tasksOf($rows) as $id => $task) {
            $locked[$id] = $task === null
                || in_array($task->getTaskStatusId(), [TaskStatus::APPROVED_ID, TaskStatus::PAID_ID], true)
                || !$this->access->canUpdateTask($viewer, $task);
        }

        return $locked;
    }

    /** Whether the fee columns are shown and written (the project-level fee gate, as on the task form). */
    public function canSetFees(User $viewer, Project $project): bool
    {
        return $this->access->canSetFees($viewer, $project);
    }

    /**
     * Saves every row; nothing is written unless all rows are valid.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{errors: list<string>, created: int, updated: int}
     */
    public function save(Project $project, array $rows, User $viewer): array
    {
        $locked = $this->lockedTaskIds($rows, $viewer);
        $tasks = $this->tasksOf($rows);
        $fees = $this->canSetFees($viewer, $project);

        $writes = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $label = sprintf('Task row %d', $index + 1);
            $taskId = $row['existingTaskId'];
            if ($taskId !== null) {
                $task = $tasks[$taskId] ?? null;
                if ($locked[$taskId] ?? true) {
                    continue; // read-only rows post nothing that is used
                }
                if ($task?->getProject()?->getId() !== $project->getId()) {
                    $errors[] = sprintf('%s: that task belongs to another project.', $label);
                    continue;
                }
            } elseif ($this->isBlank($row)) {
                continue;
            }

            $rowErrors = $this->rowProblems($row, $taskId === null, $fees);
            if ($rowErrors !== []) {
                $errors[] = sprintf('%s: %s.', $label, implode(', ', $rowErrors));
                continue;
            }

            $values = array_intersect_key($row, array_flip(self::FIELDS));
            if (!$fees) {
                $values = array_diff_key($values, array_flip(self::FEE_FIELDS));
            }
            if ($taskId !== null && $this->unchanged($tasks[$taskId], $values)) {
                continue; // nothing edited on this row: no write, no audit entry
            }
            $values['projectId'] = $project->getId();
            if ($taskId === null) {
                $values['reviewerUserId'] = $viewer->getId(); // not shown on the grid; a new task's reviewer is its creator
            }
            $writes[] = [$label, $taskId !== null ? $tasks[$taskId] : null, $values];
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'created' => 0, 'updated' => 0];
        }

        // One transaction for every row: if any write is refused, none of them stays. TaskService flushes per task,
        // so on a refusal the new tasks are detached and the changed ones reloaded from the rolled-back database.
        $counts = ['created' => 0, 'updated' => 0];
        $touched = [];
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($writes as [$label, $task, $values]) {
                $result = $task === null
                    ? $this->taskService->create((new TaskInput())->overlay($values), $viewer)
                    : $this->taskService->update($task, TaskInput::fromTask($task)->overlay($values), $viewer);
                foreach ($result->errors as $message) {
                    $errors[] = sprintf('%s: %s', $label, $message);
                }
                if ($result->isSaved()) {
                    $touched[] = [$result->record, $task === null];
                    ++$counts[$task === null ? 'created' : 'updated'];
                } elseif ($task !== null) {
                    $touched[] = [$task, false];
                }
            }
            if ($errors !== []) {
                throw new ProjectTaskGridRejected();
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            foreach ($touched as [$task, $isNew]) {
                $isNew ? $this->em->detach($task) : $this->em->refresh($task);
            }
            if (!$exception instanceof ProjectTaskGridRejected) {
                throw $exception;
            }

            return ['errors' => $errors, 'created' => 0, 'updated' => 0];
        }

        return ['errors' => $errors, ...$counts];
    }

    /** @return array<string, mixed> a task as a grid row (every value as the form shows it) */
    private function rowFor(Task $task): array
    {
        return [
            'existingTaskId' => $task->getId(),
            'name'           => $task->getName(),
            'taskTypeId'     => $task->getTaskTypeId(),
            'assigneeId'     => $task->getAssignee()?->getId(),
            'dueDate'        => $task->getDueDate() !== null ? date('Y-m-d', $task->getDueDate()) : '',
            'reviewerUserId' => $task->getReviewerUserId(),
            'taskStatusId'   => $task->getTaskStatusId(),
            'statusDetail'   => (string) $task->getStatusDetail(),
            'timeBudget'     => $task->getTimeBudget() !== null ? (string) $task->getTimeBudget() : '',
            'billableDate'   => $task->getBillableDate()?->format('Y-m-d') ?? '',
            'currencyId'     => $task->getCurrencyId(),
            'totalAmount'    => (string) $task->getTotalAmount(),
        ];
    }

    /**
     * The New Project form's rows, checked before the project is created (so a refused row never leaves a project
     * behind without its tasks): every non-empty row is a new task and must be complete.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    public function checkNewRows(array $rows, bool $fees): array
    {
        $errors = [];
        foreach ($rows as $index => $row) {
            if ($this->isBlank($row)) {
                continue;
            }
            $problems = $this->rowProblems($row, true, $fees);
            if ($problems !== []) {
                $errors[] = sprintf('Task row %d: %s.', $index + 1, implode(', ', $problems));
            }
        }

        return $errors;
    }

    /**
     * What a row is missing. A new row must be complete; an existing task keeps whatever it already lacks (older
     * tasks may have no type or contractor yet), so editing one field never forces filling in the rest.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function rowProblems(array $row, bool $isNew, bool $fees): array
    {
        $problems = [];
        if ($row['name'] === '') {
            $problems[] = 'enter the task name';
        }
        if ($isNew) {
            if ($row['taskTypeId'] === '') {
                $problems[] = 'choose the task type';
            }
            if ($fees && $row['totalAmount'] === '') {
                $problems[] = 'enter the payout';
            }
        }

        return $problems;
    }

    /** @param array<string, mixed> $values the row's posted fields */
    private function unchanged(Task $task, array $values): bool
    {
        $current = $this->rowFor($task);
        foreach ($values as $field => $value) {
            if ((string) ($current[$field] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function isBlank(array $row): bool
    {
        // The defaults a new row starts with (status, currency) do not count as filling it in.
        foreach (['name', 'taskTypeId', 'dueDate', 'statusDetail', 'totalAmount'] as $field) {
            if ($row[$field] !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, Task|null>
     */
    private function tasksOf(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row) => $row['existingTaskId'] ?? null, $rows))));
        $found = [];
        foreach ($ids === [] ? [] : $this->tasks->findBy(['id' => $ids]) as $task) {
            $found[(int) $task->getId()] = $task;
        }
        $byId = [];
        foreach ($ids as $id) {
            $byId[$id] = $found[$id] ?? null;
        }

        return $byId;
    }
}
