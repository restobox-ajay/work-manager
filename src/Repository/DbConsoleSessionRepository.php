<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DbConsoleSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DbConsoleSession>
 */
class DbConsoleSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DbConsoleSession::class);
    }

    /**
     * Revoke every open console for one account (issue #48): on logout, "logout everywhere", a completed
     * password reset, deactivation and soft-delete — a console must not outlive its owner's access.
     */
    public function deleteAllByUserId(int $userId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /** Revoke every open console of every account — the "Disable now" kill-switch (issue #48). */
    public function deleteAll(): int
    {
        return (int) $this->createQueryBuilder('s')->delete()->getQuery()->execute();
    }

    /** Sweep lapsed rows. The gateway also does this opportunistically; this is the scheduled path. */
    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM db_console_session WHERE expires_at <= :now',
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** Dry-run mirror of {@see deleteExpired()} for app:prune. */
    public function countExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM db_console_session WHERE expires_at <= :now',
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
