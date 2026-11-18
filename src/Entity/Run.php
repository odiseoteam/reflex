<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One execution of a reflex.
 */
#[ORM\Entity(repositoryClass: RunRepository::class)]
#[ORM\Index(name: 'run_reflex_created_idx', columns: ['reflex_id', 'created_at'])]
class Run
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(enumType: RunStatus::class, length: 20)]
    private RunStatus $status = RunStatus::Queued;

    /** @var array{name: string, instructions: string, model: string, toolPolicies: array<string, string>, requiredApprovals?: array<string, int>, updatedAt: string} the reflex as it was when the run was created */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $reflexSnapshot;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $output = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** How many times a worker started it; above one means it resumed after a crash. */
    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?int $tokensUsed = null;

    /** Stretch of the run between waits; part of the steps' idempotency keys. */
    #[ORM\Column]
    private int $segment = 1;

    /** @var list<string> ids of the precedents the agent was given */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $recalled = [];

    /** @var array<mixed>|null the agent conversation so far, normalized, kept while it waits */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $conversation = null;

    /** @var Collection<int, RunStep> */
    #[ORM\OneToMany(targetEntity: RunStep::class, mappedBy: 'run', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    private Collection $steps;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Reflex $reflex,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?IncomingEvent $event = null,
    ) {
        $this->id = Uuid::v7();
        $this->reflexSnapshot = $reflex->snapshot();
        $this->createdAt = new \DateTimeImmutable();
        $this->steps = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReflex(): Reflex
    {
        return $this->reflex;
    }

    public function getEvent(): ?IncomingEvent
    {
        return $this->event;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    /**
     * @return array{name: string, instructions: string, model: string, toolPolicies: array<string, string>, requiredApprovals?: array<string, int>, updatedAt: string}
     */
    public function getReflexSnapshot(): array
    {
        return $this->reflexSnapshot;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @return Collection<int, RunStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getTokensUsed(): ?int
    {
        return $this->tokensUsed;
    }

    public function recordTokens(?int $tokens): void
    {
        if (null !== $tokens) {
            $this->tokensUsed = ($this->tokensUsed ?? 0) + $tokens;
        }
    }

    public function getSegment(): int
    {
        return $this->segment;
    }

    /**
     * @return array<mixed>|null
     */
    public function getConversation(): ?array
    {
        return $this->conversation;
    }

    /**
     * @param list<string> $ids
     */
    public function recall(array $ids): void
    {
        $this->recalled = $ids;
    }

    /**
     * @return list<string>
     */
    public function getRecalled(): array
    {
        return $this->recalled;
    }

    /**
     * Pauses until its waitpoint resolves, keeping the conversation to continue it.
     *
     * @param array<mixed> $conversation
     */
    public function wait(string $output, array $conversation): void
    {
        $this->status = RunStatus::Waiting;
        $this->output = $output;
        $this->conversation = $conversation;
    }

    /**
     * Queues the next segment after its waitpoint resolved.
     */
    public function resume(): void
    {
        $this->status = RunStatus::Queued;
        ++$this->segment;
        $this->attempts = 0;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function addStep(string $idempotencyKey, RunStepKind $kind, string $toolName, array $arguments, ?string $result = null): RunStep
    {
        $step = new RunStep($this, $this->steps->count() + 1, $idempotencyKey, $kind, $toolName, $arguments, $result);
        $this->steps->add($step);

        return $step;
    }

    public function findStep(string $idempotencyKey): ?RunStep
    {
        foreach ($this->steps as $step) {
            if ($step->getIdempotencyKey() === $idempotencyKey) {
                return $step;
            }
        }

        return null;
    }

    public function start(): void
    {
        $this->status = RunStatus::Running;
        $this->startedAt ??= new \DateTimeImmutable();
        ++$this->attempts;
    }

    public function skip(string $reason): void
    {
        $this->status = RunStatus::Skipped;
        $this->error = $reason;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function succeed(string $output): void
    {
        $this->status = RunStatus::Succeeded;
        $this->output = $output;
        $this->conversation = null;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->status = RunStatus::Failed;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();
    }
}
