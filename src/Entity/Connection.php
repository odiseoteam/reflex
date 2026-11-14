<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A remote MCP server and the tools it exposes.
 */
#[ORM\Entity(repositoryClass: ConnectionRepository::class)]
#[ORM\Table(name: 'connection')]
class Connection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Prefix of the tool names this connection gives to agents. */
    #[ORM\Column(length: 40, unique: true)]
    private string $slug;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(enumType: ConnectionAuth::class, length: 20)]
    private ConnectionAuth $auth;

    /** Pre-registered OAuth client, for servers without dynamic registration. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $oauthClientId = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $oauthIssuer = null;

    /** Bearer token or OAuth client secret, encrypted with SecretBox. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedSecret = null;

    #[ORM\Column(enumType: ConnectionStatus::class, length: 30)]
    private ConnectionStatus $status = ConnectionStatus::New;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $tools = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $toolsSyncedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $url, ConnectionAuth $auth)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->slug = self::slugify($name);
        $this->url = $url;
        $this->auth = $auth;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));

        return substr('' === $slug ? 'server' : $slug, 0, 40);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAuth(): ConnectionAuth
    {
        return $this->auth;
    }

    public function getOauthClientId(): ?string
    {
        return $this->oauthClientId;
    }

    public function getOauthIssuer(): ?string
    {
        return $this->oauthIssuer;
    }

    public function usePreRegisteredClient(string $clientId, string $issuer): void
    {
        $this->oauthClientId = $clientId;
        $this->oauthIssuer = $issuer;
    }

    public function getEncryptedSecret(): ?string
    {
        return $this->encryptedSecret;
    }

    public function setEncryptedSecret(?string $encryptedSecret): void
    {
        $this->encryptedSecret = $encryptedSecret;
    }

    public function getStatus(): ConnectionStatus
    {
        return $this->status;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function getToolsSyncedAt(): ?\DateTimeImmutable
    {
        return $this->toolsSyncedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param list<array<string, mixed>> $tools
     */
    public function markConnected(array $tools): void
    {
        $this->status = ConnectionStatus::Connected;
        $this->tools = $tools;
        $this->toolsSyncedAt = new \DateTimeImmutable();
        $this->lastError = null;
    }

    public function markNeedsAuthorization(): void
    {
        $this->status = ConnectionStatus::NeedsAuthorization;
    }

    public function markFailed(string $error): void
    {
        $this->status = ConnectionStatus::Failed;
        $this->lastError = $error;
    }
}
