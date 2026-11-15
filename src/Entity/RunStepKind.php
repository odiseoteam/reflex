<?php

declare(strict_types=1);

namespace App\Entity;

enum RunStepKind: string
{
    /** An automatic tool ran. */
    case ToolCall = 'tool_call';
    /** A tool that needs approval was proposed, not run. */
    case Proposal = 'proposal';
    /** The agent asked for a tool outside its policy. */
    case Blocked = 'blocked';
    /** A tool failed. */
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::ToolCall => 'Ran',
            self::Proposal => 'Proposed',
            self::Blocked => 'Blocked',
            self::Error => 'Failed',
        };
    }
}
