<?php

declare(strict_types=1);

namespace App\Message;

final readonly class PollReflex
{
    public function __construct(
        public string $reflexId,
    ) {
    }
}
