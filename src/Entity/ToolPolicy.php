<?php

declare(strict_types=1);

namespace App\Entity;

enum ToolPolicy: string
{
    /** Runs without asking. */
    case Auto = 'auto';
    /** The agent may only propose it; a person decides. */
    case Approval = 'approval';
    /** The agent never sees it. */
    case Forbidden = 'forbidden';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::Approval => 'Needs approval',
            self::Forbidden => 'Forbidden',
        };
    }

    /**
     * Policy of a tool nobody configured: reads run, anything else waits for a person.
     *
     * @param array<string, mixed> $annotations
     */
    public static function default(array $annotations): self
    {
        return true === ($annotations['readOnlyHint'] ?? false) ? self::Auto : self::Approval;
    }
}
