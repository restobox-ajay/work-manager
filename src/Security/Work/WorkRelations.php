<?php

declare(strict_types=1);

namespace App\Security\Work;

use App\Entity\ProjectStaff;

/**
 * One user's relationships to clients, projects and tasks — everything the per-record rules in WorkAccess ask —
 * loaded once per request (three small queries) so a page checking fifty tasks runs no query per task.
 */
final class WorkRelations
{
    /**
     * @param int[]                                                $managedClientIds client_admin rows
     * @param array<int, array{permission: ?string, fee: bool}>    $staffByProject   project_staff rows, by project id
     * @param array<int, bool>                                     $taskManagerFee   task_manager rows: task id => fee access
     * @param int[]                                                $staffClientIds   clients of the projects in $staffByProject
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $managedClientIds,
        public readonly array $staffByProject,
        public readonly array $taskManagerFee,
        public readonly array $staffClientIds,
    ) {
    }

    public function managesClient(?int $clientId): bool
    {
        return $clientId !== null && in_array($clientId, $this->managedClientIds, true);
    }

    public function isStaff(?int $projectId): bool
    {
        return $projectId !== null && isset($this->staffByProject[$projectId]);
    }

    public function staffPermission(?int $projectId): ?string
    {
        return $projectId !== null ? ($this->staffByProject[$projectId]['permission'] ?? null) : null;
    }

    public function isProjectManager(?int $projectId): bool
    {
        return $this->staffPermission($projectId) === ProjectStaff::PERMISSION_PROJECT_MANAGER;
    }

    /**
     * Staff who manage rather than only do the work. work-platform granted task editing to any staff row of a
     * Manager-role user; here the row's own permission says the same thing (ADR-070).
     */
    public function isManagingStaff(?int $projectId): bool
    {
        return $this->isStaff($projectId) && $this->staffPermission($projectId) !== ProjectStaff::PERMISSION_CONTRACTOR;
    }

    public function hasStaffFeeAccess(?int $projectId): bool
    {
        return $projectId !== null && ($this->staffByProject[$projectId]['fee'] ?? false);
    }

    public function isTaskManager(?int $taskId): bool
    {
        return $taskId !== null && isset($this->taskManagerFee[$taskId]);
    }

    public function hasTaskManagerFeeAccess(?int $taskId): bool
    {
        return $taskId !== null && ($this->taskManagerFee[$taskId] ?? false);
    }

    public function hasAnyStaffFeeAccess(): bool
    {
        return array_filter($this->staffByProject, static fn (array $staff) => $staff['fee']) !== [];
    }

    /** @return int[] projects where this user is staff that manages (see isManagingStaff()) */
    public function managingStaffProjectIds(): array
    {
        return array_keys(array_filter(
            $this->staffByProject,
            static fn (array $staff) => $staff['permission'] !== ProjectStaff::PERMISSION_CONTRACTOR,
        ));
    }

    /** @return int[] */
    public function staffProjectIds(): array
    {
        return array_keys($this->staffByProject);
    }
}
