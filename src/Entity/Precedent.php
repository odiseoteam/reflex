<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrecedentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\AI\Platform\Vector\VectorInterface;
use Symfony\AI\Store\Bridge\Doctrine\Dbal\Types\VectorType;
use Symfony\AI\Store\Bridge\Doctrine\IndexableEntityInterface;
use Symfony\AI\Store\Bridge\Doctrine\Mapping\Embedding;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What people decided on a proposal or a server question, kept past the run's retention
 * so later runs of the reflex can follow it.
 */
#[ORM\Entity(repositoryClass: PrecedentRepository::class)]
class Precedent implements IndexableEntityInterface
{
    /** Longest payload excerpt kept in the situation. */
    private const int EVENT_EXCERPT = 2000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Event and proposal in words, what the embedding is built from. */
    #[ORM\Column(type: Types::TEXT)]
    private string $situation;

    #[ORM\Column(type: VectorType::NAME, nullable: true)]
    #[Embedding(from: ['situation'])]
    private ?VectorInterface $embedding = null;

    #[ORM\Column]
    private \DateTimeImmutable $decidedAt;

    /**
     * @param array<string, mixed>      $arguments
     * @param array<string, mixed>|null $answer    what people answered a question with
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Reflex $reflex,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Decision $decision,
        #[ORM\Column(length: 20)]
        private DecisionKind $kind,
        #[ORM\Column(length: 20)]
        private Verdict $verdict,
        #[ORM\Column(length: 255)]
        private string $toolName,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $arguments,
        #[ORM\Column(type: Types::TEXT)]
        private string $reason,
        #[ORM\Column(length: 255)]
        private string $decidedBy,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $note = null,
        #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
        private ?array $answer = null,
        ?IncomingEvent $event = null,
        ?string $question = null,
    ) {
        $this->id = Uuid::v7();
        $this->decidedAt = new \DateTimeImmutable();
        $this->situation = self::situation($event, $toolName, $arguments, $reason, $question);
    }

    /**
     * The same words for a stored precedent and a new case, so similar cases land close.
     *
     * @param array<string, mixed> $arguments
     */
    public static function situation(?IncomingEvent $event, ?string $toolName = null, array $arguments = [], ?string $reason = null, ?string $question = null): string
    {
        $lines = [null === $event
            ? 'Event: none, run by hand.'
            : \sprintf('Event: %s from %s. %s', $event->getType(), $event->getSource(), mb_substr(json_encode($event->getPayload(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), 0, self::EVENT_EXCERPT))];

        if (null !== $toolName) {
            $lines[] = \sprintf('Call: %s %s', $toolName, json_encode($arguments, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        }
        if (null !== $reason && '' !== $reason) {
            $lines[] = 'Reason: '.$reason;
        }
        if (null !== $question) {
            $lines[] = 'Question: '.$question;
        }

        return implode("\n", $lines);
    }

    /**
     * The e5 models expect a "query: " prefix on both sides of a similarity search.
     */
    public static function embeddable(string $situation): string
    {
        return 'query: '.$situation;
    }

    public function getIndexableContent(): string
    {
        return self::embeddable($this->situation);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReflex(): Reflex
    {
        return $this->reflex;
    }

    public function getDecision(): ?Decision
    {
        return $this->decision;
    }

    public function getKind(): DecisionKind
    {
        return $this->kind;
    }

    public function getVerdict(): Verdict
    {
        return $this->verdict;
    }

    public function getToolName(): string
    {
        return $this->toolName;
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

    public function getDecidedBy(): string
    {
        return $this->decidedBy;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAnswer(): ?array
    {
        return $this->answer;
    }

    public function getSituation(): string
    {
        return $this->situation;
    }

    public function hasEmbedding(): bool
    {
        return null !== $this->embedding;
    }

    public function getDecidedAt(): \DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
