<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\Connection;
use App\Entity\ConnectionAuth;
use App\Mcp\McpClientFactory;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client\Auth\AccessToken;
use Mcp\Client\Auth\ClientRegistration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConnectionCredentialStorageTest extends KernelTestCase
{
    private const string ISSUER = 'https://auth.example.com';
    private const string RESOURCE = 'https://mcp.example.com/mcp#authorization_code';

    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->connection = new Connection('Example', 'https://mcp.example.com/mcp', ConnectionAuth::OAuth);
        $this->entityManager->persist($this->connection);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testStoresTokensAndRegistrationsEncrypted(): void
    {
        $storage = $this->storage();
        $storage->saveClientRegistration(self::ISSUER, ClientRegistration::fromArray(['client_id' => 'client-123', 'dynamic' => true]));
        $storage->saveToken(self::ISSUER, self::RESOURCE, AccessToken::fromResponse(['access_token' => 'secret-token', 'expires_in' => 3600]));

        $fresh = $this->storage();
        self::assertSame('client-123', $fresh->getClientRegistration(self::ISSUER)?->clientId);
        self::assertSame('secret-token', $fresh->getToken(self::ISSUER, self::RESOURCE)?->accessToken);
        self::assertNull($fresh->getToken(self::ISSUER, 'https://other.example.com#authorization_code'));

        $raw = self::getContainer()->get(DbalConnection::class)->fetchOne('SELECT data FROM connection_credentials');
        self::assertIsString($raw);
        self::assertStringNotContainsString('secret-token', $raw);
    }

    public function testForgetsOneIssuerOrEverything(): void
    {
        $storage = $this->storage();
        $storage->saveToken(self::ISSUER, self::RESOURCE, AccessToken::fromResponse(['access_token' => 'a']));
        $storage->saveToken('https://other.example.com', self::RESOURCE, AccessToken::fromResponse(['access_token' => 'b']));

        $storage->forget(self::ISSUER);
        self::assertNull($storage->getToken(self::ISSUER, self::RESOURCE));
        self::assertNotNull($storage->getToken('https://other.example.com', self::RESOURCE));

        $storage->forget();
        self::assertNull($storage->getToken('https://other.example.com', self::RESOURCE));
    }

    public function testSynchronizeIsReentrant(): void
    {
        $storage = $this->storage();

        $calls = 0;
        $storage->synchronize(static function () use ($storage, &$calls): void {
            ++$calls;
            $storage->synchronize(static function () use (&$calls): void {
                ++$calls;
            });
        });

        self::assertSame(2, $calls);
        self::assertNull($storage->consumePendingAuthorization('unknown-state'));
    }

    private function storage(): \App\Mcp\ConnectionCredentialStorage
    {
        return self::getContainer()->get(McpClientFactory::class)->credentialStorage($this->connection);
    }
}
