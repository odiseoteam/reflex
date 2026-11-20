<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Run;
use App\Entity\RunStatus;
use App\Entity\RunStep;
use App\Entity\RunStepKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Run>
 */
class RunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Run::class);
    }

    /**
     * Tokens the reflex spent since midnight UTC.
     */
    public function tokensUsedToday(\App\Entity\Reflex $reflex): int
    {
        $total = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.tokensUsed), 0)')
            ->where('r.reflex = :reflex')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('reflex', $reflex)
            ->setParameter('since', new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * Runs created since $since, counted by status value.
     *
     * @return array<string, int>
     */
    public function countByStatusSince(\DateTimeImmutable $since): array
    {
        /** @var list<array{status: RunStatus, runs: int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status, COUNT(r.id) AS runs')
            ->where('r.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('r.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['runs'];
        }

        return $counts;
    }

    /**
     * Tool calls that ran on their own, without a person, since $since.
     */
    public function countToolCallsSince(\DateTimeImmutable $since): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(RunStep::class, 's')
            ->where('s.kind = :kind')
            ->andWhere('s.createdAt >= :since')
            ->setParameter('kind', RunStepKind::ToolCall)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function tokensUsedSince(\DateTimeImmutable $since): int
    {
        $total = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.tokensUsed), 0)')
            ->where('r.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * When each reflex last ran, by reflex id.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function lastRunAtByReflex(): array
    {
        /** @var list<array{reflex: string, lastRun: string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.reflex) AS reflex, MAX(r.createdAt) AS lastRun')
            ->groupBy('r.reflex')
            ->getQuery()
            ->getArrayResult();

        $lastRuns = [];
        foreach ($rows as $row) {
            $lastRuns[(string) $row['reflex']] = new \DateTimeImmutable($row['lastRun']);
        }

        return $lastRuns;
    }

    /**
     * Latest runs, optionally of one status or reflex.
     *
     * @return list<Run>
     */
    public function findFiltered(?RunStatus $status, ?\App\Entity\Reflex $reflex, int $limit = 100): array
    {
        return $this->findBy(array_filter(['status' => $status, 'reflex' => $reflex], static fn (mixed $value): bool => null !== $value), ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Steps of each run by kind, keyed by run id.
     *
     * @param list<Run> $runs
     *
     * @return array<string, array<string, int>>
     */
    public function countStepsByKind(array $runs): array
    {
        if ([] === $runs) {
            return [];
        }

        /** @var list<array{run: string, kind: RunStepKind, steps: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(s.run) AS run, s.kind AS kind, COUNT(s.id) AS steps')
            ->from(RunStep::class, 's')
            ->where('s.run IN (:runs)')
            ->setParameter('runs', $runs)
            ->groupBy('s.run, s.kind')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['run']][$row['kind']->value] = (int) $row['steps'];
        }

        return $counts;
    }

    /**
     * @return list<Run>
     */
    public function findLatest(?\App\Entity\Reflex $reflex = null, int $limit = 50): array
    {
        return $this->findBy(null === $reflex ? [] : ['reflex' => $reflex], ['createdAt' => 'DESC'], $limit);
    }
}
