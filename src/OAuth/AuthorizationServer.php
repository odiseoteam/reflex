<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Repository\OAuthGrantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * OAuth 2.1 for public clients: authorization codes bound to PKCE (S256) and to the MCP
 * endpoint, short-lived access tokens, and refresh tokens that rotate on every use.
 */
final readonly class AuthorizationServer
{
    public function __construct(
        private OAuthGrantRepository $grants,
        private ClientRegistry $clients,
        private Endpoints $endpoints,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Records the person's consent and returns the one-time code for the client.
     *
     * @param list<Scope> $scopes
     */
    public function authorize(OAuthClient $client, User $user, string $redirectUri, string $codeChallenge, array $scopes): string
    {
        $code = self::secret('rfx_code_');
        $grant = new OAuthGrant($client, $user, array_map(static fn (Scope $scope): string => $scope->value, $scopes), $this->endpoints->resource(), $redirectUri, $codeChallenge, $code);
        $this->entityManager->persist($grant);
        $this->entityManager->flush();

        return $code;
    }

    /**
     * @return array<string, mixed> the token response
     *
     * @throws OAuthException
     */
    public function exchange(string $grantType, \Closure $param): array
    {
        return match ($grantType) {
            'authorization_code' => $this->redeemCode($param),
            'refresh_token' => $this->refresh($param),
            default => throw new OAuthException('unsupported_grant_type', 'Use "authorization_code" or "refresh_token".'),
        };
    }

    /**
     * Revokes a token and every token of its grant (RFC 7009); unknown tokens are ignored.
     */
    public function revoke(string $token, ?string $clientId): void
    {
        $grant = $this->grants->findByAccessToken($token) ?? $this->grants->findByRefreshToken($token)[0] ?? null;
        if (null === $grant || (null !== $clientId && $grant->getClient()->getClientId() !== $clientId)) {
            return;
        }

        $grant->revoke();
        $this->entityManager->flush();
    }

    /**
     * The grant behind a valid access token for our MCP endpoint.
     */
    public function validate(string $accessToken): ?OAuthGrant
    {
        $grant = $this->grants->findByAccessToken($accessToken);
        $now = $this->clock->now();
        if (null === $grant || !$grant->isAccessValid($now) || !$this->endpoints->isOurResource($grant->getResource())) {
            return null;
        }

        // Written at most once a minute, not on every MCP request.
        if (null === $grant->getLastUsedAt() || $grant->getLastUsedAt() < $now->modify('-1 minute')) {
            $grant->touch($now);
            $this->entityManager->flush();
        }

        return $grant;
    }

    /**
     * @param \Closure(string): ?string $param
     *
     * @return array<string, mixed>
     */
    private function redeemCode(\Closure $param): array
    {
        $grant = $this->grants->findByCode(self::required($param, 'code'));
        $client = $this->client($param);
        $now = $this->clock->now();

        if (null === $grant || $grant->getClient() !== $client) {
            throw new OAuthException('invalid_grant', 'Unknown authorization code.');
        }
        if ($grant->wasCodeUsed()) {
            // A replayed code may have been stolen: what it gave out stops working.
            $grant->revoke();
            $this->entityManager->flush();

            throw new OAuthException('invalid_grant', 'The authorization code was already used.');
        }
        if (!$grant->isCodeRedeemable($now)) {
            throw new OAuthException('invalid_grant', 'The authorization code expired.');
        }
        if ($param('redirect_uri') !== $grant->getRedirectUri()) {
            throw new OAuthException('invalid_grant', 'The redirect_uri differs from the one authorized.');
        }
        if (!self::verifies(self::required($param, 'code_verifier'), $grant->getCodeChallenge())) {
            throw new OAuthException('invalid_grant', 'The code_verifier does not match the code_challenge.');
        }
        $this->checkResource($param, $grant);

        return $this->issue($grant, $now);
    }

    /**
     * @param \Closure(string): ?string $param
     *
     * @return array<string, mixed>
     */
    private function refresh(\Closure $param): array
    {
        $found = $this->grants->findByRefreshToken(self::required($param, 'refresh_token'));
        $client = $this->client($param);
        $now = $this->clock->now();

        if (null === $found || $found[0]->getClient() !== $client) {
            throw new OAuthException('invalid_grant', 'Unknown refresh token.');
        }

        [$grant, $rotated] = $found;
        if ($rotated) {
            $grant->revoke();
            $this->entityManager->flush();

            throw new OAuthException('invalid_grant', 'The refresh token was already used; sign in again.');
        }
        if (!$grant->isRefreshValid($now)) {
            throw new OAuthException('invalid_grant', 'The refresh token expired or was revoked.');
        }
        $this->checkResource($param, $grant);

        return $this->issue($grant, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(OAuthGrant $grant, \DateTimeImmutable $now): array
    {
        $access = self::secret('rfx_at_');
        $refresh = self::secret('rfx_rt_');
        $grant->issue($access, $refresh, $now);
        $this->entityManager->flush();

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => OAuthGrant::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $grant->getScopes()),
        ];
    }

    /**
     * @param \Closure(string): ?string $param
     */
    private function client(\Closure $param): OAuthClient
    {
        return $this->clients->find(self::required($param, 'client_id'))
            ?? throw new OAuthException('invalid_client', 'Unknown client.', 401);
    }

    /**
     * @param \Closure(string): ?string $param
     */
    private function checkResource(\Closure $param, OAuthGrant $grant): void
    {
        $resource = $param('resource');
        if (null !== $resource && !$this->endpoints->isOurResource($resource)) {
            throw new OAuthException('invalid_target', 'Tokens are only issued for this server\'s MCP endpoint.');
        }
    }

    /**
     * @param \Closure(string): ?string $param
     */
    private static function required(\Closure $param, string $name): string
    {
        $value = $param($name);

        return null === $value || '' === $value ? throw new OAuthException('invalid_request', \sprintf('Missing "%s".', $name)) : $value;
    }

    private static function verifies(string $verifier, string $challenge): bool
    {
        if (1 !== preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            return false;
        }

        return hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
    }

    private static function secret(string $prefix): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
