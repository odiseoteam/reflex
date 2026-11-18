<?php

declare(strict_types=1);

namespace App\Tests\Trigger;

use App\Entity\Reflex;
use App\Entity\Run;
use App\Repository\ReflexRepository;
use App\Trigger\Poller;
use App\Trigger\PollTrigger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PollerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Reflex $reflex;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->reflex = new Reflex('New issues', Reflex::POLL_SOURCE, Reflex::ANY_EVENT, 'Triage new issues.');
        $this->entityManager->persist($this->reflex);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testTheFirstPollIsABaselineThenOnlyNewItemsRun(): void
    {
        $poll = new PollTrigger('c', 'list_issues');
        $poller = self::getContainer()->get(Poller::class);

        self::assertFalse($poller->compare($this->reflex, $poll, ['issues' => [['id' => 1], ['id' => 2]]]));
        self::assertFalse($poller->compare($this->reflex, $poll, ['issues' => [['id' => 2], ['id' => 1]]]));
        self::assertTrue($poller->compare($this->reflex, $poll, ['issues' => [['id' => 3, 'title' => 'New'], ['id' => 2], ['id' => 1]]]));
        // Items that dropped out and came back are not new.
        self::assertFalse($poller->compare($this->reflex, $poll, ['issues' => [['id' => 1]]]));

        $runs = $this->entityManager->getRepository(Run::class)->findBy(['reflex' => $this->reflex]);
        self::assertCount(1, $runs);
        self::assertSame('items.new', $runs[0]->getEvent()?->getType());
        self::assertSame([['id' => 3, 'title' => 'New']], $runs[0]->getEvent()->getPayload()['items']);
    }

    public function testWithoutAnItemKeyAnyChangeRuns(): void
    {
        $poll = new PollTrigger('c', 'get_balance', itemKey: null);
        $poller = self::getContainer()->get(Poller::class);

        self::assertFalse($poller->compare($this->reflex, $poll, ['available' => 100]));
        self::assertFalse($poller->compare($this->reflex, $poll, ['available' => 100]));
        self::assertTrue($poller->compare($this->reflex, $poll, ['available' => 40]));
        self::assertTrue($poller->compare($this->reflex, $poll, ['available' => 100]), 'Going back to an earlier value is a change too.');
    }

    public function testIntervalsAreClaimedOnce(): void
    {
        $this->reflex->setTriggerConfig(new PollTrigger('c', 'list_issues', intervalMinutes: 10));
        $now = new \DateTimeImmutable('2026-11-18 10:00');

        $reflexes = self::getContainer()->get(ReflexRepository::class);
        $this->entityManager->flush();

        self::assertTrue($this->reflex->isPollDue($now));
        self::assertTrue($reflexes->claimPoll($this->reflex, $now, 10));
        self::assertFalse($reflexes->claimPoll($this->reflex, $now, 10), 'A second worker gets nothing.');
        self::assertFalse($this->reflex->isPollDue($now->modify('+9 minutes')));
        self::assertTrue($reflexes->claimPoll($this->reflex, $now->modify('+10 minutes'), 10));
    }
}
