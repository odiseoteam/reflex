<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;

#[AsRemoteEventConsumer('stripe')]
final class StripeEventConsumer extends EventConsumer
{
    protected function source(): string
    {
        return 'stripe';
    }
}
