<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Access a person gave an app, from the authorization code to its rotating tokens.
 * Only hashes of the code and tokens are kept.
 */
#[ORM\Entity(repositoryClass: OAuthGrantRepository::class)]
#[ORM\Table(name: 'oauth_grant')]
class OAuthGrant
{
    public const int CODE_TTL = 60;
    public const int ACCESS_TTL = 3600;
    public const int REFRESH_TTL = 30 * 86400;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $codeHash;

    #[ORM\Column]
    private \DateTimeImmutable $codeExpiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $codeUsedAt = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $accessHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accessExpiresAt = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $refreshHash = null;

    /** The refresh token rotated away; presenting it again means it leaked. */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $previousRefreshHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refreshExpiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private OAuthClient $client,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $scopes,
        /** The MCP endpoint the tokens are for (RFC 8707). */
        #[ORM\Column(length: 2048)]
        private string $resource,
        #[ORM\Column(length: 2048)]
        private string $redirectUri,
        #[ORM\Column(length: 128)]
        private string $codeChallenge,
        string $code,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->codeHash = self::hash($code);
        $this->codeExpiresAt = $this->createdAt->modify(\sprintf('+%d seconds', self::CODE_TTL));
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Swaps the code, or the refresh token, for a new pair of tokens.
     */
    public function issue(string $accessToken, string $refreshToken, \DateTimeImmutable $now): void
    {
        $this->codeUsedAt ??= $now;
        $this->previousRefreshHash = $this->refreshHash;
        $this->accessHash = self::hash($accessToken);
        $this->accessExpiresAt = $now->modify(\sprintf('+%d seconds', self::ACCESS_TTL));
        $this->refreshHash = self::hash($refreshToken);
        $this->refreshExpiresAt = $now->modify(\sprintf('+%d seconds', self::REFRESH_TTL));
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
        $this->accessHash = null;
        $this->refreshHash = null;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    public function isCodeRedeemable(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && null === $this->codeUsedAt && $now < $this->codeExpiresAt;
    }

    public function isAccessValid(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && null !== $this->accessExpiresAt && $now < $this->accessExpiresAt;
    }

    public function isRefreshValid(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && null !== $this->refreshExpiresAt && $now < $this->refreshExpiresAt;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return null !== $this->codeUsedAt && $this->isRefreshValid($now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function wasCodeUsed(): bool
    {
        return null !== $this->codeUsedAt;
    }
}
