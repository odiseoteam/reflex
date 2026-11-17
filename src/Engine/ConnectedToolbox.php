<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\Connection;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;

/**
 * The tools of one connection, as the agent sees them.
 */
final readonly class ConnectedToolbox
{
    public function __construct(
        public ToolboxInterface $toolbox,
        public ?Connection $connection = null,
    ) {
    }

    /**
     * The tool's name on its MCP server, without the connection prefix.
     */
    public function remoteName(string $toolName): string
    {
        $prefix = null === $this->connection ? '' : $this->connection->getSlug().'_';

        return '' !== $prefix && str_starts_with($toolName, $prefix) ? substr($toolName, \strlen($prefix)) : $toolName;
    }
}
