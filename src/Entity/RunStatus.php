<?php

declare(strict_types=1);

namespace App\Entity;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Not run, e.g. the daily budget was spent. */
    case Skipped = 'skipped';

    public function isFinished(): bool
    {
        return self::Queued !== $this && self::Running !== $this;
    }
}
