<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Connection;

/**
 * Reads an MCP resource of a connection as it is now.
 */
interface ResourceReaderInterface
{
    /**
     * @return list<array{mimeType: string|null, text: string}>|string text contents, or why they couldn't be read
     */
    public function read(Connection $connection, string $uri): array|string;
}
