<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Attachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Attachment>
 */
class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attachment::class);
    }

    /** @return Attachment[] newest first */
    public function findForOwner(string $type, int $id): array
    {
        return $this->findBy(['ownerType' => $type, 'ownerId' => $id], ['uploadedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, int> owner id => number of attachments
     */
    public function countByOwners(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('a')
            ->select('a.ownerId AS id, COUNT(a.id) AS n')
            ->andWhere('a.ownerType = :type')->setParameter('type', $type)
            ->andWhere('a.ownerId IN (:ids)')->setParameter('ids', $ids)
            ->groupBy('a.ownerId')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r) => ['id' => (int) $r['id'], 'n' => (int) $r['n']], $rows), 'n', 'id');
    }
}
