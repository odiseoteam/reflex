<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\Reflex;
use App\Entity\RunStatus;
use App\Repository\DecisionRepository;
use App\Repository\ReflexRepository;
use App\Repository\RunRepository;
use Psr\Clock\ClockInterface;

/**
 * What the home shows: today's numbers.
 */
final readonly class Dashboard
{
    public function __construct(
        private RunRepository $runs,
        private DecisionRepository $decisions,
        private ReflexRepository $reflexes,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<Reflex> $reflexes
     */
    public function stats(array $reflexes): Stats
    {
        $today = $this->today();
        $byStatus = $this->runs->countByStatusSince($today);
        $budgets = array_filter(array_map(
            static fn (Reflex $reflex): ?int => $reflex->isEnabled() ? $reflex->getDailyTokenBudget() : null,
            $reflexes,
        ), static fn (?int $budget): bool => null !== $budget);

        return new Stats(
            $this->decisions->countPending(),
            array_sum($byStatus),
            ($byStatus[RunStatus::Succeeded->value] ?? 0) + ($byStatus[RunStatus::Failed->value] ?? 0) + ($byStatus[RunStatus::Skipped->value] ?? 0),
            $byStatus[RunStatus::Waiting->value] ?? 0,
            $this->runs->countToolCallsSince($today),
            $this->runs->tokensUsedSince($today),
            [] === $budgets ? null : array_sum($budgets),
        );
    }

    /**
     * @return list<Reflex>
     */
    public function reflexes(): array
    {
        return $this->reflexes->findAllOrdered();
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
    }
}
