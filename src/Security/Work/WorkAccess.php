<?php

declare(strict_types=1);

namespace App\Security\Work;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\Settings\TaskStatus;
use App\Entity\User;
use App\Enum\Role;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who may see and change which client, project and task (ADR-070) — the one place these rules live. The voters,
 * the list scoping and the templates all ask here, so a button and its endpoint cannot disagree.
 *
 * Since ADR-124 (owner request) there are no Client Manager, project staff or Task Manager rows any more, so two
 * kinds of user remain:
 *  - Admin and above (ROLE_ADMIN): every client, project, task and fee.
 *  - Everyone else: only tasks they are the assignee, creator or reviewer of — see them, report on them, correct a
 *    Pending task they filed. No clients or projects of their own.
 */
final class WorkAccess implements ResetInterface
{
    /** @var array<int, bool> */
    private array $admins = [];

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
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
        return $this->isAdmin($user);
    }

    public function canEditClient(User $user, Client $client): bool
    {
        return $this->isAdmin($user);
    }

    public function canAdministerClient(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /** @return int[]|null the clients this user may see; null = all */
    public function visibleClientIds(User $user): ?array
    {
        return $this->isAdmin($user) ? null : [];
    }

    // ── Projects ─────────────────────────────────────────────────────────────

    public function canCreateProject(User $user, ?Client $client = null): bool
    {
        return $this->isAdmin($user);
    }

    public function canViewProject(User $user, Project $project): bool
    {
        return $this->isAdmin($user);
    }

    /** Edit, archive, remove. */
    public function canEditProject(User $user, Project $project): bool
    {
        return $this->isAdmin($user);
    }

    /** @return int[]|null the projects this user may see; null = all */
    public function visibleProjectIds(User $user): ?array
    {
        return $this->isAdmin($user) ? null : [];
    }

    // ── Tasks ────────────────────────────────────────────────────────────────

    /** Filing tasks is for admins (every task is regular work as soon as it is filed, ADR-084). */
    public function canCreateTask(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /** Whether a task may be filed under this project (null = no project) by this user. */
    public function canAddTaskTo(User $user, ?Project $project): bool
    {
        return $this->isAdmin($user);
    }

    /** Someone who manages work: the manager dashboard, priority pages. */
    public function managesAnyWork(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function canViewTask(User $user, Task $task): bool
    {
        return $this->isAdmin($user)
            || $this->isAssignee($user, $task)
            || $task->getCreatedBy() === $user->getId()
            || $this->isReviewer($user, $task);
    }

    /** work-platform's canUpdate(). */
    public function canUpdateTask(User $user, Task $task): bool
    {
        if ($this->isAdmin($user) || $this->isReviewer($user, $task)) {
            return true;
        }

        // A task a user filed stays theirs to correct while it is still Pending (no queue holds it any more, ADR-084).
        return $task->getCreatedBy() === $user->getId() && $task->getTaskStatusId() === TaskStatus::PENDING_ID;
    }

    /** The assignee reports progress through the status detail even without edit rights (work-platform parity). */
    public function canUpdateStatusDetail(User $user, Task $task): bool
    {
        return $this->canUpdateTask($user, $task) || $this->isAssignee($user, $task);
    }

    /** The structural action (delete). Never on a paid task — that is payment history. */
    public function canDeleteTask(User $user, Task $task): bool
    {
        if ($task->getTaskStatusId() === TaskStatus::PAID_ID) {
            return false;
        }

        return $this->isAdmin($user)
            || $this->isAssignee($user, $task)
            || $task->getCreatedBy() === $user->getId()
            || $this->isReviewer($user, $task);
    }

    /** See the payout and currency: admins, and the assignee for their own task. */
    public function canAccessFee(User $user, Task $task): bool
    {
        return $this->isAdmin($user) || $this->isAssignee($user, $task);
    }

    /** The fee gate when writing: no assignee leg, so an assignee cannot set their own payout. */
    public function canSetFees(User $user, ?Project $project): bool
    {
        return $this->isAdmin($user);
    }

    /** Whether canSetFees() could say yes for some project — decides if a create form renders the fee fields. */
    public function canSetFeesForAnyProject(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * The task-list scope for TaskRepository: null = every task; otherwise the user's own tasks only.
     *
     * @return array{userId: int, taskIds: int[], projectIds: int[], clientIds: int[]}|null
     */
    public function taskVisibility(User $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        return ['userId' => (int) $user->getId(), 'taskIds' => [], 'projectIds' => [], 'clientIds' => []];
    }

    public function reset(): void
    {
        $this->admins = [];
    }

    private function isReviewer(User $user, Task $task): bool
    {
        return $task->getReviewerUserId() !== null && $task->getReviewerUserId() === $user->getId();
    }

    private function isAssignee(User $user, Task $task): bool
    {
        return $task->getAssignee() !== null && $task->getAssignee()->getId() === $user->getId();
    }
}
