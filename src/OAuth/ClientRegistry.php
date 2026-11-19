<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OAuthClient;
use App\Repository\OAuthClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Knows the apps that may ask for access: those that registered themselves, and those whose
 * client id is the HTTPS URL of a metadata document, fetched and cached for a day.
 */
final readonly class ClientRegistry
{
    private const int MAX_DOCUMENT_BYTES = 10_000;
    private const int MAX_REDIRECT_URIS = 10;
    private const string DOCUMENT_TTL = '-1 day';

    private HttpClientInterface $documents;

    public function __construct(
        private OAuthClientRepository $clients,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        HttpClientInterface $httpClient,
    ) {
        // A metadata document URL comes from an unknown app: never let it reach the internal network.
        $this->documents = new NoPrivateNetworkHttpClient($httpClient->withOptions(['timeout' => 5, 'max_duration' => 5, 'max_redirects' => 0]));
    }

    /**
     * Dynamic client registration (RFC 7591), for public clients using PKCE.
     *
     * @param array<mixed> $metadata
     *
     * @throws OAuthException
     */
    public function register(array $metadata): OAuthClient
    {
        $method = $metadata['token_endpoint_auth_method'] ?? 'none';
        if ('none' !== $method) {
            throw new OAuthException('invalid_client_metadata', 'Only public clients are supported: use "token_endpoint_auth_method": "none" with PKCE.');
        }

        $client = new OAuthClient('rfx_'.bin2hex(random_bytes(16)), self::name($metadata), self::redirectUris($metadata));
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    /**
     * @throws OAuthException when an unknown client id is no usable metadata document
     */
    public function find(string $clientId): ?OAuthClient
    {
        $client = $this->clients->findOneByClientId($clientId);
        if (!self::isDocumentUrl($clientId)) {
            return $client;
        }

        if (null !== $client && $client->getRefreshedAt() > $this->clock->now()->modify(self::DOCUMENT_TTL)) {
            return $client;
        }

        [$name, $redirectUris] = $this->fetchDocument($clientId);
        if (null === $client) {
            $client = new OAuthClient($clientId, $name, $redirectUris, metadataDocument: true);
            $this->entityManager->persist($client);
        } else {
            $client->refresh($name, $redirectUris);
        }
        $this->entityManager->flush();

        return $client;
    }

    public static function isDocumentUrl(string $clientId): bool
    {
        $parts = parse_url($clientId);

        return 'https' === ($parts['scheme'] ?? null) && isset($parts['host']) && '' !== trim($parts['path'] ?? '', '/') && !isset($parts['fragment']);
    }

    /**
     * Exact match, except that a loopback redirect may use any port (RFC 8252).
     */
    public static function allowsRedirect(OAuthClient $client, string $uri): bool
    {
        if ($client->allowsRedirect($uri)) {
            return true;
        }

        $withoutPort = static fn (string $url): ?string => self::isLoopback($url) ? preg_replace('#^(http://[^/:]+):\d+#', '$1', $url) : null;
        $wanted = $withoutPort($uri);

        return null !== $wanted && \in_array($wanted, array_map($withoutPort, $client->getRedirectUris()), true);
    }

    /**
     * @return array{string, list<string>}
     *
     * @throws OAuthException
     */
    private function fetchDocument(string $url): array
    {
        try {
            $response = $this->documents->request('GET', $url, ['headers' => ['Accept' => 'application/json']]);
            $body = $response->getContent();
            if (\strlen($body) > self::MAX_DOCUMENT_BYTES) {
                throw new OAuthException('invalid_client', 'The client metadata document is too large.');
            }
            $metadata = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (OAuthException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new OAuthException('invalid_client', 'The client metadata document could not be fetched.');
        }

        if (!\is_array($metadata) || ($metadata['client_id'] ?? null) !== $url) {
            throw new OAuthException('invalid_client', 'The client metadata document must name its own URL as "client_id".');
        }
        if (($metadata['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            throw new OAuthException('invalid_client', 'Only public clients are supported.');
        }

        return [self::name($metadata), self::redirectUris($metadata)];
    }

    /**
     * @param array<mixed> $metadata
     */
    private static function name(array $metadata): string
    {
        $name = $metadata['client_name'] ?? null;

        return \is_string($name) && '' !== trim($name) ? mb_substr(trim(strip_tags($name)), 0, 100) : 'Unnamed app';
    }

    /**
     * @param array<mixed> $metadata
     *
     * @return list<string>
     *
     * @throws OAuthException
     */
    private static function redirectUris(array $metadata): array
    {
        $uris = $metadata['redirect_uris'] ?? null;
        if (!\is_array($uris) || [] === $uris || \count($uris) > self::MAX_REDIRECT_URIS) {
            throw new OAuthException('invalid_redirect_uri', \sprintf('Give between 1 and %d "redirect_uris".', self::MAX_REDIRECT_URIS));
        }

        $valid = [];
        foreach ($uris as $uri) {
            if (!\is_string($uri) || !self::isAcceptableRedirect($uri)) {
                throw new OAuthException('invalid_redirect_uri', 'Redirect URIs must be HTTPS, a loopback address over HTTP, or an app scheme, without a fragment.');
            }
            $valid[] = $uri;
        }

        return array_values(array_unique($valid));
    }

    private static function isAcceptableRedirect(string $uri): bool
    {
        if (\strlen($uri) > 2048 || str_contains($uri, '#')) {
            return false;
        }

        $parts = parse_url($uri);
        $scheme = strtolower($parts['scheme'] ?? '');

        return match (true) {
            'https' === $scheme => isset($parts['host']),
            'http' === $scheme => self::isLoopback($uri),
            // Native apps use a private scheme such as "cursor://"; never one that runs code.
            default => 1 === preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) && !\in_array($scheme, ['javascript', 'data', 'vbscript', 'file'], true),
        };
    }

    private static function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);

        return 'http' === strtolower($parts['scheme'] ?? '') && \in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
