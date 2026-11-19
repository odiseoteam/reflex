<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Answers MCP requests without a valid token with where to get one (RFC 9728).
 */
final readonly class BearerChallenge implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(
        private Endpoints $endpoints,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->challenge(401, null, 'Sign in to Reflex to use its MCP server.');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->challenge(401, 'invalid_token', 'The access token is invalid or expired.');
    }

    /**
     * @param list<string> $scopes
     */
    public function challenge(int $status, ?string $error, string $description, array $scopes = [Scope::Read->value]): Response
    {
        $parameters = array_filter([
            'error' => $error,
            'error_description' => null === $error ? null : $description,
            'scope' => implode(' ', $scopes),
            'resource_metadata' => $this->endpoints->resourceMetadata(),
        ]);
        $header = 'Bearer '.implode(', ', array_map(static fn (string $key, string $value): string => \sprintf('%s="%s"', $key, addcslashes($value, '"\\')), array_keys($parameters), $parameters));

        return new JsonResponse(['error' => $error ?? 'unauthorized', 'error_description' => $description], $status, ['WWW-Authenticate' => $header]);
    }
}
