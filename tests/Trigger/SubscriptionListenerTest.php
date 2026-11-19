<?php

declare(strict_types=1);

namespace App\Tests\Trigger;

use App\Entity\Connection;
use App\Entity\ConnectionAuth;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Trigger\SubscriptionListener;
use App\Trigger\SubscriptionTrigger;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\SubscriptionFilter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a server notifies reaches the reflexes subscribed to it, and only those.
 */
final class SubscriptionListenerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $tracker;
    private Reflex $watchesIssue;
    private Reflex $watchesList;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        // Unreachable on purpose: reading the resource fails and the run says so.
        $this->tracker = new Connection('Tracker', 'http://127.0.0.1:9/mcp', ConnectionAuth::None);
        $other = new Connection('Billing', 'http://127.0.0.1:9/billing', ConnectionAuth::None);
        $trackerId = $this->tracker->getId()->toRfc4122();

        $this->watchesIssue = $this->reflex('Issue 42', new SubscriptionTrigger($trackerId, ['tracker://issues/42']));
        $this->watchesList = $this->reflex('New issues', new SubscriptionTrigger($trackerId, ['tracker://issues/7'], listChanged: true));
        $elsewhere = $this->reflex('Invoices', new SubscriptionTrigger($other->getId()->toRfc4122(), ['tracker://issues/42']));
        $disabled = $this->reflex('Off', new SubscriptionTrigger($trackerId, ['tracker://issues/99']));
        $disabled->update('Off', Reflex::SUBSCRIPTION_SOURCE, Reflex::ANY_EVENT, 'x', Reflex::DEFAULT_MODEL, false, []);

        foreach ([$this->tracker, $other, $this->watchesIssue, $this->watchesList, $elsewhere, $disabled] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testThePlanJoinsWhatEachConnectionsReflexesWatch(): void
    {
        $plan = $this->listener()->plan();

        self::assertEquals(
            new SubscriptionFilter(resourcesListChanged: true, resourceSubscriptions: ['tracker://issues/42', 'tracker://issues/7']),
            $plan[$this->tracker->getId()->toRfc4122()],
        );
        self::assertCount(2, $plan);
    }

    public function testAnUpdatedResourceRunsTheReflexesWatchingIt(): void
    {
        self::assertSame(1, $this->listener()->dispatch($this->tracker, new ResourceUpdatedNotification('tracker://issues/42')));

        $runs = $this->entityManager->getRepository(Run::class)->findAll();
        self::assertCount(1, $runs);
        self::assertSame('Issue 42', $runs[0]->getReflex()->getName());
        $event = $runs[0]->getEvent();
        self::assertInstanceOf(IncomingEvent::class, $event);
        self::assertSame('mcp', $event->getSource());
        self::assertSame('resource.updated', $event->getType());
        self::assertSame('tracker://issues/42', $event->getPayload()['uri']);
        $contents = $event->getPayload()['contents'];
        self::assertIsString($contents);
        self::assertStringStartsWith('Could not read it', $contents);
    }

    public function testAListChangeRunsTheReflexesThatAskedForIt(): void
    {
        self::assertSame(1, $this->listener()->dispatch($this->tracker, new ResourceListChangedNotification()));
        self::assertSame('New issues', $this->entityManager->getRepository(Run::class)->findAll()[0]->getReflex()->getName());

        self::assertSame(0, $this->listener()->dispatch($this->tracker, new ToolListChangedNotification()));
        self::assertSame(0, $this->listener()->dispatch($this->tracker, new ResourceUpdatedNotification('tracker://issues/1')));
    }

    private function reflex(string $name, SubscriptionTrigger $trigger): Reflex
    {
        $reflex = new Reflex($name, Reflex::SUBSCRIPTION_SOURCE, Reflex::ANY_EVENT, 'React to the change.');
        $reflex->setTriggerConfig($trigger);

        return $reflex;
    }

    private function listener(): SubscriptionListener
    {
        return self::getContainer()->get(SubscriptionListener::class);
    }
}
