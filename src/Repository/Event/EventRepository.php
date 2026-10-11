<?php

declare(strict_types=1);

namespace App\Repository\Event;

use App\Entity\Event\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /** @return Event[] newest date first */
    public function findFiltered(?int $categoryId, ?int $year, ?string $term): array
    {
        $qb = $this->createQueryBuilder('e')->addSelect('c')->leftJoin('e.category', 'c')
            ->orderBy('e.eventDate', 'DESC')->addOrderBy('e.id', 'DESC');
        if ($categoryId !== null) {
            $qb->andWhere('c.id = :cat')->setParameter('cat', $categoryId);
        }
        if ($year !== null) {
            $qb->andWhere('e.eventDate BETWEEN :from AND :to')
                ->setParameter('from', new \DateTimeImmutable(sprintf('%04d-01-01', $year)))
                ->setParameter('to', new \DateTimeImmutable(sprintf('%04d-12-31', $year)));
        }
        if ($term !== null && $term !== '') {
            $qb->andWhere('e.title LIKE :t OR e.people LIKE :t OR e.place LIKE :t')->setParameter('t', '%'.addcslashes($term, '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Candidates for the upcoming list: every yearly event, plus one-off events that touch [from, to].
     *
     * @return Event[]
     */
    public function findUpcomingCandidates(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('e')->addSelect('c')->leftJoin('e.category', 'c')
            ->andWhere('e.repeatsYearly = true OR (e.eventDate <= :to AND COALESCE(e.endDate, e.eventDate) >= :from)')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getResult();
    }

    /** @return Event[] the ones that ask for a reminder email */
    public function findWithReminders(): array
    {
        return $this->createQueryBuilder('e')->andWhere('e.remindDaysBefore IS NOT NULL')->getQuery()->getResult();
    }

    /** @return list<int> the years that have events, newest first */
    public function years(): array
    {
        $dates = $this->createQueryBuilder('e')->select('e.eventDate')->getQuery()->getSingleColumnResult();
        $years = array_unique(array_map(static fn ($d) => (int) ($d instanceof \DateTimeInterface ? $d->format('Y') : substr((string) $d, 0, 4)), $dates));
        rsort($years);

        return array_values($years);
    }
}
