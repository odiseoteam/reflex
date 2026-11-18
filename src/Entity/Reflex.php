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
    /** Trigger source of reflexes that run on a cron schedule. */
    public const string SCHEDULE_SOURCE = 'schedule';
    public const int DEFAULT_MAX_TOOL_CALLS = 10;
    public const int DEFAULT_TIME_LIMIT = 120;
    /** Consecutive failed runs that disable a reflex. */
    public const int FAILURE_THRESHOLD = 3;
    public const int MAX_APPROVALS = 5;

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

    /** Cron expression in UTC, for scheduled reflexes. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cron = null;

    /** Slot the schedule last fired for. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFiredAt = null;

    /** @var array<string, int> tool name => approvals it needs, when more than one */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $requiredApprovals = [];

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

    public function isScheduled(): bool
    {
        return self::SCHEDULE_SOURCE === $this->triggerSource && null !== $this->cron;
    }

    public function getCron(): ?string
    {
        return $this->cron;
    }

    public function setCron(?string $cron): void
    {
        if ($cron !== $this->cron) {
            $this->cron = $cron;
            $this->lastFiredAt = null;
        }
    }

    public function getLastFiredAt(): ?\DateTimeImmutable
    {
        return $this->lastFiredAt;
    }

    /**
     * The latest slot due at $now, once: null if it already fired or the reflex
     * was created after it. Missed slots collapse into the latest one.
     */
    public function claimDueSlot(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (!$this->isScheduled() || !$this->enabled) {
            return null;
        }

        $slot = \DateTimeImmutable::createFromInterface((new \Cron\CronExpression((string) $this->cron))->getPreviousRunDate($now, 0, true, 'UTC'));
        $since = $this->lastFiredAt ?? $this->createdAt;
        if ($slot <= $since) {
            return null;
        }

        $this->lastFiredAt = $slot;

        return $slot;
    }

    public function nextRunAt(\DateTimeInterface $now): ?\DateTimeImmutable
    {
        return $this->isScheduled() ? \DateTimeImmutable::createFromInterface((new \Cron\CronExpression((string) $this->cron))->getNextRunDate($now, 0, false, 'UTC')) : null;
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

    public function approvalsFor(string $toolName): int
    {
        return $this->requiredApprovals[$toolName] ?? 1;
    }

    /**
     * @return array<string, int>
     */
    public function getRequiredApprovals(): array
    {
        return $this->requiredApprovals;
    }

    /**
     * @param array<string, ToolPolicy> $policies
     * @param array<string, int>        $approvals tool name => approvals it needs; 1 when missing
     */
    public function setToolPolicies(array $policies, array $approvals = []): void
    {
        $this->toolPolicies = array_map(static fn (ToolPolicy $policy): string => $policy->value, $policies);
        $this->requiredApprovals = array_filter($approvals, static fn (int $count): bool => $count > 1);
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * What a run records, so later edits don't rewrite history.
     *
     * @return array{name: string, instructions: string, model: string, toolPolicies: array<string, string>, requiredApprovals: array<string, int>, updatedAt: string}
     */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'instructions' => $this->instructions,
            'model' => $this->model,
            'toolPolicies' => $this->toolPolicies,
            'requiredApprovals' => $this->requiredApprovals,
            'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
        ];
    }
}
