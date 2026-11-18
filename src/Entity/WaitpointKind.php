<?php

declare(strict_types=1);

namespace App\Entity;

enum WaitpointKind: string
{
    /** Waits until every decision of the waitpoint is resolved. */
    case Decisions = 'decisions';
    /** Waits for an event Y after an event X; starts a run if Y doesn't come in time. */
    case Absence = 'absence';
}
