<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Signs MCP requests in as the person who granted the access token.
 */
final readonly class McpTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private AuthorizationServer $server,
        private CurrentGrant $current,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $grant = $this->server->validate($accessToken) ?? throw new BadCredentialsException('Invalid or expired access token.');
        $this->current->set($grant);
        $user = $grant->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
