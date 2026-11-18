<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A persisted wait: for decisions, it resumes its run once they resolve; for an absence,
 * it starts a run when its deadline passes, unless the awaited event cancels it first.
 */
#[ORM\Entity]
#[ORM\Index(name: 'waitpoint_due_idx', columns: ['kind', 'resolved_at', 'due_at'])]
#[ORM\Index(name: 'waitpoint_correlation_idx', columns: ['reflex_id', 'correlation'])]
class Waitpoint
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** The awaited event came, so nothing runs. */
    #[ORM\Column]
    private bool $cancelled = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Reflex $reflex = null;

    /** Event X, for an absence. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?IncomingEvent $event = null;

    /** Value X and Y must share, e.g. a customer's email. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $correlation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?Run $run,
        #[ORM\Column(enumType: WaitpointKind::class, length: 20)]
        private WaitpointKind $kind,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public static function forAbsence(Reflex $reflex, IncomingEvent $event, string $correlation, \DateTimeImmutable $dueAt): self
    {
        $waitpoint = new self(null, WaitpointKind::Absence);
        $waitpoint->reflex = $reflex;
        $waitpoint->event = $event;
        $waitpoint->correlation = $correlation;
        $waitpoint->dueAt = $dueAt;

        return $waitpoint;
    }

    public function getRun(): Run
    {
        return $this->run ?? throw new \LogicException('This waitpoint does not belong to a run.');
    }

    public function getReflex(): ?Reflex
    {
        return $this->reflex;
    }

    public function getEvent(): ?IncomingEvent
    {
        return $this->event;
    }

    public function getCorrelation(): ?string
    {
        return $this->correlation;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function cancel(): void
    {
        if (null === $this->resolvedAt) {
            $this->cancelled = true;
            $this->resolvedAt = new \DateTimeImmutable();
        }
    }

    public function getKind(): WaitpointKind
    {
        return $this->kind;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isResolved(): bool
    {
        return null !== $this->resolvedAt;
    }

    public function resolve(): void
    {
        $this->resolvedAt ??= new \DateTimeImmutable();
    }
}
