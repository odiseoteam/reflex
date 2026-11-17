<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Engine\DecisionDesk;
use App\Entity\Decision;
use App\Message\ExecuteDecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExecuteDecisionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DecisionDesk $desk,
    ) {
    }

    public function __invoke(ExecuteDecision $message): void
    {
        $decision = $this->entityManager->find(Decision::class, $message->decisionId);
        if (null !== $decision) {
            $this->desk->execute($decision);
        }
    }
}
