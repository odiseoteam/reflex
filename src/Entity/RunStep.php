<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A tool call of a run, stored before it executes so a resumed run never repeats an effect.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'run_step_position_idx', columns: ['run_id', 'position'])]
#[ORM\UniqueConstraint(name: 'run_step_idempotency_idx', columns: ['idempotency_key'])]
class RunStep
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Null while the call is in flight; a resumed run finds it that way after a crash. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'steps')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Run $run,
        #[ORM\Column]
        private int $position,
        /** Run, call ordinal, tool and arguments: the same call in a resumed attempt gets the same key. */
        #[ORM\Column(length: 64)]
        private string $idempotencyKey,
        #[ORM\Column(enumType: RunStepKind::class, length: 20)]
        private RunStepKind $kind,
        #[ORM\Column(length: 255)]
        private string $toolName,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $arguments,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $result = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        if (RunStepKind::ToolCall !== $kind) {
            $this->completedAt = $this->createdAt;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getKind(): RunStepKind
    {
        return $this->kind;
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

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isCompleted(): bool
    {
        return null !== $this->completedAt;
    }

    public function complete(string $result): void
    {
        $this->result = $result;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->kind = RunStepKind::Error;
        $this->complete($error);
    }
}
