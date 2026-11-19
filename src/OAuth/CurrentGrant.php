<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OAuthGrant;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The grant behind the access token of the current MCP request.
 */
final class CurrentGrant implements ResetInterface
{
    private ?OAuthGrant $grant = null;

    public function set(OAuthGrant $grant): void
    {
        $this->grant = $grant;
    }

    public function get(): ?OAuthGrant
    {
        return $this->grant;
    }

    public function allows(Scope $scope): bool
    {
        return null !== $this->grant && \in_array($scope->value, $this->grant->getScopes(), true);
    }

    public function reset(): void
    {
        $this->grant = null;
    }
}
