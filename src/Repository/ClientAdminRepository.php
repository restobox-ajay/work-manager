<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\ClientAdmin;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * `client_admin`: which users are a client's "Client Managers".
 *
 * @extends ServiceEntityRepository<ClientAdmin>
 */
class ClientAdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientAdmin::class);
    }

    /** @return User[] */
    public function findManagers(Client $client): array
    {
        return array_map(
            static fn (ClientAdmin $row) => $row->getUser(),
            $this->createQueryBuilder('ca')
                ->addSelect('u')
                ->join('ca.user', 'u')
                ->andWhere('ca.client = :client')
                ->setParameter('client', $client)
                ->orderBy('u.name', 'ASC')
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * Managers of many clients at once, for a list page (one query, not one per row).
     *
     * @param int[] $clientIds
     *
     * @return array<int, User[]> client id => managers
     */
    public function findManagersByClientIds(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('ca')
            ->addSelect('u', 'c')
            ->join('ca.user', 'u')
            ->join('ca.client', 'c')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', $clientIds)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        $byClient = [];
        foreach ($rows as $row) {
            $byClient[(int) $row->getClient()->getId()][] = $row->getUser();
        }

        return $byClient;
    }

    /** @return int[] ids of the clients this user manages */
    public function findClientIdsForUser(int $userId): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT client_id FROM client_admin WHERE user_id = ?',
            [$userId],
        ));
    }
}
