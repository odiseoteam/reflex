<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An app allowed to ask people for access: registered on the fly (RFC 7591) or known by
 * the URL of its metadata document.
 */
#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[ORM\Table(name: 'oauth_client')]
class OAuthClient
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $refreshedAt;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        #[ORM\Column(length: 2048, unique: true)]
        private string $clientId,
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $redirectUris,
        /** Its client id is the URL of a metadata document it publishes. */
        #[ORM\Column]
        private bool $metadataDocument = false,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->refreshedAt = $this->createdAt;
    }

    /**
     * @param list<string> $redirectUris
     */
    public function refresh(string $name, array $redirectUris): void
    {
        $this->name = $name;
        $this->redirectUris = $redirectUris;
        $this->refreshedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    public function allowsRedirect(string $uri): bool
    {
        return \in_array($uri, $this->redirectUris, true);
    }

    public function isMetadataDocument(): bool
    {
        return $this->metadataDocument;
    }

    public function getRefreshedAt(): \DateTimeImmutable
    {
        return $this->refreshedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
