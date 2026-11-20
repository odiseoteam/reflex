<?php

declare(strict_types=1);

namespace App\Trigger;

use App\Engine\EventIntake;
use App\Entity\Connection;
use App\Entity\Reflex;
use App\Mcp\McpClientFactory;
use App\Mcp\ResourceReaderInterface;
use App\Repository\ConnectionRepository;
use App\Repository\ReflexRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client\CancellationTokenInterface;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\SubscriptionFilter;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns what an MCP server notifies on a `subscriptions/listen` stream into events of the
 * reflexes subscribed to it.
 *
 * A stream only carries what happens while it is open, and servers end streams after a
 * while. So the listener remembers each watched resource as it last saw it, and when a new
 * stream opens it reads them again: one that changed in between fires as if notified.
 */
final readonly class SubscriptionListener
{
    public const string SOURCE = 'mcp';

    public function __construct(
        private ReflexRepository $reflexes,
        private ConnectionRepository $connections,
        private McpClientFactory $clients,
        private ResourceReaderInterface $resources,
        private EventIntake $intake,
        private EntityManagerInterface $entityManager,
        private CacheItemPoolInterface $cache,
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
     * Listens until the server ends the subscription or $stop asks to, catching up first on
     * what changed while no stream was open.
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
                function (SubscriptionFilter $agreed) use ($connection, $onAcknowledged): void {
                    if (null !== $onAcknowledged) {
                        $onAcknowledged($agreed);
                    }
                    $this->catchUp($connection, $agreed);
                },
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

        $connection = $this->fresh($connection);
        $contents = null;
        if (null !== $uri) {
            $contents = $this->resources->read($connection, $uri);
            if (\is_array($contents)) {
                $this->remember($connection, $uri, $contents);
            }
        }

        return $this->fire($connection, $type, $uri, $contents);
    }

    /**
     * Reads the watched resources again and fires for each that differs from how it was
     * last seen. The first time, it only takes them as they are.
     *
     * @return int runs started
     */
    public function catchUp(Connection $connection, SubscriptionFilter $agreed): int
    {
        $connection = $this->fresh($connection);
        $started = 0;
        foreach ($agreed->resourceSubscriptions as $uri) {
            $contents = $this->resources->read($connection, $uri);
            if (\is_array($contents) && $this->remember($connection, $uri, $contents)) {
                $this->logger->info('{uri} on {connection} changed while no stream was open.', ['uri' => $uri, 'connection' => $connection->getName()]);
                $started += $this->fire($connection, 'resource.updated', $uri, $contents);
            }
        }

        return $started;
    }

    /**
     * @param list<array{mimeType: string|null, text: string}>|string|null $contents
     */
    private function fire(Connection $connection, string $type, ?string $uri, array|string|null $contents): int
    {
        $payload = ['connection' => $connection->getName(), 'uri' => $uri];
        if (null !== $uri) {
            $payload['contents'] = $contents;
        }

        // One change is one event; every subscribed reflex gets its own run of it.
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
     * Keeps a fingerprint of the resource as seen now.
     *
     * @param list<array{mimeType: string|null, text: string}> $contents
     *
     * @return bool whether it differs from the one seen before
     */
    private function remember(Connection $connection, string $uri, array $contents): bool
    {
        $item = $this->cache->getItem('reflex.subscription.'.hash('xxh128', $connection->getId()->toRfc4122().'|'.$uri));
        $previous = $item->isHit() ? $item->get() : null;
        $seen = hash('sha256', json_encode($contents, \JSON_THROW_ON_ERROR));
        $this->cache->save($item->set($seen));

        return \is_string($previous) && $previous !== $seen;
    }

    private function fresh(Connection $connection): Connection
    {
        $this->entityManager->clear();

        return $this->connections->find($connection->getId()) ?? $connection;
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
