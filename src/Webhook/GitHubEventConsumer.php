<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;

#[AsRemoteEventConsumer('github')]
final class GitHubEventConsumer extends EventConsumer
{
    protected function source(): string
    {
        return 'github';
    }
}
