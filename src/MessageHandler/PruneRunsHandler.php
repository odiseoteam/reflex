<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PruneRuns;
use App\Scheduler\RunPruner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PruneRunsHandler
{
    public function __construct(
        private RunPruner $pruner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PruneRuns $message): void
    {
        $this->logger->info('Pruned {count} old runs.', ['count' => $this->pruner->prune()]);
    }
}
