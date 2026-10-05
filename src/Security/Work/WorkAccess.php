<?php

declare(strict_types=1);

namespace App\Security\Work;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\Settings\TaskStatus;
use App\Entity\User;
use App\Enum\Role;
use App\Repository\ClientAdminRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStaffRepository;
use App\Repository\TaskManagerRepository;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who may see and change which client, project and task (ADR-070) — the one place these rules live. The voters,
 * the list scoping and the templates all ask here, so a button and its endpoint cannot disagree.
 *
 * Ported from work-platform's TaskAccessChecker / ClientVoter / ProjectVoter, with its four roles mapped onto
 * this app's single account type:
 *  - Admin and above (ROLE_ADMIN) take work-platform's Admin/Accountant place: every record, every fee.
 *  - Everyone else is decided by their relationship to the record — work-platform's Manager rules, where the
 *    relationship row itself now says "manager": a client_admin row (Client Manager), a project_staff row that is
 *    not "Contractor", or a task_manager row. A Contractor staff row, or only being the assignee, gives
 *    work-platform's Contractor rights: see your tasks and report on them.
 */
final class WorkAccess implements ResetInterface
{
    /** @var array<int, WorkRelations> */
    private array $relations = [];

    /** @var array<int, bool> */
    private array $admins = [];

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly ClientAdminRepository $clientAdmins,
        private readonly ProjectStaffRepository $projectStaff,
        private readonly TaskManagerRepository $taskManagers,
        private readonly ProjectRepository $projects,
    ) {
    }

    public function isAdmin(User $user): bool
    {
        return $this->admins[(int) $user->getId()] ??= in_array(
            Role::Admin->value,
            $this->roleHierarchy->getReachableRoleNames($user->getRoles()),
            true,
        );
    }

    // ── Clients ──────────────────────────────────────────────────────────────

    public function canCreateClient(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function canViewClient(User $user, Client $client): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $relations->managesClient($client->getId())
            || in_array($client->getId(), $relations->staffClientIds, true);
    }

    /** Edit fields and status. Choosing the Client Managers and removing the client stay with admins. */
    public function canEditClient(User $user, Client $client): bool
    {
        return $this->isAdmin($user) || $this->relationsOf($user)->managesClient($client->getId());
    }

    public function canAdministerClient(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /** @return int[]|null the clients this user may see; null = all */
    public function visibleClientIds(User $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }
        $relations = $this->relationsOf($user);

        return array_values(array_unique([...$relations->managedClientIds, ...$relations->staffClientIds]));
    }

    // ── Projects ─────────────────────────────────────────────────────────────

    /** With no client: whether this user may create a project for at least one client. */
    public function canCreateProject(User $user, ?Client $client = null): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $client === null ? $relations->managedClientIds !== [] : $relations->managesClient($client->getId());
    }

    public function canViewProject(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $relations->managesClient($project->getClient()?->getId()) || $relations->isStaff($project->getId());
    }

    /** Edit, archive, remove, and manage staff. */
    public function canEditProject(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $relations->managesClient($project->getClient()?->getId()) || $relations->isProjectManager($project->getId());
    }

    /** @return int[]|null the projects this user may see; null = all. Client Managers see their clients' projects. */
    public function visibleProjectIds(User $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }
        $relations = $this->relationsOf($user);

        return array_values(array_unique([
            ...$relations->staffProjectIds(),
            ...$this->projects->findIdsByClientIds($relations->managedClientIds),
        ]));
    }

    // ── Tasks ────────────────────────────────────────────────────────────────

    /**
     * Whether this user may file tasks at all: an admin, a Client Manager or managing staff files them directly; a
     * Contractor on some project may file one too, and it waits in the authorization queue (work-platform's rule
     * for a Contractor-created task).
     */
    public function canCreateTask(User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $relations->managedClientIds !== [] || $relations->staffProjectIds() !== [];
    }

    /** Whether a task may be filed under this project (null = no project) by this user — directly or into the queue. */
    public function canAddTaskTo(User $user, ?Project $project): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        if ($project === null) {
            return false;
        }
        $relations = $this->relationsOf($user);

        return $relations->managesClient($project->getClient()?->getId()) || $relations->isStaff($project->getId());
    }

    /**
     * Whether a task this user files under this project skips the authorization queue: yes for an admin, a
     * Client Manager or managing staff; no for a Contractor. A budget request always queues (decided by the caller).
     */
    public function filesDirectly(User $user, ?Project $project): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $project !== null
            && ($relations->managesClient($project->getClient()?->getId()) || $relations->isManagingStaff($project->getId()));
    }

    /** Approve or deny a queued task (work-platform's canReview): admin, its Project Manager, or its Client Manager. */
    public function canReviewTask(User $user, Task $task): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $relations->isProjectManager($task->getProject()?->getId())
            || $relations->managesClient($task->getProject()?->getClient()?->getId())
            || $relations->managesClient($task->getClientId());
    }

    /** Sees the queue / manager pages at all: someone who manages work. */
    public function managesAnyWork(User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $relations->managedClientIds !== [] || $relations->managingStaffProjectIds() !== [];
    }

    public function canViewTask(User $user, Task $task): bool
    {
        return $this->isAdmin($user)
            || $this->isAssignee($user, $task)
            || $task->getCreatedBy() === $user->getId()
            || $this->managesTask($user, $task);
    }

    /** work-platform's canUpdate(). */
    public function canUpdateTask(User $user, Task $task): bool
    {
        if ($this->isAdmin($user) || $this->managesTask($user, $task)) {
            return true;
        }

        // A task a user filed that is still waiting in the authorization queue stays theirs to correct.
        return $task->getCreatedBy() === $user->getId() && $task->getAuthorized() === Task::AUTHORIZED_YES;
    }

    /** The assignee reports progress through the status detail even without edit rights (work-platform parity). */
    public function canUpdateStatusDetail(User $user, Task $task): bool
    {
        return $this->canUpdateTask($user, $task) || $this->isAssignee($user, $task);
    }

    /**
     * work-platform's canManageTask(): the structural actions (delete). Never on a paid task — that is payment
     * history.
     */
    public function canDeleteTask(User $user, Task $task): bool
    {
        if ($task->getTaskStatusId() === TaskStatus::PAID_ID) {
            return false;
        }

        return $this->isAdmin($user)
            || $this->isAssignee($user, $task)
            || $task->getCreatedBy() === $user->getId()
            || $this->managesTask($user, $task);
    }

    /** work-platform's canAccessFee(): see the payout, time budget, billable date and currency. */
    public function canAccessFee(User $user, Task $task): bool
    {
        if ($this->isAdmin($user) || $this->isAssignee($user, $task)) {
            return true;
        }
        $relations = $this->relationsOf($user);

        return $relations->hasTaskManagerFeeAccess($task->getId())
            || $relations->hasStaffFeeAccess($task->getProject()?->getId());
    }

    /**
     * The fee gate when writing: no assignee leg, so an assignee cannot set their own payout. A null project
     * leaves only admins.
     */
    public function canSetFees(User $user, ?Project $project): bool
    {
        return $this->isAdmin($user) || $this->relationsOf($user)->hasStaffFeeAccess($project?->getId());
    }

    /** Whether canSetFees() could say yes for some project — decides if a create form renders the fee fields. */
    public function canSetFeesForAnyProject(User $user): bool
    {
        return $this->isAdmin($user) || $this->relationsOf($user)->hasAnyStaffFeeAccess();
    }

    /**
     * The task-list scope for TaskRepository: null = every task.
     *
     * @return array{userId: int, taskIds: int[], projectIds: int[], clientIds: int[]}|null
     */
    public function taskVisibility(User $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }
        $relations = $this->relationsOf($user);

        return [
            'userId'     => (int) $user->getId(),
            'taskIds'    => array_keys($relations->taskManagerFee),
            'projectIds' => $relations->managingStaffProjectIds(),
            'clientIds'  => $relations->managedClientIds,
        ];
    }

    public function reset(): void
    {
        $this->relations = [];
        $this->admins = [];
    }

    /**
     * Manager of this task through any relationship: reviewer, task manager, managing staff on its project, or
     * Client Manager of its project's client (or of the client a project-less task names directly).
     */
    private function managesTask(User $user, Task $task): bool
    {
        if ($task->getReviewerUserId() === $user->getId()) {
            return true;
        }
        $relations = $this->relationsOf($user);
        $projectId = $task->getProject()?->getId();

        return $relations->isTaskManager($task->getId())
            || $relations->isManagingStaff($projectId)
            || $relations->managesClient($task->getProject()?->getClient()?->getId())
            || $relations->managesClient($task->getClientId());
    }

    private function isAssignee(User $user, Task $task): bool
    {
        return $task->getAssignee() !== null && $task->getAssignee()->getId() === $user->getId();
    }

    private function relationsOf(User $user): WorkRelations
    {
        $userId = (int) $user->getId();
        if (isset($this->relations[$userId])) {
            return $this->relations[$userId];
        }

        $staffByProject = [];
        $staffClientIds = [];
        foreach ($this->projectStaff->findRowsForUser($userId) as $row) {
            $staffByProject[$row['project_id']] = ['permission' => $row['permission'], 'fee' => $row['fee']];
            $staffClientIds[] = $row['client_id'];
        }

        return $this->relations[$userId] = new WorkRelations(
            $userId,
            $this->clientAdmins->findClientIdsForUser($userId),
            $staffByProject,
            $this->taskManagers->findFeeAccessByTaskForUser($userId),
            array_values(array_unique($staffClientIds)),
        );
    }
}
