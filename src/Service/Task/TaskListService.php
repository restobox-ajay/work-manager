<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Security\Work\WorkAccess;
use App\Service\Pagination\Paginated;
use App\Service\Project\ProjectService;
use App\Service\Validation\InputValue;

/**
 * The task list (ADR-070): reads the filters, scopes the query to what the viewer may see, and works out each
 * row's tags and buttons in memory (WorkAccess holds the viewer's relationships, so no query per row).
 */
final class TaskListService
{
    public const PAGE_SIZE = 25;

    /** Query parameters the list understands, as criteria keys for TaskRepository. */
    private const FILTERS = [
        'clientId'       => 'clientId',
        'projectId'      => 'projectId',
        'assigneeId'     => 'assigneeId',
        'reviewerUserId' => 'reviewerUserId',
        'taskStatusId'   => 'taskStatusId',
        'taskTypeId'     => 'taskTypeId',
    ];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly WorkAccess $access,
        private readonly ProjectService $projects,
    ) {
    }

    /**
     * @param array<string, mixed> $query the request's query parameters
     *
     * @return array<string, int|string|null> the filters as the page echoes them back into its form
     */
    public function filtersFrom(array $query): array
    {
        $filters = ['q' => InputValue::text($query['q'] ?? null)];
        foreach (array_keys(self::FILTERS) as $key) {
            $filters[$key] = InputValue::int($query[$key] ?? null);
        }

        return $filters;
    }

    /**
     * @param array<string, int|string|null> $filters from filtersFrom()
     *
     * @return Paginated<Task>
     */
    public function search(User $viewer, array $filters, int $page, ?string $sort, int $pageSize = self::PAGE_SIZE): Paginated
    {
        $criteria = ['visibility' => $this->access->taskVisibility($viewer), 'term' => $filters['q'] ?? null];
        foreach (self::FILTERS as $key => $criterion) {
            $criteria[$criterion] = $filters[$key] ?? null;
        }

        return new Paginated(
            $this->tasks->searchList($criteria, $page, $pageSize, $sort),
            $this->tasks->countList($criteria),
            $page,
            $pageSize,
        );
    }

    /**
     * Per-row facts the list renders: why a row is tagged "Archived" (only rows that surfaced because a client or
     * project was named), and which buttons and fee cells the viewer gets.
     *
     * @param Task[] $tasks
     *
     * @return array<int, array{archived: list<string>, canEdit: bool, canDelete: bool, canSeeFee: bool}>
     */
    public function rowDetails(User $viewer, array $tasks): array
    {
        $reasonsByProject = [];
        $rows = [];
        foreach ($tasks as $task) {
            $project = $task->getProject();
            $archived = [];
            if ($project !== null) {
                $archived = $reasonsByProject[(int) $project->getId()] ??= $this->projects->archivedReasons($project);
            }

            $rows[(int) $task->getId()] = [
                'archived'  => $archived,
                'canEdit'   => $this->access->canUpdateTask($viewer, $task),
                'canDelete' => $this->access->canDeleteTask($viewer, $task),
                'canSeeFee' => $this->access->canAccessFee($viewer, $task),
            ];
        }

        return $rows;
    }
}
