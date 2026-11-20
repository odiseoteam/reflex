<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * The home's numbers for the current UTC day.
 */
final readonly class Stats
{
    public function __construct(
        public int $pendingDecisions,
        public int $runs,
        public int $finishedRuns,
        public int $waitingRuns,
        public int $toolCalls,
        public int $tokens,
        /** Sum of the daily budgets of enabled reflexes; null when none has one. */
        public ?int $tokenBudget,
    ) {
    }

    public function budgetPercent(): ?int
    {
        return null === $this->tokenBudget || 0 === $this->tokenBudget
            ? null
            : min(100, (int) round($this->tokens * 100 / $this->tokenBudget));
    }
}
