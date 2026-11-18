<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FireSchedules;
use App\Scheduler\ScheduledTrigger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FireSchedulesHandler
{
    public function __construct(
        private ScheduledTrigger $trigger,
    ) {
    }

    public function __invoke(FireSchedules $message): void
    {
        $this->trigger->fire();
    }
}
