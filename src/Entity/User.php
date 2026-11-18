<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Admins manage connections and reflexes and decide; viewers only look.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    public const string ROLE_VIEWER = 'ROLE_VIEWER';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $password = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 180, unique: true)]
        private string $email,
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column]
        private bool $admin = false,
    ) {
        $this->id = Uuid::v7();
        $this->email = mb_strtolower($email);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): void
    {
        $this->admin = $admin;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->admin ? [self::ROLE_ADMIN] : [self::ROLE_VIEWER];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __serialize(): array
    {
        // The session keeps a hash of the hash, never the hash itself.
        return ['id' => $this->id, 'email' => $this->email, 'name' => $this->name, 'admin' => $this->admin, 'password' => hash('crc32c', $this->password), 'createdAt' => $this->createdAt];
    }

    /**
     * @param array{id: Uuid, email: string, name: string, admin: bool, password: string, createdAt: \DateTimeImmutable} $data
     */
    public function __unserialize(array $data): void
    {
        ['id' => $this->id, 'email' => $this->email, 'name' => $this->name, 'admin' => $this->admin, 'password' => $this->password, 'createdAt' => $this->createdAt] = $data;
    }
}
