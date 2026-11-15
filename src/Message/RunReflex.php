<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RunReflex
{
    public function __construct(public string $runId)
    {
    }
}
