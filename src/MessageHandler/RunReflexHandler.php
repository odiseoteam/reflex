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

        // A redelivered message must not run a reflex twice.
        if (null === $run || RunStatus::Queued !== $run->getStatus()) {
            return;
        }

        $this->runner->run($run);
    }
}
