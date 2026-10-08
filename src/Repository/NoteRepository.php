<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Note;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Notes (ADR-101). Lists are pinned first, then most recently changed.
 *
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    /** @return Note[] the notes on one client, project or task */
    public function findForSubject(Client|Project|Task $subject): array
    {
        $field = match (true) {
            $subject instanceof Client  => 'n.client',
            $subject instanceof Project => 'n.project',
            default                     => 'n.task',
        };

        return $this->ordered($this->createQueryBuilder('n')
            ->addSelect('a')
            ->join('n.author', 'a')
            ->andWhere("$field = :subject")
            ->setParameter('subject', $subject))
            ->getQuery()
            ->getResult();
    }

    /** @return Note[] the notes on a project's tasks (deleted tasks left out), with their task loaded */
    public function findOnTasksOf(Project $project): array
    {
        return $this->ordered($this->createQueryBuilder('n')
            ->addSelect('a', 't')
            ->join('n.author', 'a')
            ->join('n.task', 't')
            ->andWhere('t.project = :project AND t.isDeleted = 0')
            ->setParameter('project', $project))
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{term?: ?string, type?: ?string} $filters
     *
     * @return Note[]
     */
    public function search(User $viewer, bool $isAdmin, array $filters, int $page, int $pageSize): array
    {
        return $this->ordered($this->searchQuery($viewer, $isAdmin, $filters))
            ->addSelect('a', 'c', 'p', 't')
            ->leftJoin('n.client', 'c')
            ->leftJoin('n.project', 'p')
            ->leftJoin('n.task', 't')
            ->setFirstResult(max(0, $page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
    }

    /** @param array{term?: ?string, type?: ?string} $filters */
    public function countSearch(User $viewer, bool $isAdmin, array $filters): int
    {
        return (int) $this->searchQuery($viewer, $isAdmin, $filters)
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Independent notes are their author's alone. Notes on a record: an admin sees all of them; anyone else sees
     * the ones they wrote here, and the rest on the record's own page, which checks that they may view it.
     *
     * @param array{term?: ?string, type?: ?string} $filters
     */
    private function searchQuery(User $viewer, bool $isAdmin, array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('n')
            ->join('n.author', 'a')
            ->setParameter('viewer', $viewer);
        $isIndependent = 'n.client IS NULL AND n.project IS NULL AND n.task IS NULL';
        $qb->andWhere($isAdmin ? "n.author = :viewer OR NOT ($isIndependent)" : 'n.author = :viewer');

        $type = $filters['type'] ?? null;
        match ($type) {
            Note::TYPE_INDEPENDENT => $qb->andWhere($isIndependent),
            Note::TYPE_CLIENT      => $qb->andWhere('n.client IS NOT NULL'),
            Note::TYPE_PROJECT     => $qb->andWhere('n.project IS NOT NULL'),
            Note::TYPE_TASK        => $qb->andWhere('n.task IS NOT NULL'),
            default                => null,
        };

        $term = trim((string) ($filters['term'] ?? ''));
        if ($term !== '') {
            $qb->andWhere('n.title LIKE :term OR n.body LIKE :term')
                ->setParameter('term', '%'.addcslashes($term, '%_\\').'%');
        }

        return $qb;
    }

    private function ordered(QueryBuilder $qb): QueryBuilder
    {
        return $qb->orderBy('n.pinned', 'DESC')->addOrderBy('n.updatedAt', 'DESC')->addOrderBy('n.id', 'DESC');
    }
}
