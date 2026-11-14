<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * OAuth state of a connection (client registration, tokens, pending authorizations), encrypted.
 *
 * Read and written with DBAL by ConnectionCredentialStorage, which locks the row; mapped only for the schema.
 */
#[ORM\Entity]
#[ORM\Table(name: 'connection_credentials')]
class ConnectionCredentials
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: Connection::class)]
        #[ORM\JoinColumn(name: 'connection_id', nullable: false, onDelete: 'CASCADE')]
        public readonly Connection $connection,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $data,
    ) {
    }
}
