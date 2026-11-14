<?php

declare(strict_types=1);

namespace App\Entity;

enum ConnectionAuth: string
{
    case None = 'none';
    case OAuth = 'oauth';
    case Bearer = 'bearer';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No authentication',
            self::OAuth => 'OAuth',
            self::Bearer => 'Bearer token',
        };
    }
}
