<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Connection;
use Mcp\Client\Client;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;

/**
 * Tools of one connection, opened on first use.
 */
final class ConnectionToolset implements ToolsetInterface
{
    private ?Client $client = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly McpClientFactory $clientFactory,
    ) {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getName(): string
    {
        return $this->connection->getSlug();
    }

    public function getTools(): array
    {
        $tools = [];
        $cursor = null;
        do {
            $page = $this->client()->listTools($cursor);
            foreach ($page->tools as $tool) {
                $tools[] = $tool;
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor);

        return $tools;
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        return $this->client()->callTool($name, $arguments);
    }

    public function disconnect(): void
    {
        $this->client?->disconnect();
        $this->client = null;
    }

    private function client(): Client
    {
        return $this->client ??= $this->clientFactory->connect($this->connection);
    }
}
