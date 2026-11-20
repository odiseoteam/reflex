<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Connection;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reads resources over a connection of its own, since a listening one is busy with its stream.
 */
#[AsAlias(ResourceReaderInterface::class)]
final readonly class ResourceReader implements ResourceReaderInterface
{
    private const int MAX_CONTENT = 10_000;

    public function __construct(
        private McpClientFactory $clients,
        private LoggerInterface $logger,
    ) {
    }

    public function read(Connection $connection, string $uri): array|string
    {
        try {
            $client = $this->clients->connect($connection);
            try {
                $result = $client->readResource($uri);
            } finally {
                $client->disconnect();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read {uri}.', ['uri' => $uri, 'exception' => $e]);

            return 'Could not read it: '.$e->getMessage();
        }

        $contents = [];
        foreach ($result->contents as $content) {
            $contents[] = match (true) {
                $content instanceof TextResourceContents => ['mimeType' => $content->mimeType, 'text' => mb_substr($content->text, 0, self::MAX_CONTENT)],
                $content instanceof BlobResourceContents => ['mimeType' => $content->mimeType, 'text' => '(binary, not shown)'],
                default => ['mimeType' => null, 'text' => '(unsupported content)'],
            };
        }

        return $contents;
    }
}
