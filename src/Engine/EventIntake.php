<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Message\RunReflex;
use App\Repository\ReflexRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Stores an incoming event once and queues a run for every reflex it triggers.
 */
final readonly class EventIntake
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReflexRepository $reflexes,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<Run> the queued runs, none for a redelivered event
     */
    public function receive(string $source, string $externalId, string $type, array $payload): array
    {
        if (null !== $this->entityManager->getRepository(IncomingEvent::class)->findOneBy(['source' => $source, 'externalId' => $externalId])) {
            $this->logger->info('Dropped redelivered event {source}/{id}.', ['source' => $source, 'id' => $externalId]);

            return [];
        }

        return $this->queue(new IncomingEvent($source, $externalId, $type, $payload), $this->reflexes->findTriggeredBy($source, $type));
    }

    /**
     * Same as receive(), for an event meant for one reflex only, such as a schedule slot.
     *
     * @param array<mixed> $payload
     */
    public function receiveFor(Reflex $reflex, string $source, string $externalId, string $type, array $payload): ?Run
    {
        if (null !== $this->entityManager->getRepository(IncomingEvent::class)->findOneBy(['source' => $source, 'externalId' => $externalId])) {
            return null;
        }

        return $this->queue(new IncomingEvent($source, $externalId, $type, $payload), [$reflex])[0] ?? null;
    }

    /**
     * @param iterable<Reflex> $reflexes
     *
     * @return list<Run>
     */
    private function queue(IncomingEvent $event, iterable $reflexes): array
    {
        $this->entityManager->persist($event);

        $runs = [];
        foreach ($reflexes as $reflex) {
            $runs[] = $run = new Run($reflex, $event);
            $this->entityManager->persist($run);
        }

        // The unique (source, external_id) index settles concurrent redeliveries.
        $this->entityManager->flush();

        foreach ($runs as $run) {
            $this->bus->dispatch(new RunReflex($run->getId()->toRfc4122()));
        }

        return $runs;
    }
}
