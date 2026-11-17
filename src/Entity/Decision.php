<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DecisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An action an agent proposed and a person decides on. What runs on approval is exactly
 * this tool with these arguments, on this connection; the model is not asked again.
 *
 * Its status is the marking of the "decision" workflow.
 */
#[ORM\Entity(repositoryClass: DecisionRepository::class)]
#[ORM\Index(name: 'decision_status_idx', columns: ['status', 'created_at'])]
class Decision
{
    public const string PROPOSED = 'proposed';
    public const string APPROVED = 'approved';
    public const string REJECTED = 'rejected';
    public const string EXECUTED = 'executed';
    public const string FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20)]
    private string $status = self::PROPOSED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $result = null;

    /** Segment of the run that proposed it. */
    #[ORM\Column]
    private int $segment;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Set before the call, so a redelivered execution never repeats it. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $executionStartedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $executedAt = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Run $run,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Waitpoint $waitpoint,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Connection $connection,
        /** Name the agent saw, e.g. "github_close_issue". */
        #[ORM\Column(length: 255)]
        private string $toolName,
        /** Name on the MCP server, e.g. "close_issue". */
        #[ORM\Column(length: 255)]
        private string $remoteToolName,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $arguments,
        /** Why the agent proposes it, in its own words. */
        #[ORM\Column(type: Types::TEXT)]
        private string $reason,
    ) {
        $this->id = Uuid::v7();
        $this->segment = $run->getSegment();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getSegment(): int
    {
        return $this->segment;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    public function getWaitpoint(): Waitpoint
    {
        return $this->waitpoint;
    }

    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getRemoteToolName(): string
    {
        return $this->remoteToolName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Used by the workflow's marking store.
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
        if (\in_array($status, [self::APPROVED, self::REJECTED], true)) {
            $this->decidedAt = new \DateTimeImmutable();
        }
        if (\in_array($status, [self::EXECUTED, self::FAILED], true)) {
            $this->executedAt = new \DateTimeImmutable();
        }
    }

    public function isPending(): bool
    {
        return self::PROPOSED === $this->status;
    }

    /**
     * Whether nothing else will happen to it.
     */
    public function isResolved(): bool
    {
        return \in_array($this->status, [self::REJECTED, self::EXECUTED, self::FAILED], true);
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): void
    {
        $this->result = $result;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getExecutedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function hasExecutionStarted(): bool
    {
        return null !== $this->executionStartedAt;
    }

    public function markExecutionStarted(): void
    {
        $this->executionStartedAt = new \DateTimeImmutable();
    }
}
