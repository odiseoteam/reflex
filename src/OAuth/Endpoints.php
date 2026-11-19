<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Absolute URLs of the authorization server and the MCP endpoint it issues tokens for.
 */
final readonly class Endpoints
{
    public const string MCP_ROUTE = '_mcp_endpoint_reflex';

    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function issuer(): string
    {
        return rtrim($this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), '/');
    }

    public function resource(): string
    {
        return $this->urls->generate(self::MCP_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function resourceMetadata(): string
    {
        return $this->issuer().'/.well-known/oauth-protected-resource'.parse_url($this->resource(), \PHP_URL_PATH);
    }

    public function url(string $route): string
    {
        return $this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Whether a resource indicator names our MCP endpoint, ignoring case of the host and a trailing slash.
     */
    public function isOurResource(string $resource): bool
    {
        $normalize = static function (string $url): string {
            $parts = parse_url($url);

            return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')).rtrim($parts['path'] ?? '', '/');
        };

        return $normalize($resource) === $normalize($this->resource());
    }
}
