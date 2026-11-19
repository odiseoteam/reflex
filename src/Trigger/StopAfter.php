<?php

declare(strict_types=1);

namespace App\Trigger;

use Mcp\Client\CancellationTokenInterface;

/**
 * Stops listening after a while, so the listener picks up configuration changes.
 */
final readonly class StopAfter implements CancellationTokenInterface
{
    private float $deadline;

    public function __construct(float $seconds)
    {
        $this->deadline = microtime(true) + $seconds;
    }

    public function isCancellationRequested(): bool
    {
        return microtime(true) >= $this->deadline;
    }
}
