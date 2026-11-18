<?php

declare(strict_types=1);

namespace App\Entity;

enum Verdict: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Answered = 'answered';
    case Declined = 'declined';
}
