<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Engine\EventIntake;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

#[AsRemoteEventConsumer('generic')]
final readonly class WebhookEventConsumer implements ConsumerInterface
{
    public function __construct(private EventIntake $intake)
    {
    }

    public function consume(RemoteEvent $event): void
    {
        $this->intake->receive('generic', $event->getId(), $event->getName(), $event->getPayload());
    }
}
