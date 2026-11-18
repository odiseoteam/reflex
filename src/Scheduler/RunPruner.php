<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\IncomingEvent;
use App\Entity\Run;
use App\Entity\RunStatus;
use App\Entity\Waitpoint;
use App\Entity\WaitpointKind;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes finished runs, with their steps and decisions, resolved absence waits, and events
 * nothing refers to any more.
 * Waiting runs are kept however old they are.
 */
final readonly class RunPruner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        #[Autowire('%env(int:REFLEX_RETENTION_DAYS)%')]
        private int $retentionDays,
    ) {
    }

    /**
     * @return int runs deleted
     */
    public function prune(): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d days', max(1, $this->retentionDays)));

        $deleted = $this->entityManager->createQueryBuilder()
            ->delete(Run::class, 'r')
            ->where('r.createdAt < :cutoff')
            ->andWhere('r.status IN (:finished)')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('finished', [RunStatus::Succeeded, RunStatus::Failed, RunStatus::Skipped])
            ->getQuery()
            ->execute();

        $this->entityManager->createQueryBuilder()
            ->delete(Waitpoint::class, 'w')
            ->where('w.kind = :absence')
            ->andWhere('w.resolvedAt < :cutoff')
            ->setParameter('absence', WaitpointKind::Absence)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $this->entityManager->createQueryBuilder()
            ->delete(IncomingEvent::class, 'e')
            ->where('e.receivedAt < :cutoff')
            ->andWhere(\sprintf('NOT EXISTS (SELECT 1 FROM %s r WHERE r.event = e)', Run::class))
            ->andWhere(\sprintf('NOT EXISTS (SELECT 1 FROM %s w WHERE w.event = e)', Waitpoint::class))
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
