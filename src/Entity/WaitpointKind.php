<?php

declare(strict_types=1);

namespace App\Entity;

enum WaitpointKind: string
{
    /** Waits until every decision of the waitpoint is resolved. */
    case Decisions = 'decisions';
}
