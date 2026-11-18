<?php

declare(strict_types=1);

namespace App\Entity;

use App\Mcp\Elicitation\Question;
use App\Repository\DecisionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A tool call waiting on people: one the agent proposed, or one a server paused to ask
 * something. What runs is exactly this tool with these arguments, on this connection;
 * the model is not asked again.
 *
 * Its status is the marking of the "decision" workflow.
 */
#[ORM\Entity(repositoryClass: DecisionRepository::class)]
#[ORM\Index(name: 'decision_status_idx', columns: ['status', 'created_at'])]
class Decision
{
    public const string PROPOSED = 'proposed';
    public const string ASKED = 'asked';
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

    /** @var array{key: string, message: string, schema: array<string, mixed>}|null open question from the server */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $question = null;

    /** @var array<string, array<string, mixed>> question key => answer, replayed when the call repeats */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $answers = [];

    /** Who last answered or declined the server's question. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $respondent = null;

    /** @var Collection<int, Vote> */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'decision')]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending])]
    private Collection $votes;

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
        #[ORM\Column(length: 20)]
        private DecisionKind $kind = DecisionKind::Proposal,
        /** Approvals it needs before it runs. */
        #[ORM\Column]
        private int $requiredApprovals = 1,
        ?Question $question = null,
    ) {
        $this->id = Uuid::v7();
        $this->segment = $run->getSegment();
        $this->createdAt = new \DateTimeImmutable();
        $this->votes = new ArrayCollection();
        if (null !== $question) {
            $this->question = $question->toArray();
            $this->status = self::ASKED;
        }
    }

    public function getKind(): DecisionKind
    {
        return $this->kind;
    }

    public function getRequiredApprovals(): int
    {
        return $this->requiredApprovals;
    }

    /**
     * @return Collection<int, Vote>
     */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function addVote(Vote $vote): void
    {
        $this->votes->add($vote);
    }

    public function countApprovals(): int
    {
        return $this->votes->filter(static fn (Vote $vote): bool => $vote->isApprove())->count();
    }

    public function hasVoted(User $user): bool
    {
        return $this->votes->exists(static fn (int $key, Vote $vote): bool => $vote->getUser()->getId()->equals($user->getId()));
    }

    public function getRespondent(): ?User
    {
        return $this->respondent;
    }

    public function setRespondent(?User $respondent): void
    {
        $this->respondent = $respondent;
    }

    public function getQuestion(): ?Question
    {
        return null === $this->question ? null : Question::fromArray($this->question);
    }

    /**
     * The server paused the call to ask this; it is repeated once answered.
     */
    public function ask(Question $question): void
    {
        $this->question = $question->toArray();
        $this->executionStartedAt = null;
    }

    /**
     * @param array<string, mixed> $content
     */
    public function answer(array $content): void
    {
        if (null === $this->question) {
            throw new \LogicException('There is no open question.');
        }

        $this->answers[$this->question['key']] = $content;
        $this->question = null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAnswers(): array
    {
        return $this->answers;
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

    /**
     * Whether it waits on a person.
     */
    public function isPending(): bool
    {
        return \in_array($this->status, [self::PROPOSED, self::ASKED], true);
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
