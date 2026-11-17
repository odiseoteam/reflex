<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ExecuteDecision
{
    public function __construct(public string $decisionId)
    {
    }
}
