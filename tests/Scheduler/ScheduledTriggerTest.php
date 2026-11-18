<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Engine\EventIntake;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\RunStatus;
use App\Repository\ReflexRepository;
use App\Scheduler\RunPruner;
use App\Scheduler\ScheduledTrigger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class ScheduledTriggerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testFiresEachDueSlotOnceAndCollapsesMissedOnes(): void
    {
        $reflex = new Reflex('Weekly digest', Reflex::SCHEDULE_SOURCE, Reflex::ANY_EVENT, 'Summarise the week.');
        $reflex->setCron('0 9 * * 1');
        $this->entityManager->persist($reflex);
        $this->entityManager->flush();

        $clock = new MockClock(new \DateTimeImmutable('next monday 08:59', new \DateTimeZone('UTC')));
        $trigger = $this->trigger($clock);
        self::assertSame(0, $trigger->fire());

        $clock->modify('+1 minute');
        self::assertSame(1, $trigger->fire());
        self::assertSame(0, $trigger->fire(), 'A second tick in the same slot queues nothing.');

        // Three weeks offline: only the latest slot runs.
        $clock->modify('+21 days +3 hours');
        self::assertSame(1, $trigger->fire());

        $runs = $this->entityManager->getRepository(Run::class)->findBy(['reflex' => $reflex]);
        self::assertCount(2, $runs);
        $event = $runs[0]->getEvent();
        self::assertNotNull($event);
        self::assertSame('schedule', $event->getSource());
        self::assertSame('tick', $event->getType());
    }

    public function testSkipsDisabledReflexes(): void
    {
        $reflex = new Reflex('Off', Reflex::SCHEDULE_SOURCE, Reflex::ANY_EVENT, 'Nothing.');
        $reflex->setCron('* * * * *');
        $reflex->update('Off', Reflex::SCHEDULE_SOURCE, Reflex::ANY_EVENT, 'Nothing.', Reflex::DEFAULT_MODEL, false, []);
        $this->entityManager->persist($reflex);
        $this->entityManager->flush();

        self::assertSame(0, $this->trigger(new MockClock('+5 minutes'))->fire());
    }

    public function testPrunesOnlyFinishedRunsPastRetention(): void
    {
        $reflex = new Reflex('Triage', 'generic', '*', 'Close duplicates.');
        $old = new IncomingEvent('generic', 'old', 'x', []);
        $finished = new Run($reflex, $old);
        $finished->start();
        $finished->succeed('Done.');
        $waiting = new Run($reflex, $old);
        $waiting->start();
        $waiting->wait('Proposed.', []);
        $orphan = new IncomingEvent('generic', 'orphan', 'x', []);
        foreach ([$reflex, $old, $finished, $waiting, $orphan] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $pruner = new RunPruner($this->entityManager, new MockClock('+31 days'), 30);
        self::assertSame(1, $pruner->prune());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Run::class, $finished->getId()));
        self::assertSame(RunStatus::Waiting, $this->entityManager->find(Run::class, $waiting->getId())?->getStatus());
        self::assertNull($this->entityManager->find(IncomingEvent::class, $orphan->getId()));
        self::assertNotNull($this->entityManager->find(IncomingEvent::class, $old->getId()));
    }

    private function trigger(MockClock $clock): ScheduledTrigger
    {
        $container = self::getContainer();

        return new ScheduledTrigger($container->get(ReflexRepository::class), $container->get(EventIntake::class), $this->entityManager, $clock);
    }
}
