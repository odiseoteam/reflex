<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reflex;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reflex>
 */
class ReflexRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reflex::class);
    }

    /**
     * @return list<Reflex>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * Enabled reflexes listening to this event.
     *
     * @return list<Reflex>
     */
    public function findTriggeredBy(string $source, string $type): array
    {
        /** @var list<Reflex> $reflexes */
        $reflexes = $this->createQueryBuilder('r')
            ->where('r.enabled = true')
            ->andWhere('r.triggerSource = :source')
            ->andWhere('r.triggerEvent IN (:events)')
            ->setParameter('source', $source)
            ->setParameter('events', [$type, Reflex::ANY_EVENT])
            ->getQuery()
            ->getResult();

        return $reflexes;
    }

    /**
     * @return list<Reflex>
     */
    public function findScheduled(): array
    {
        /** @var list<Reflex> $reflexes */
        $reflexes = $this->createQueryBuilder('r')
            ->where('r.enabled = true')
            ->andWhere('r.triggerSource = :source')
            ->andWhere('r.cron IS NOT NULL')
            ->setParameter('source', Reflex::SCHEDULE_SOURCE)
            ->getQuery()
            ->getResult();

        return $reflexes;
    }

    /**
     * @return list<Reflex>
     */
    public function findEnabledBySource(string $source): array
    {
        /** @var list<Reflex> $reflexes */
        $reflexes = $this->findBy(['enabled' => true, 'triggerSource' => $source]);

        return $reflexes;
    }

    /**
     * Marks a schedule slot as fired, if nobody did yet. A single conditional update, so
     * concurrent workers can't both claim it.
     */
    public function claimSlot(Reflex $reflex, \DateTimeImmutable $slot): bool
    {
        $claimed = 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE reflex SET last_fired_at = :slot WHERE id = :id AND (last_fired_at IS NULL OR last_fired_at < :slot)',
            ['slot' => $slot->format('Y-m-d H:i:s'), 'id' => $reflex->getId()->toRfc4122()],
        );
        $this->getEntityManager()->refresh($reflex);

        return $claimed;
    }

    /**
     * Marks a poll as started, if its interval passed and nobody did yet.
     */
    public function claimPoll(Reflex $reflex, \DateTimeImmutable $now, int $intervalMinutes): bool
    {
        $claimed = 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE reflex SET last_polled_at = :now WHERE id = :id AND (last_polled_at IS NULL OR last_polled_at <= :since)',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'since' => $now->modify(\sprintf('-%d minutes', $intervalMinutes))->format('Y-m-d H:i:s'),
                'id' => $reflex->getId()->toRfc4122(),
            ],
        );
        $this->getEntityManager()->refresh($reflex);

        return $claimed;
    }
}
