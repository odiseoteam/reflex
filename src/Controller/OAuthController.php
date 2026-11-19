<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OAuthClient;
use App\Entity\User;
use App\OAuth\AuthorizationServer;
use App\OAuth\ClientRegistry;
use App\OAuth\Endpoints;
use App\OAuth\OAuthException;
use App\OAuth\Scope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The authorization server MCP clients discover from the protected MCP endpoint.
 */
final class OAuthController extends AbstractController
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ClientRegistry $clients,
        private readonly Endpoints $endpoints,
        #[Target('oauth_register.limiter')]
        private readonly RateLimiterFactoryInterface $registrations,
        #[Target('oauth_token.limiter')]
        private readonly RateLimiterFactoryInterface $tokenRequests,
    ) {
    }

    /**
     * Protected resource metadata (RFC 9728), also at the path-suffixed URL of the endpoint.
     */
    #[Route('/.well-known/oauth-protected-resource{suffix}', name: 'oauth_protected_resource', requirements: ['suffix' => '(/.*)?'], defaults: ['suffix' => ''], methods: ['GET'])]
    public function protectedResource(): JsonResponse
    {
        return $this->metadata([
            'resource' => $this->endpoints->resource(),
            'authorization_servers' => [$this->endpoints->issuer()],
            'scopes_supported' => Scope::names(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Reflex',
        ]);
    }

    /**
     * Authorization server metadata (RFC 8414).
     */
    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_server_metadata', methods: ['GET'])]
    public function serverMetadata(): JsonResponse
    {
        return $this->metadata([
            'issuer' => $this->endpoints->issuer(),
            'authorization_endpoint' => $this->endpoints->url('oauth_authorize'),
            'token_endpoint' => $this->endpoints->url('oauth_token'),
            'registration_endpoint' => $this->endpoints->url('oauth_register'),
            'revocation_endpoint' => $this->endpoints->url('oauth_revoke'),
            'scopes_supported' => Scope::names(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => true,
        ]);
    }

    /**
     * Dynamic client registration (RFC 7591). Its body is free-form metadata answered with
     * RFC 7591 errors, so it is read directly rather than mapped to a DTO.
     */
    #[Route('/oauth/register', name: 'oauth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        if (!$this->registrations->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->error(new OAuthException('temporarily_unavailable', 'Too many registrations, try again later.', 429));
        }

        try {
            $client = $this->clients->register($request->toArray());
        } catch (OAuthException $e) {
            return $this->error($e);
        } catch (\Throwable) {
            return $this->error(new OAuthException('invalid_client_metadata', 'Send the client metadata as a JSON object.'));
        }

        return new JsonResponse([
            'client_id' => $client->getClientId(),
            'client_id_issued_at' => $client->getCreatedAt()->getTimestamp(),
            'client_name' => $client->getName(),
            'redirect_uris' => $client->getRedirectUris(),
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], 201, ['Cache-Control' => 'no-store']);
    }

    #[Route('/oauth/token', name: 'oauth_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        if (!$this->tokenRequests->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->error(new OAuthException('temporarily_unavailable', 'Too many token requests, try again later.', 429));
        }

        $param = static function (string $name) use ($request): ?string {
            $value = $request->request->get($name);

            return \is_string($value) ? $value : null;
        };

        try {
            $tokens = $this->server->exchange((string) $param('grant_type'), $param);
        } catch (OAuthException $e) {
            return $this->error($e);
        }

        return new JsonResponse($tokens, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    #[Route('/oauth/revoke', name: 'oauth_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $token = $request->request->get('token');
        if (\is_string($token) && '' !== $token) {
            $clientId = $request->request->get('client_id');
            $this->server->revoke($token, \is_string($clientId) ? $clientId : null);
        }

        return new Response(null, 200);
    }

    /**
     * Asks the signed-in person whether the app may act as them. Problems with the client or
     * its redirect URI are shown here; the rest go back to the app.
     */
    #[Route('/oauth/authorize', name: 'oauth_authorize', methods: ['GET', 'POST'])]
    public function authorize(Request $request, #[CurrentUser] User $user): Response
    {
        $query = $request->query;
        $redirectUri = $query->getString('redirect_uri');
        $state = $query->has('state') ? $query->getString('state') : null;

        try {
            $client = $this->clients->find($query->getString('client_id'));
        } catch (OAuthException $e) {
            return $this->render('oauth/error.html.twig', ['message' => $e->getMessage()], new Response(null, 400));
        }
        if (null === $client) {
            return $this->render('oauth/error.html.twig', ['message' => 'This app is not registered with Reflex.'], new Response(null, 400));
        }
        if (!ClientRegistry::allowsRedirect($client, $redirectUri)) {
            return $this->render('oauth/error.html.twig', ['message' => 'This app asked to send you to an address it did not register.'], new Response(null, 400));
        }

        $back = fn (array $params): RedirectResponse => $this->back($redirectUri, $params + ['state' => $state, 'iss' => $this->endpoints->issuer()]);

        if ('code' !== $query->getString('response_type')) {
            return $back(['error' => 'unsupported_response_type']);
        }
        $challenge = $query->getString('code_challenge');
        if ('S256' !== $query->getString('code_challenge_method') || 1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            return $back(['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.']);
        }
        if ($query->has('resource') && !$this->endpoints->isOurResource($query->getString('resource'))) {
            return $back(['error' => 'invalid_target', 'error_description' => 'Reflex only issues tokens for its MCP endpoint.']);
        }

        $scopes = Scope::grantable($query->has('scope') ? $query->getString('scope') : null, $user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('oauth-consent', $request->getPayload()->getString('_token'))) {
                return $back(['error' => 'access_denied', 'error_description' => 'The consent form expired.']);
            }
            if ('allow' !== $request->getPayload()->getString('decision')) {
                return $back(['error' => 'access_denied']);
            }

            return $back(['code' => $this->server->authorize($client, $user, $redirectUri, $challenge, $scopes)]);
        }

        return $this->render('oauth/consent.html.twig', [
            'client' => $client,
            'client_host' => self::host($client),
            'redirect_host' => parse_url($redirectUri, \PHP_URL_HOST) ?: $redirectUri,
            'scopes' => $scopes,
            'read_only' => !\in_array(Scope::Write, $scopes, true),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadata(array $metadata): JsonResponse
    {
        return new JsonResponse($metadata, 200, ['Cache-Control' => 'public, max-age=3600'], false);
    }

    private function error(OAuthException $e): JsonResponse
    {
        return new JsonResponse($e->toArray(), $e->status, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param array<mixed> $params
     */
    private function back(string $redirectUri, array $params): RedirectResponse
    {
        $query = http_build_query(array_filter($params, static fn (mixed $value): bool => \is_string($value)));

        return new RedirectResponse($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query);
    }

    private static function host(OAuthClient $client): ?string
    {
        return $client->isMetadataDocument() ? parse_url($client->getClientId(), \PHP_URL_HOST) ?: null : null;
    }
}
