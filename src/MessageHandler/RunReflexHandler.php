<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Engine\ReflexRunner;
use App\Entity\Run;
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

        // A finished run is never repeated; a running one resumes after a worker crash.
        if (null === $run || $run->getStatus()->isFinished()) {
            return;
        }

        $this->runner->run($run);
    }
}
