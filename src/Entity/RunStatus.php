<?php

declare(strict_types=1);

namespace App\Entity;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    /** Paused until a waitpoint resolves, e.g. people decide on its proposals. */
    case Waiting = 'waiting';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Not run, e.g. the daily budget was spent. */
    case Skipped = 'skipped';

    public function isFinished(): bool
    {
        return \in_array($this, [self::Succeeded, self::Failed, self::Skipped], true);
    }
}
