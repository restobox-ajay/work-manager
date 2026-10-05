<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectStaff;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * `project_staff`: who works on a project, and as what (Project Manager / Client Manager / Contractor).
 *
 * @extends ServiceEntityRepository<ProjectStaff>
 */
class ProjectStaffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectStaff::class);
    }

    /** @return ProjectStaff[] */
    public function findForProject(Project $project): array
    {
        return $this->createQueryBuilder('ps')
            ->addSelect('u')
            ->join('ps.user', 'u')
            ->andWhere('ps.project = :project')
            ->setParameter('project', $project)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForProjectAndUser(Project $project, User $user): ?ProjectStaff
    {
        return $this->findOneBy(['project' => $project, 'user' => $user]);
    }

    /**
     * One user's staff rows as plain data (with each project's client), for the per-request access map.
     *
     * @return list<array{project_id: int, client_id: int, permission: ?string, fee: bool}>
     */
    public function findRowsForUser(int $userId): array
    {
        return array_map(
            static fn (array $row) => [
                'project_id' => (int) $row['project_id'],
                'client_id'  => (int) $row['client_id'],
                'permission' => $row['permission'],
                'fee'        => (int) $row['can_access_task_fee'] === 1,
            ],
            $this->getEntityManager()->getConnection()->fetchAllAssociative(
                'SELECT ps.project_id, p.client_id, ps.permission, COALESCE(ps.can_access_task_fee, 0) AS can_access_task_fee
                   FROM project_staff ps JOIN project p ON p.id = ps.project_id
                  WHERE ps.user_id = ?',
                [$userId],
            ),
        );
    }

    /**
     * The contractors on many projects at once (project list page), in one query.
     *
     * @param int[] $projectIds
     *
     * @return array<int, User[]> project id => contractors on it
     */
    public function findContractorsByProjectIds(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('ps')
            ->addSelect('u', 'p')
            ->join('ps.user', 'u')
            ->join('ps.project', 'p')
            ->andWhere('p.id IN (:ids)')
            ->andWhere('ps.permission = :contractor')
            ->setParameter('ids', $projectIds)
            ->setParameter('contractor', ProjectStaff::PERMISSION_CONTRACTOR)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        $byProject = [];
        foreach ($rows as $row) {
            $byProject[(int) $row->getProject()->getId()][] = $row->getUser();
        }

        return $byProject;
    }
}
