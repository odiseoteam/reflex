<?php

declare(strict_types=1);

namespace App\Tests\Trigger;

use App\Engine\EventIntake;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\Waitpoint;
use App\Trigger\AbsenceTrigger;
use App\Trigger\AbsenceWatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AbsenceWatchTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $entityManager;
    private Reflex $reflex;

    protected function setUp(): void
    {
        self::mockTime('2026-11-18 10:00:00');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->reflex = new Reflex('Abandoned checkout', Reflex::ABSENCE_SOURCE, Reflex::ANY_EVENT, 'Write to the customer.');
        $this->reflex->setTriggerConfig(new AbsenceTrigger('generic', 'email_captured', 'order_completed', 30, 'customer.email'));
        $this->entityManager->persist($this->reflex);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testRunsOnlyWhenYDoesNotFollowXForTheSameValue(): void
    {
        $intake = self::getContainer()->get(EventIntake::class);
        $watch = self::getContainer()->get(AbsenceWatch::class);

        $intake->receive('generic', 'd-1', 'email_captured', ['customer' => ['email' => 'ann@example.com']]);
        $intake->receive('generic', 'd-2', 'email_captured', ['customer' => ['email' => 'bob@example.com']]);
        $intake->receive('generic', 'd-3', 'email_captured', ['customer' => []]);
        self::assertCount(2, $this->open());

        self::mockTime('+20 minutes');
        $intake->receive('generic', 'd-4', 'order_completed', ['customer' => ['email' => 'ann@example.com']]);
        self::assertSame(0, $watch->fireDue(), 'Nothing is due yet.');
        self::assertCount(1, $this->open());

        self::mockTime('+11 minutes');
        self::assertSame(1, $watch->fireDue());
        self::assertSame(0, $watch->fireDue(), 'A due absence fires once.');

        $runs = $this->entityManager->getRepository(Run::class)->findBy(['reflex' => $this->reflex]);
        self::assertCount(1, $runs);
        $event = $runs[0]->getEvent();
        self::assertSame('email_captured.without.order_completed', $event?->getType());
        self::assertSame(['type' => 'email_captured', 'payload' => ['customer' => ['email' => 'bob@example.com']]], array_intersect_key((array) $event->getPayload()['happened'], ['type' => 1, 'payload' => 1]));

        // A late Y changes nothing.
        $intake->receive('generic', 'd-5', 'order_completed', ['customer' => ['email' => 'bob@example.com']]);
        self::assertCount(1, $this->entityManager->getRepository(Run::class)->findBy(['reflex' => $this->reflex]));
    }

    public function testCorrelationValuesAreStoredHashed(): void
    {
        self::getContainer()->get(EventIntake::class)->receive('generic', 'd-1', 'email_captured', ['customer' => ['email' => 'ann@example.com']]);

        self::assertSame(hash('sha256', 'ann@example.com'), $this->open()[0]->getCorrelation());
    }

    /**
     * @return list<Waitpoint>
     */
    private function open(): array
    {
        /** @var list<Waitpoint> $open */
        $open = $this->entityManager->getRepository(Waitpoint::class)->findBy(['reflex' => $this->reflex, 'resolvedAt' => null]);

        return $open;
    }
}
