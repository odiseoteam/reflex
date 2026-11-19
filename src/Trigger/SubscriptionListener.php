<?php

declare(strict_types=1);

namespace App\Trigger;

use App\Engine\EventIntake;
use App\Entity\Connection;
use App\Entity\Reflex;
use App\Mcp\McpClientFactory;
use App\Repository\ConnectionRepository;
use App\Repository\ReflexRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client\CancellationTokenInterface;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\SubscriptionFilter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns what an MCP server notifies on a `subscriptions/listen` stream into events of the
 * reflexes subscribed to it.
 */
final readonly class SubscriptionListener
{
    public const string SOURCE = 'mcp';
    private const int MAX_CONTENT = 10_000;

    public function __construct(
        private ReflexRepository $reflexes,
        private ConnectionRepository $connections,
        private McpClientFactory $clients,
        private EventIntake $intake,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * What to listen for on each connection, from the enabled subscription reflexes.
     *
     * @return array<string, SubscriptionFilter> keyed by connection id
     */
    public function plan(): array
    {
        $uris = [];
        $listChanged = [];
        foreach ($this->subscribed() as $trigger) {
            $uris[$trigger->connectionId] = [...$uris[$trigger->connectionId] ?? [], ...$trigger->resourceUris];
            $listChanged[$trigger->connectionId] = ($listChanged[$trigger->connectionId] ?? false) || $trigger->listChanged;
        }

        $plan = [];
        foreach ($uris as $connectionId => $connectionUris) {
            $connectionUris = array_values(array_unique($connectionUris));
            sort($connectionUris);
            $plan[$connectionId] = new SubscriptionFilter(resourcesListChanged: $listChanged[$connectionId], resourceSubscriptions: $connectionUris);
        }

        return $plan;
    }

    /**
     * Listens until the server ends the subscription or $stop asks to.
     *
     * @param \Closure(SubscriptionFilter): void|null $onAcknowledged
     */
    public function listen(Connection $connection, SubscriptionFilter $filter, CancellationTokenInterface $stop, ?\Closure $onAcknowledged = null): void
    {
        $client = $this->clients->connect($connection);
        try {
            $client->listen(
                $filter,
                function (Notification $notification) use ($connection): void {
                    $this->dispatch($connection, $notification);
                },
                $onAcknowledged,
                $stop,
            );
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Starts a run of each reflex the notification concerns.
     *
     * @return int runs started
     */
    public function dispatch(Connection $connection, Notification $notification): int
    {
        [$type, $uri] = match (true) {
            $notification instanceof ResourceUpdatedNotification => ['resource.updated', $notification->uri],
            $notification instanceof ResourceListChangedNotification => ['resources.list_changed', null],
            default => [null, null],
        };
        if (null === $type) {
            return 0;
        }

        $this->entityManager->clear();
        $connection = $this->connections->find($connection->getId()) ?? $connection;

        $payload = ['connection' => $connection->getName(), 'uri' => $uri];
        if (null !== $uri) {
            $payload['contents'] = $this->read($connection, $uri);
        }

        // One notification is one event; every subscribed reflex gets its own run of it.
        $externalId = Uuid::v7()->toRfc4122();
        $started = 0;
        foreach ($this->reflexes->findEnabledBySource(Reflex::SUBSCRIPTION_SOURCE) as $reflex) {
            $trigger = $reflex->getSubscriptionTrigger();
            if (null === $trigger || $trigger->connectionId !== $connection->getId()->toRfc4122()) {
                continue;
            }
            if (null === $uri ? !$trigger->listChanged : !$trigger->watches($uri)) {
                continue;
            }

            if (null !== $this->intake->receiveFor($reflex, self::SOURCE, $externalId.'-'.$reflex->getId()->toRfc4122(), $type, $payload)) {
                ++$started;
            }
        }

        $this->logger->info('{type} on {connection} started {runs} runs.', ['type' => $type, 'connection' => $connection->getName(), 'runs' => $started, 'uri' => $uri]);

        return $started;
    }

    /**
     * The resource as it is now, so the agent sees what changed. The listening connection
     * is busy with the stream, so this reads over another one.
     *
     * @return list<array{mimeType: string|null, text: string}>|string text contents, or why they couldn't be read
     */
    private function read(Connection $connection, string $uri): array|string
    {
        try {
            $client = $this->clients->connect($connection);
            try {
                $result = $client->readResource($uri);
            } finally {
                $client->disconnect();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read {uri} after its update.', ['uri' => $uri, 'exception' => $e]);

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

    /**
     * @return iterable<SubscriptionTrigger>
     */
    private function subscribed(): iterable
    {
        foreach ($this->reflexes->findEnabledBySource(Reflex::SUBSCRIPTION_SOURCE) as $reflex) {
            $trigger = $reflex->getSubscriptionTrigger();
            if (null !== $trigger && ([] !== $trigger->resourceUris || $trigger->listChanged)) {
                yield $trigger;
            }
        }
    }
}
