<?php

declare(strict_types=1);

namespace App\Trigger;

/**
 * Listens to an MCP server and fires when it says a resource changed (2026-07-28 `subscriptions/listen`).
 */
final readonly class SubscriptionTrigger
{
    public const int MAX_URIS = 50;

    /**
     * @param list<string> $resourceUris resources whose updates fire the reflex
     * @param bool         $listChanged  whether a change to the server's resource list fires it too
     */
    public function __construct(
        public string $connectionId,
        public array $resourceUris = [],
        public bool $listChanged = false,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $uris = \is_array($data['resourceUris'] ?? null) ? $data['resourceUris'] : [];

        return new self(
            \is_string($data['connectionId'] ?? null) ? $data['connectionId'] : '',
            array_values(array_filter($uris, static fn (mixed $uri): bool => \is_string($uri) && '' !== $uri)),
            true === ($data['listChanged'] ?? false),
        );
    }

    /**
     * @return array{connectionId: string, resourceUris: list<string>, listChanged: bool}
     */
    public function toArray(): array
    {
        return ['connectionId' => $this->connectionId, 'resourceUris' => $this->resourceUris, 'listChanged' => $this->listChanged];
    }

    public function watches(string $uri): bool
    {
        return \in_array($uri, $this->resourceUris, true);
    }
}
