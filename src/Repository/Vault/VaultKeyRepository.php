<?php

declare(strict_types=1);

namespace App\Repository\Vault;

use App\Entity\User;
use App\Entity\Vault\VaultKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VaultKey>
 */
class VaultKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VaultKey::class);
    }

    public function findForUser(User $user): ?VaultKey
    {
        return $this->findOneBy(['user' => $user]);
    }
}
