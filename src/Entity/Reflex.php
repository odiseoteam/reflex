<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReflexRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An instruction an agent runs on its own when a trigger fires, within a per-tool policy.
 */
#[ORM\Entity(repositoryClass: ReflexRepository::class)]
#[ORM\Index(name: 'reflex_trigger_idx', columns: ['trigger_source', 'trigger_event'])]
class Reflex
{
    public const string DEFAULT_MODEL = 'claude-sonnet-5-5';
    public const string ANY_EVENT = '*';
    public const int DEFAULT_MAX_TOOL_CALLS = 10;
    public const int DEFAULT_TIME_LIMIT = 120;
    /** Consecutive failed runs that disable a reflex. */
    public const int FAILURE_THRESHOLD = 3;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private bool $enabled = true;

    /** @var Collection<int, Connection> */
    #[ORM\ManyToMany(targetEntity: Connection::class)]
    #[ORM\JoinTable(name: 'reflex_connection')]
    #[ORM\OrderBy(['name' => \SortDirection::Ascending])]
    private Collection $connections;

    /** @var array<string, string> tool name => ToolPolicy value */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $toolPolicies = [];

    /** Tokens it may spend per UTC day; null for no limit. */
    #[ORM\Column(nullable: true)]
    private ?int $dailyTokenBudget = null;

    #[ORM\Column]
    private int $maxToolCalls = self::DEFAULT_MAX_TOOL_CALLS;

    #[ORM\Column]
    private int $timeLimitSeconds = self::DEFAULT_TIME_LIMIT;

    #[ORM\Column]
    private int $consecutiveFailures = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $disabledReason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $name,
        /** Webhook type that triggers it, e.g. "generic". */
        #[ORM\Column(length: 40)]
        private string $triggerSource,
        /** Event type within the source, or "*" for any. */
        #[ORM\Column(length: 100)]
        private string $triggerEvent,
        #[ORM\Column(type: Types::TEXT)]
        private string $instructions,
        #[ORM\Column(length: 100)]
        private string $model = self::DEFAULT_MODEL,
    ) {
        $this->id = Uuid::v7();
        $this->connections = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTriggerSource(): string
    {
        return $this->triggerSource;
    }

    public function getTriggerEvent(): string
    {
        return $this->triggerEvent;
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @return Collection<int, Connection>
     */
    public function getConnections(): Collection
    {
        return $this->connections;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDailyTokenBudget(): ?int
    {
        return $this->dailyTokenBudget;
    }

    public function getMaxToolCalls(): int
    {
        return $this->maxToolCalls;
    }

    public function getTimeLimitSeconds(): int
    {
        return $this->timeLimitSeconds;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getDisabledReason(): ?string
    {
        return $this->disabledReason;
    }

    public function setLimits(?int $dailyTokenBudget, int $maxToolCalls, int $timeLimitSeconds): void
    {
        $this->dailyTokenBudget = $dailyTokenBudget;
        $this->maxToolCalls = $maxToolCalls;
        $this->timeLimitSeconds = $timeLimitSeconds;
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
    }

    /**
     * @return bool whether this failure disabled the reflex
     */
    public function recordFailure(): bool
    {
        if (++$this->consecutiveFailures < self::FAILURE_THRESHOLD || !$this->enabled) {
            return false;
        }

        $this->enabled = false;
        $this->disabledReason = \sprintf('Disabled after %d failed runs in a row.', $this->consecutiveFailures);

        return true;
    }

    /**
     * @param iterable<Connection> $connections
     */
    public function update(string $name, string $triggerSource, string $triggerEvent, string $instructions, string $model, bool $enabled, iterable $connections): void
    {
        $this->name = $name;
        $this->triggerSource = $triggerSource;
        $this->triggerEvent = $triggerEvent;
        $this->instructions = $instructions;
        $this->model = $model;
        if ($enabled && !$this->enabled) {
            $this->consecutiveFailures = 0;
            $this->disabledReason = null;
        }
        $this->enabled = $enabled;
        $this->connections->clear();
        foreach ($connections as $connection) {
            $this->connections->add($connection);
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $annotations
     */
    public function policyFor(string $toolName, array $annotations = []): ToolPolicy
    {
        $configured = $this->toolPolicies[$toolName] ?? null;

        return null === $configured ? ToolPolicy::default($annotations) : ToolPolicy::from($configured);
    }

    /**
     * @return array<string, string>
     */
    public function getToolPolicies(): array
    {
        return $this->toolPolicies;
    }

    /**
     * @param array<string, ToolPolicy> $policies
     */
    public function setToolPolicies(array $policies): void
    {
        $this->toolPolicies = array_map(static fn (ToolPolicy $policy): string => $policy->value, $policies);
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * What a run records, so later edits don't rewrite history.
     *
     * @return array{name: string, instructions: string, model: string, toolPolicies: array<string, string>, updatedAt: string}
     */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'instructions' => $this->instructions,
            'model' => $this->model,
            'toolPolicies' => $this->toolPolicies,
            'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
        ];
    }
}
