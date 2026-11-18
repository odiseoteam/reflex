<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\Connection;
use App\Mcp\Elicitation\QuestionBox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;

/**
 * The tools of one connection, as the agent sees them.
 */
final readonly class ConnectedToolbox
{
    public function __construct(
        public ToolboxInterface $toolbox,
        public ?Connection $connection = null,
        /** Where its server's questions land, when it can ask any. */
        public ?QuestionBox $questions = null,
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
