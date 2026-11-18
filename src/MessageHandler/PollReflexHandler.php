<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Reflex;
use App\Message\PollReflex;
use App\Trigger\Poller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PollReflexHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Poller $poller,
    ) {
    }

    public function __invoke(PollReflex $message): void
    {
        $reflex = $this->entityManager->find(Reflex::class, $message->reflexId);
        if (null !== $reflex) {
            $this->poller->poll($reflex);
        }
    }
}
