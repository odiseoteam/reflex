<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Engine\EventIntake;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Hands the events of one webhook source to the engine.
 */
abstract class EventConsumer implements ConsumerInterface
{
    public function __construct(private readonly EventIntake $intake)
    {
    }

    abstract protected function source(): string;

    public function consume(RemoteEvent $event): void
    {
        $this->intake->receive($this->source(), $event->getId(), $event->getName(), $event->getPayload());
    }
}
