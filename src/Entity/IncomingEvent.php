<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An event received from outside. Its payload is untrusted data, never instructions.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'incoming_event_external_idx', columns: ['source', 'external_id'])]
class IncomingEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    /**
     * @param array<mixed> $payload
     */
    public function __construct(
        #[ORM\Column(length: 40)]
        private string $source,
        /** Id given by the sender, used to drop redeliveries. */
        #[ORM\Column(length: 255)]
        private string $externalId,
        #[ORM\Column(length: 100)]
        private string $type,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $payload,
    ) {
        $this->id = Uuid::v7();
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
