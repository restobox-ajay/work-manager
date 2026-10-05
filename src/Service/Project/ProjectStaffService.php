<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\ProjectStaff;
use App\Entity\User;
use App\Repository\ClientAdminRepository;
use App\Repository\ProjectStaffRepository;
use App\Repository\UserRepository;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A project's staff (ADR-070), ported from work-platform's ProjectStaffService.
 *
 * Every Client Manager of the project's client is staff automatically ("Client Manager" rows, no fee access):
 * added when the project is created, when it moves client, and whenever its staff page is opened, so a manager
 * granted later still appears. Those rows are not edited or removed here — change the client's managers instead.
 * work-platform also auto-added every admin; admins already see every project here, so they are not added.
 *
 * Rows added by hand are "Project Manager" (manages the project and its tasks) or "Contractor" (works on it).
 */
final class ProjectStaffService
{
    /** The permissions a person can be given on the staff form. */
    public const ASSIGNABLE_PERMISSIONS = [
        ProjectStaff::PERMISSION_PROJECT_MANAGER,
        ProjectStaff::PERMISSION_CONTRACTOR,
    ];

    public function __construct(
        private readonly ProjectStaffRepository $projectStaff,
        private readonly ClientAdminRepository $clientAdmins,
        private readonly UserRepository $users,
        private readonly WorkAuditTrail $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return ProjectStaff[] */
    public function staffOf(Project $project): array
    {
        return $this->projectStaff->findForProject($project);
    }

    public function isAutomatic(ProjectStaff $staff): bool
    {
        return $staff->getPermission() === ProjectStaff::PERMISSION_CLIENT_MANAGER;
    }

    /** @return User[] active people not yet on the project */
    public function selectableUsers(Project $project): array
    {
        $onProject = [];
        foreach ($this->projectStaff->findForProject($project) as $staff) {
            $onProject[(int) $staff->getUser()->getId()] = true;
        }

        return array_values(array_filter(
            $this->users->findActiveOrderedByName(),
            static fn (User $user) => !isset($onProject[(int) $user->getId()]),
        ));
    }

    public function ensureAutomaticStaff(Project $project, User $actor): void
    {
        $client = $project->getClient();
        if ($client === null) {
            return;
        }

        $existing = [];
        foreach ($this->projectStaff->findForProject($project) as $staff) {
            $existing[(int) $staff->getUser()->getId()] = true;
        }

        $added = false;
        foreach ($this->clientAdmins->findManagers($client) as $manager) {
            if (isset($existing[(int) $manager->getId()])) {
                continue;
            }
            $this->em->persist($this->newRow($project, $manager, ProjectStaff::PERMISSION_CLIENT_MANAGER, false, $actor));
            $added = true;
        }

        if ($added) {
            $this->em->flush();
        }
    }

    /** @return ?string why the person could not be added, or null when they were */
    public function add(Project $project, ?User $user, string $permission, bool $canAccessTaskFee, User $actor): ?string
    {
        if ($user === null || !$user->isActive()) {
            return 'Choose a person to add.';
        }
        if (!in_array($permission, self::ASSIGNABLE_PERMISSIONS, true)) {
            return 'Choose Project Manager or Contractor.';
        }
        if ($this->projectStaff->findOneForProjectAndUser($project, $user) !== null) {
            return sprintf('%s is already on this project.', $user->getName());
        }

        $this->em->persist($this->newRow($project, $user, $permission, $canAccessTaskFee, $actor));
        $this->em->flush();

        $this->audit->record($actor, 'project.staff_add', sprintf('#%d %s: %s as %s', (int) $project->getId(), $project->getName(), $user->getEmail(), $permission));

        return null;
    }

    /** @return ?string why the row could not be changed, or null when it was */
    public function update(ProjectStaff $staff, string $permission, bool $canAccessTaskFee, User $actor): ?string
    {
        if ($this->isAutomatic($staff)) {
            return 'Client Managers are added automatically; change the client\'s managers instead.';
        }
        if (!in_array($permission, self::ASSIGNABLE_PERMISSIONS, true)) {
            return 'Choose Project Manager or Contractor.';
        }

        $staff->setPermission($permission)
            ->setCanAccessTaskFee($canAccessTaskFee ? 1 : 0)
            ->setUpdatedAt(time());
        $this->em->flush();

        $this->audit->record($actor, 'project.staff_update', $this->describe($staff));

        return null;
    }

    /** @return ?string why the row could not be removed, or null when it was */
    public function remove(ProjectStaff $staff, User $actor): ?string
    {
        if ($this->isAutomatic($staff)) {
            return 'Client Managers are added automatically; change the client\'s managers instead.';
        }

        $description = $this->describe($staff);
        $this->em->remove($staff);
        $this->em->flush();

        $this->audit->record($actor, 'project.staff_remove', $description);

        return null;
    }

    private function newRow(Project $project, User $user, string $permission, bool $canAccessTaskFee, User $actor): ProjectStaff
    {
        return (new ProjectStaff())
            ->setProject($project)
            ->setUser($user)
            ->setPermission($permission)
            ->setCanAccessTaskFee($canAccessTaskFee ? 1 : 0)
            ->setCreatedBy($actor->getId())
            ->setCreatedAt(time())
            ->setUpdatedAt(time());
    }

    private function describe(ProjectStaff $staff): string
    {
        return sprintf(
            '#%d %s: %s as %s, fee access %s',
            (int) $staff->getProject()->getId(),
            $staff->getProject()->getName(),
            $staff->getUser()->getEmail(),
            (string) $staff->getPermission(),
            $staff->getCanAccessTaskFee() === 1 ? 'yes' : 'no',
        );
    }
}
