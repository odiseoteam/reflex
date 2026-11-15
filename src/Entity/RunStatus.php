<?php

declare(strict_types=1);

namespace App\Entity;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return self::Succeeded === $this || self::Failed === $this;
    }
}
