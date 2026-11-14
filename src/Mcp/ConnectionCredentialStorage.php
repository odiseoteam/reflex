<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Security\SecretBox;
use Doctrine\DBAL\Connection as DbalConnection;
use Mcp\Client\Auth\AccessToken;
use Mcp\Client\Auth\ClientRegistration;
use Mcp\Client\Auth\PendingAuthorization;
use Mcp\Client\Auth\PendingAuthorizationStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * OAuth credentials of one connection, encrypted in the `connection_credentials` table.
 *
 * synchronize() locks the connection row, so workers never refresh the same token twice.
 *
 * @phpstan-type Data array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>, pending: array<string, array<string, mixed>>}
 */
final class ConnectionCredentialStorage implements PendingAuthorizationStorageInterface
{
    private int $depth = 0;

    public function __construct(
        private readonly DbalConnection $db,
        private readonly SecretBox $secretBox,
        private readonly Uuid $connectionId,
    ) {
    }

    public function getClientRegistration(string $issuer): ?ClientRegistration
    {
        $registration = $this->load()['registrations'][$issuer] ?? null;

        return null === $registration ? null : ClientRegistration::fromArray($registration);
    }

    public function saveClientRegistration(string $issuer, ClientRegistration $registration): void
    {
        $this->update(static function (array $data) use ($issuer, $registration): array {
            $data['registrations'][$issuer] = $registration->jsonSerialize();

            return $data;
        });
    }

    public function getToken(string $issuer, string $resource): ?AccessToken
    {
        $token = $this->load()['tokens'][$issuer][$resource] ?? null;

        return null === $token ? null : AccessToken::fromArray($token);
    }

    public function saveToken(string $issuer, string $resource, AccessToken $token): void
    {
        $this->update(static function (array $data) use ($issuer, $resource, $token): array {
            $data['tokens'][$issuer][$resource] = $token->jsonSerialize();

            return $data;
        });
    }

    public function forget(?string $issuer = null): void
    {
        $this->update(static function (array $data) use ($issuer): array {
            if (null === $issuer) {
                return ['registrations' => [], 'tokens' => [], 'pending' => []];
            }

            unset($data['registrations'][$issuer], $data['tokens'][$issuer]);

            return $data;
        });
    }

    public function savePendingAuthorization(PendingAuthorization $authorization): void
    {
        $this->update(static function (array $data) use ($authorization): array {
            $data['pending'][$authorization->state] = $authorization->jsonSerialize();

            return $data;
        });
    }

    public function consumePendingAuthorization(string $state): ?PendingAuthorization
    {
        return $this->synchronize(function () use ($state): ?PendingAuthorization {
            $data = $this->load();
            $pending = $data['pending'][$state] ?? null;
            if (null === $pending) {
                return null;
            }

            unset($data['pending'][$state]);
            $this->store($data);

            return PendingAuthorization::fromArray($pending);
        });
    }

    public function synchronize(callable $operation): mixed
    {
        // The SDK may nest operations; only the outermost one opens the transaction.
        if ($this->depth > 0) {
            ++$this->depth;
            try {
                return $operation();
            } finally {
                --$this->depth;
            }
        }

        $this->depth = 1;
        try {
            return $this->db->transactional(function () use ($operation): mixed {
                $this->db->executeQuery('SELECT id FROM connection WHERE id = ? FOR UPDATE', [$this->connectionId->toRfc4122()]);

                return $operation();
            });
        } finally {
            $this->depth = 0;
        }
    }

    /**
     * @param callable(Data): Data $change
     */
    private function update(callable $change): void
    {
        $this->synchronize(function () use ($change): void {
            $this->store($change($this->load()));
        });
    }

    /**
     * @return Data
     */
    private function load(): array
    {
        $empty = ['registrations' => [], 'tokens' => [], 'pending' => []];

        $encrypted = $this->db->fetchOne(
            'SELECT data FROM connection_credentials WHERE connection_id = ?',
            [$this->connectionId->toRfc4122()],
        );
        if (!\is_string($encrypted)) {
            return $empty;
        }

        /** @var Data $data */
        $data = json_decode($this->secretBox->decrypt($encrypted), true, flags: \JSON_THROW_ON_ERROR);

        return $data + $empty;
    }

    /**
     * @param Data $data
     */
    private function store(array $data): void
    {
        $this->db->executeStatement(
            'INSERT INTO connection_credentials (connection_id, data) VALUES (?, ?)
             ON CONFLICT (connection_id) DO UPDATE SET data = EXCLUDED.data',
            [$this->connectionId->toRfc4122(), $this->secretBox->encrypt(json_encode($data, \JSON_THROW_ON_ERROR))],
        );
    }
}
