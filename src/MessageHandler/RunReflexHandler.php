<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Engine\ReflexRunner;
use App\Entity\Run;
use App\Entity\RunStatus;
use App\Message\RunReflex;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunReflexHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReflexRunner $runner,
    ) {
    }

    public function __invoke(RunReflex $message): void
    {
        $run = $this->entityManager->find(Run::class, $message->runId);

        // Finished and waiting runs are left alone; a running one resumes after a worker crash.
        if (null === $run || !\in_array($run->getStatus(), [RunStatus::Queued, RunStatus::Running], true)) {
            return;
        }

        $this->runner->run($run);
    }
}
