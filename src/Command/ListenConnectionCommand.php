<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ConnectionRepository;
use App\Trigger\StopAfter;
use App\Trigger\SubscriptionListener;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client\Exception\RequestCancelledException;
use Mcp\Client\Exception\RuntimeException as McpRuntimeException;
use Mcp\Schema\SubscriptionFilter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps one connection's subscription stream open, reconnecting when the server ends it.
 * Started by reflex:listen.
 */
#[AsCommand(name: 'reflex:listen:connection', description: 'Listens to one connection for the resource changes its reflexes subscribe to.')]
final readonly class ListenConnectionCommand
{
    /** Seconds between re-reading what to listen for. */
    private const int REPLAN_EVERY = 60;
    private const int MAX_BACKOFF = 60;
    /** A server without subscriptions/listen is asked again this rarely. */
    private const int UNSUPPORTED_RETRY = 300;

    public function __construct(
        private SubscriptionListener $listener,
        private ConnectionRepository $connections,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        #[Argument('Id of the connection.')] string $connection,
        #[Option('Seconds to run before exiting, to be started again fresh.')] int $timeLimit = 3600,
    ): int {
        $id = $connection;
        $until = time() + $timeLimit;
        $backoff = 1;

        while (time() < $until) {
            $this->entityManager->clear();
            $filter = $this->listener->plan()[$id] ?? null;
            $found = Uuid::isValid($id) ? $this->connections->find(Uuid::fromString($id)) : null;
            if (null === $filter || null === $found) {
                $this->logger->info('Nothing to listen for on connection {id} any more.', ['id' => $id]);

                return Command::SUCCESS;
            }

            try {
                $this->listener->listen($found, $filter, new StopAfter(min(self::REPLAN_EVERY, max(1, $until - time()))), function (SubscriptionFilter $agreed) use ($found): void {
                    $this->logger->info('Listening to {connection} for {uris} resources{lists}.', [
                        'connection' => $found->getName(),
                        'uris' => \count($agreed->resourceSubscriptions),
                        'lists' => $agreed->resourcesListChanged ? ' and list changes' : '',
                    ]);
                });
                $backoff = 1;
            } catch (RequestCancelledException) {
                $backoff = 1;
            } catch (McpRuntimeException $e) {
                $this->logger->error('{connection} can\'t be listened to: {message}', ['connection' => $found->getName(), 'message' => $e->getMessage()]);
                sleep(self::UNSUPPORTED_RETRY);
            } catch (\Throwable $e) {
                $this->logger->warning('Listening to {connection} failed, retrying in {seconds}s: {message}', ['connection' => $found->getName(), 'seconds' => $backoff, 'message' => $e->getMessage(), 'exception' => $e]);
                sleep($backoff);
                $backoff = min(self::MAX_BACKOFF, $backoff * 2);
            }
        }

        return Command::SUCCESS;
    }
}
