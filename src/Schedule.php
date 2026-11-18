<?php

declare(strict_types=1);

namespace App;

use App\Message\FireSchedules;
use App\Message\PruneRuns;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * A tick each minute fires the reflexes whose cron is due, read from the database so
 * edits apply without restarting workers. Old runs are pruned nightly. The lock keeps
 * several workers from each ticking.
 */
#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->lock($this->lockFactory->createLock('reflex-schedule'))
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('1 minute', new FireSchedules()))
            ->add(RecurringMessage::cron('17 3 * * *', new PruneRuns()));
    }
}
