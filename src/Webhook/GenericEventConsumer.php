<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;

#[AsRemoteEventConsumer('generic')]
final class GenericEventConsumer extends EventConsumer
{
    protected function source(): string
    {
        return 'generic';
    }
}
