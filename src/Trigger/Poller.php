<?php

declare(strict_types=1);

namespace App\Trigger;

use App\Engine\EventIntake;
use App\Entity\Connection;
use App\Entity\ConnectionStatus;
use App\Entity\Reflex;
use App\Entity\ToolPolicy;
use App\Mcp\McpClientFactory;
use App\Message\PollReflex;
use App\Repository\ReflexRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Calls a reflex's poll tool and fires on what is new. The first call only records a
 * baseline, so existing items don't trigger anything. The tool must be automatic in the
 * reflex's policy, since nobody approves a poll.
 */
final readonly class Poller
{
    public function __construct(
        private ReflexRepository $reflexes,
        private McpClientFactory $clientFactory,
        private EventIntake $intake,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Queues a poll for every reflex whose interval has passed.
     */
    public function queueDue(): int
    {
        $now = $this->clock->now();
        $queued = 0;
        foreach ($this->reflexes->findEnabledBySource(Reflex::POLL_SOURCE) as $reflex) {
            $poll = $reflex->getPollTrigger();
            if (null !== $poll && $reflex->isPollDue($now) && $this->reflexes->claimPoll($reflex, $now, $poll->intervalMinutes)) {
                $this->bus->dispatch(new PollReflex($reflex->getId()->toRfc4122()));
                ++$queued;
            }
        }

        return $queued;
    }

    /**
     * @return bool whether a run was queued
     */
    public function poll(Reflex $reflex): bool
    {
        $poll = $reflex->getPollTrigger();
        $connection = null === $poll ? null : $this->connection($reflex, $poll);
        if (null === $poll || null === $connection) {
            return false;
        }

        $toolName = $connection->getSlug().'_'.$poll->tool;
        if (ToolPolicy::Auto !== $reflex->policyFor($toolName, self::annotations($connection, $poll->tool))) {
            $this->logger->warning('Poll of {reflex} skipped: {tool} is not automatic in its policy.', ['reflex' => $reflex->getName(), 'tool' => $toolName]);

            return false;
        }

        try {
            $client = $this->clientFactory->connect($connection);
            try {
                $result = $client->callTool($poll->tool, $poll->arguments);
            } finally {
                $client->disconnect();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Poll of {reflex} failed.', ['reflex' => $reflex->getName(), 'exception' => $e]);

            return false;
        }

        if ($result->isError) {
            $this->logger->warning('Poll of {reflex} returned an error.', ['reflex' => $reflex->getName()]);

            return false;
        }

        return $this->compare($reflex, $poll, self::data($result));
    }

    /**
     * Fires on items whose key wasn't seen, or on any change of a result that isn't a list.
     */
    public function compare(Reflex $reflex, PollTrigger $poll, mixed $data): bool
    {
        $state = $reflex->getPollState();
        $itemKey = $poll->itemKey;
        $items = null === $itemKey ? null : self::items($data);

        if (null !== $items) {
            $keyed = [];
            foreach ($items as $item) {
                $key = \is_array($item) ? ($item[$itemKey] ?? null) : null;
                if (\is_scalar($key)) {
                    $keyed[(string) $key] = $item;
                }
            }

            $seen = $state['keys'] ?? null;
            $new = null === $seen ? [] : array_diff_key($keyed, array_flip($seen));
            $reflex->setPollState(['keys' => array_values(array_unique([...$seen ?? [], ...array_map('strval', array_keys($keyed))]))]);
            $this->entityManager->flush();

            if ([] === $new) {
                return false;
            }

            return $this->fire($reflex, 'items.new', hash('sha256', implode("\n", array_keys($new))), ['tool' => $poll->tool, 'items' => array_values($new)]);
        }

        $hash = hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR));
        $previous = $state['hash'] ?? null;
        $reflex->setPollState(['hash' => $hash]);
        $this->entityManager->flush();

        if (null === $previous || $previous === $hash) {
            return false;
        }

        return $this->fire($reflex, 'result.changed', $hash.'@'.$this->clock->now()->format('U'), ['tool' => $poll->tool, 'result' => $data]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fire(Reflex $reflex, string $type, string $key, array $payload): bool
    {
        return null !== $this->intake->receiveFor($reflex, Reflex::POLL_SOURCE, $reflex->getId()->toRfc4122().'@'.$key, $type, $payload);
    }

    private function connection(Reflex $reflex, PollTrigger $poll): ?Connection
    {
        foreach ($reflex->getConnections() as $connection) {
            if ($connection->getId()->toRfc4122() === $poll->connectionId && ConnectionStatus::Connected === $connection->getStatus()) {
                return $connection;
            }
        }

        $this->logger->warning('Poll of {reflex} skipped: its connection is not connected.', ['reflex' => $reflex->getName()]);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function annotations(Connection $connection, string $tool): array
    {
        foreach ($connection->getTools() as $entry) {
            if (($entry['name'] ?? null) === $tool && \is_array($entry['annotations'] ?? null)) {
                /** @var array<string, mixed> $annotations */
                $annotations = $entry['annotations'];

                return $annotations;
            }
        }

        return [];
    }

    private static function data(CallToolResult $result): mixed
    {
        if (null !== $result->structuredContent) {
            return $result->structuredContent;
        }

        $text = implode("\n", array_map(
            static fn ($content): string => $content instanceof TextContent && \is_string($content->text) ? $content->text : '',
            $result->content,
        ));

        try {
            return json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }
    }

    /**
     * The list in a result: the result itself, or its only list property, e.g. {"issues": [...]}.
     *
     * @return list<mixed>|null
     */
    private static function items(mixed $data): ?array
    {
        if (!\is_array($data)) {
            return null;
        }
        if (array_is_list($data)) {
            return $data;
        }

        $lists = array_filter($data, static fn ($value): bool => \is_array($value) && array_is_list($value));

        return 1 === \count($lists) ? array_values($lists)[0] : null;
    }
}
