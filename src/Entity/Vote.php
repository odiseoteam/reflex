<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One person's verdict on a decision.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'vote_decision_user_uniq', columns: ['decision_id', 'user_id'])]
class Vote
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'votes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Decision $decision,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column]
        private bool $approve,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $note = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getDecision(): Decision
    {
        return $this->decision;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isApprove(): bool
    {
        return $this->approve;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
