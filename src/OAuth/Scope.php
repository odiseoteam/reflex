<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\User;

enum Scope: string
{
    case Read = 'reflex:read';
    case Write = 'reflex:write';

    public function describe(): string
    {
        return match ($this) {
            self::Read => 'See reflexes, runs and the decision inbox',
            self::Write => 'Approve, reject and answer decisions, and create and run reflexes',
        };
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * What the person can give out of what was asked: all scopes when none are named,
     * and never more than their own role allows.
     *
     * @return list<self>
     */
    public static function grantable(?string $requested, User $user): array
    {
        $names = preg_split('/\s+/', trim((string) $requested), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $scopes = [] === $names ? self::cases() : array_values(array_filter(array_map(self::tryFrom(...), $names)));

        if (!$user->isAdmin()) {
            $scopes = array_values(array_filter($scopes, static fn (self $scope): bool => self::Write !== $scope));
        }

        // Writing without reading isn't useful for an MCP client.
        if ([] !== $scopes && !\in_array(self::Read, $scopes, true)) {
            array_unshift($scopes, self::Read);
        }

        return $scopes;
    }
}
