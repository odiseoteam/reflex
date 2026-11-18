<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\IncomingEvent;

/**
 * An event was stored for the first time.
 */
final readonly class EventReceived
{
    public function __construct(
        public IncomingEvent $event,
    ) {
    }
}
