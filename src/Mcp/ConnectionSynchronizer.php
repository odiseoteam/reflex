<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client\Exception\AuthorizationPendingException;
use Mcp\Client\Exception\ExceptionInterface as McpException;

/**
 * Connects to a server and refreshes the stored tool catalog.
 */
final readonly class ConnectionSynchronizer
{
    public function __construct(
        private McpClientFactory $clientFactory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws AuthorizationPendingException when the user must authorize in the browser first
     */
    public function synchronize(Connection $connection): void
    {
        try {
            $client = $this->clientFactory->connect($connection);
            try {
                $tools = [];
                $cursor = null;
                do {
                    $page = $client->listTools($cursor);
                    foreach ($page->tools as $tool) {
                        $tools[] = $tool->jsonSerialize();
                    }
                    $cursor = $page->nextCursor;
                } while (null !== $cursor);
            } finally {
                $client->disconnect();
            }

            $connection->markConnected($tools);
        } catch (AuthorizationPendingException $e) {
            $connection->markNeedsAuthorization();
            $this->entityManager->flush();

            throw $e;
        } catch (McpException|\RuntimeException $e) {
            $connection->markFailed(self::describe($e));
        }

        $this->entityManager->flush();
    }

    private static function describe(\Throwable $e): string
    {
        $message = $e->getMessage();
        for ($previous = $e->getPrevious(); null !== $previous; $previous = $previous->getPrevious()) {
            $message .= ' — '.$previous->getMessage();
        }

        return $message;
    }
}
