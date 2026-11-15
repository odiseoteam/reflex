<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'run_step_position_idx', columns: ['run_id', 'position'])]
class RunStep
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'steps')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Run $run,
        #[ORM\Column]
        private int $position,
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
}
