<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\ConnectionStatus;
use App\Entity\Run;
use App\Mcp\ConnectionToolset;
use App\Mcp\McpClientFactory;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * MCP tools of the reflex's connected servers, opened lazily.
 */
#[AsAlias(ToolboxProviderInterface::class)]
final class ConnectionToolboxProvider implements ToolboxProviderInterface
{
    /** @var list<ConnectionToolset> */
    private array $toolsets = [];

    public function __construct(
        private readonly McpClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function open(Run $run): array
    {
        $toolboxes = [];
        foreach ($run->getReflex()->getConnections() as $connection) {
            if (ConnectionStatus::Connected === $connection->getStatus()) {
                $this->toolsets[] = $toolset = new ConnectionToolset($connection, $this->clientFactory);
                $toolboxes[] = new ConnectedToolbox(new McpToolbox($toolset, logger: $this->logger), $connection, $toolset->getQuestions());
            }
        }

        return $toolboxes;
    }

    public function close(): void
    {
        foreach ($this->toolsets as $toolset) {
            $toolset->disconnect();
        }
        $this->toolsets = [];
    }
}
