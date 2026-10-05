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
 * Two views, as there: people who manage work (admins, Client Managers, Project Managers) get the manager view —
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
