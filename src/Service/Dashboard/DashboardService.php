<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\Client;
use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Security\Work\WorkAccess;

/**
 * The dashboard (ADR-070), ported from work-platform's DashboardController/DashboardService.
 *
 * Two views, as there: people who manage work (admins, ADR-124) get the manager view —
 * pick a client and project in the side list, filter by status, see the newest tasks. Everyone else gets the
 * contractor view — their own to-do, what is waiting for review, and what is approved but not yet paid.
 * A manager can switch to the contractor view for their own tasks (?view=mine), as work-platform's Contractor View.
 */
final class DashboardService
{
    public const WIDGET_LIMIT = 10;
    public const MANAGER_TASK_LIMIT = 15;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly WorkAccess $access,
    ) {
    }

    /** The "All" pill's taskStatusId: every status rather than the default one. */
    public const ALL_STATUSES = 'all';

    /**
     * The manager view's filters (ADR-102): opening the dashboard shows Pending tasks; ?taskStatusId=all shows every
     * status. Pending is found by name, because its id differs between databases (work-platform data has it as 2).
     *
     * @param array<string, mixed> $query
     * @param array<int, string>   $statuses id => name
     *
     * @return array<string, mixed> the query with taskStatusId settled
     */
    public function managerQuery(array $query, array $statuses): array
    {
        if (!array_key_exists('taskStatusId', $query)) {
            $pending = array_search('pending', array_map(static fn (string $name) => strtolower(trim($name)), $statuses), true);
            $query['taskStatusId'] = $pending !== false ? $pending : TaskStatus::PENDING_ID;
        } elseif ($query['taskStatusId'] === self::ALL_STATUSES) {
            unset($query['taskStatusId']);
        }

        return $query;
    }

    public function showsManagerView(User $viewer, bool $mineRequested): bool
    {
        return !$mineRequested && $this->access->managesAnyWork($viewer);
    }

    /**
     * The contractor view's three widgets. work-platform ordered the to-do by each person's own priority list
     * (not ported yet); until it is, soonest due first.
     *
     * @return array{pending: Task[], review: Task[], approved: Task[]}
     */
    public function myWork(User $viewer): array
    {
        $userId = (int) $viewer->getId();

        return [
            'pending'  => $this->tasks->findAssignedInStatus($userId, TaskStatus::PENDING_ID, self::WIDGET_LIMIT, false, 'due'),
            'review'   => $this->tasks->findAssignedInStatus($userId, TaskStatus::REVIEWING_INTERNAL_ID, self::WIDGET_LIMIT, false, 'due'),
            // work-platform showed 11 here; archived work included (money owed — see findAssignedInStatus()).
            'approved' => $this->tasks->findAssignedInStatus($userId, TaskStatus::APPROVED_ID, self::WIDGET_LIMIT + 1, true, 'newest'),
        ];
    }

    /**
     * The side list of the manager view: the clients this viewer may see.
     *
     * @param Client[] $clients
     *
     * @return Client[]
     */
    public function liveClients(array $clients): array
    {
        return array_values(array_filter($clients, static fn (Client $client) => $client->isActive()));
    }
}
