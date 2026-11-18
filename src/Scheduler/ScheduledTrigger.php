<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Engine\EventIntake;
use App\Entity\Reflex;
use App\Repository\ReflexRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Queues a run for every scheduled reflex whose slot is due. A slot becomes an event
 * keyed by reflex and time, so two ticks for the same slot queue one run.
 */
final readonly class ScheduledTrigger
{
    public function __construct(
        private ReflexRepository $reflexes,
        private EventIntake $intake,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int runs queued
     */
    public function fire(): int
    {
        $now = $this->clock->now();
        $queued = 0;
        foreach ($this->reflexes->findScheduled() as $reflex) {
            $slot = $reflex->claimDueSlot($now);
            if (null === $slot) {
                continue;
            }

            $this->entityManager->flush();
            $run = $this->intake->receiveFor($reflex, Reflex::SCHEDULE_SOURCE, $reflex->getId()->toRfc4122().'@'.$slot->format(\DATE_ATOM), 'tick', [
                'scheduledFor' => $slot->format(\DATE_ATOM),
                'cron' => $reflex->getCron(),
            ]);
            $queued += null === $run ? 0 : 1;
        }

        return $queued;
    }
}
