<?php

declare(strict_types=1);

namespace App\Entity;

enum ConnectionStatus: string
{
    case New = 'new';
    case Connected = 'connected';
    case NeedsAuthorization = 'needs_authorization';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Not connected',
            self::Connected => 'Connected',
            self::NeedsAuthorization => 'Needs authorization',
            self::Failed => 'Failed',
        };
    }
}
