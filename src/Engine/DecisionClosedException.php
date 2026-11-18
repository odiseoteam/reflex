<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Someone else decided first, or this person already voted.
 */
final class DecisionClosedException extends \RuntimeException
{
}
