<?php

declare(strict_types=1);

namespace App\Trigger;

use App\Engine\EventIntake;
use App\Entity\Reflex;
use App\Entity\Waitpoint;
use App\Entity\WaitpointKind;
use App\Event\EventReceived;
use App\Repository\ReflexRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * "X without Y": event X opens a waitpoint keyed by its correlation value; Y with the
 * same value cancels it; when it's due, a run starts. Claims are single conditional
 * updates, so a late Y and the deadline can't both win.
 *
 * Correlation values are stored hashed, since they are often emails or other personal data.
 */
final readonly class AbsenceWatch
{
    public function __construct(
        private ReflexRepository $reflexes,
        private EventIntake $intake,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onEventReceived(EventReceived $received): void
    {
        $event = $received->event;
        foreach ($this->reflexes->findEnabledBySource(Reflex::ABSENCE_SOURCE) as $reflex) {
            $absence = $reflex->getAbsenceTrigger();
            if (null === $absence || $absence->source !== $event->getSource()) {
                continue;
            }

            $value = $absence->correlationIn($event->getPayload());
            if (null === $value) {
                if (\in_array($event->getType(), [$absence->whenEvent, $absence->missingEvent], true)) {
                    $this->logger->warning('Event {type} has no "{path}" for {reflex}.', ['type' => $event->getType(), 'path' => $absence->correlationPath, 'reflex' => $reflex->getName()]);
                }
                continue;
            }
            $correlation = hash('sha256', $value);

            if ($event->getType() === $absence->missingEvent) {
                $this->cancel($reflex, $correlation);
            }
            if ($event->getType() === $absence->whenEvent && null === $this->findOpen($reflex, $correlation)) {
                $due = $this->clock->now()->modify(\sprintf('+%d minutes', $absence->withinMinutes));
                $this->entityManager->persist(Waitpoint::forAbsence($reflex, $event, $correlation, $due));
                $this->entityManager->flush();
            }
        }
    }

    /**
     * Starts a run for every absence whose window passed without Y.
     *
     * @return int runs queued
     */
    public function fireDue(): int
    {
        /** @var list<Waitpoint> $due */
        $due = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(Waitpoint::class, 'w')
            ->where('w.kind = :kind')
            ->andWhere('w.resolvedAt IS NULL')
            ->andWhere('w.dueAt <= :now')
            ->setParameter('kind', WaitpointKind::Absence)
            ->setParameter('now', $this->clock->now())
            ->getQuery()
            ->getResult();

        $queued = 0;
        foreach ($due as $waitpoint) {
            $reflex = $waitpoint->getReflex();
            $event = $waitpoint->getEvent();
            $absence = $reflex?->getAbsenceTrigger();
            if (null === $reflex || null === $event || null === $absence || !$this->claim($waitpoint)) {
                continue;
            }

            $run = $this->intake->receiveFor($reflex, Reflex::ABSENCE_SOURCE, $waitpoint->getId()->toRfc4122(), $absence->whenEvent.'.without.'.$absence->missingEvent, [
                'happened' => ['type' => $event->getType(), 'at' => $event->getReceivedAt()->format(\DATE_ATOM), 'payload' => $event->getPayload()],
                'missing' => $absence->missingEvent,
                'withinMinutes' => $absence->withinMinutes,
            ]);
            $queued += null === $run ? 0 : 1;
        }

        return $queued;
    }

    private function claim(Waitpoint $waitpoint): bool
    {
        $claimed = $this->entityManager->createQueryBuilder()
            ->update(Waitpoint::class, 'w')
            ->set('w.resolvedAt', ':now')
            ->where('w.id = :id')
            ->andWhere('w.resolvedAt IS NULL')
            ->setParameter('now', $this->clock->now())
            ->setParameter('id', $waitpoint->getId(), 'uuid')
            ->getQuery()
            ->execute();

        return 1 === $claimed;
    }

    private function cancel(Reflex $reflex, string $correlation): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(Waitpoint::class, 'w')
            ->set('w.resolvedAt', ':now')
            ->set('w.cancelled', 'true')
            ->where('w.reflex = :reflex')
            ->andWhere('w.correlation = :correlation')
            ->andWhere('w.kind = :kind')
            ->andWhere('w.resolvedAt IS NULL')
            ->setParameter('now', $this->clock->now())
            ->setParameter('reflex', $reflex->getId(), 'uuid')
            ->setParameter('correlation', $correlation)
            ->setParameter('kind', WaitpointKind::Absence)
            ->getQuery()
            ->execute();
    }

    private function findOpen(Reflex $reflex, string $correlation): ?Waitpoint
    {
        return $this->entityManager->getRepository(Waitpoint::class)->findOneBy([
            'reflex' => $reflex,
            'correlation' => $correlation,
            'kind' => WaitpointKind::Absence,
            'resolvedAt' => null,
        ]);
    }
}
