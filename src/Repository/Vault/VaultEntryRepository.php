<?php

declare(strict_types=1);

namespace App\Repository\Vault;

use App\Entity\User;
use App\Entity\Vault\VaultEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VaultEntry>
 */
class VaultEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VaultEntry::class);
    }

    /** @return VaultEntry[] */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'ASC']);
    }

    /** The entry only when $user owns it: another admin's entry id is treated as not found. */
    public function findOwned(int $id, User $user): ?VaultEntry
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function deleteForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('e')->delete()->where('e.user = :user')->setParameter('user', $user)->getQuery()->execute();
    }
}
