<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Connection;
use App\Entity\ConnectionAuth;
use App\Entity\ConnectionStatus;
use App\Repository\ConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConnectionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testListsConnections(): void
    {
        $this->entityManager->persist(new Connection('GitHub', 'https://api.githubcopilot.com/mcp/', ConnectionAuth::OAuth));
        $this->entityManager->flush();

        $this->client->request('GET', '/connections');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'GitHub');
        self::assertSelectorTextContains('main', 'Not connected');
    }

    public function testRequiresATokenForBearerAuth(): void
    {
        $this->client->request('GET', '/connections/new');
        $this->client->submitForm('Connect', [
            'connection[name]' => 'Stripe',
            'connection[url]' => 'https://mcp.stripe.com',
            'connection[auth]' => 'bearer',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'A bearer token is required.');
    }

    public function testRejectsADuplicateName(): void
    {
        $this->entityManager->persist(new Connection('GitHub', 'https://api.githubcopilot.com/mcp/', ConnectionAuth::OAuth));
        $this->entityManager->flush();

        $this->client->request('GET', '/connections/new');
        $this->client->submitForm('Connect', [
            'connection[name]' => 'github',
            'connection[url]' => 'https://example.com/mcp',
            'connection[auth]' => 'none',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Another connection already uses this name.');
    }

    public function testRefusesServersOnAPrivateNetwork(): void
    {
        $this->client->request('GET', '/connections/new');
        $this->client->submitForm('Connect', [
            'connection[name]' => 'Internal',
            'connection[url]' => 'http://10.0.0.5/mcp',
            'connection[auth]' => 'none',
        ]);

        self::assertResponseRedirects();
        $connection = self::getContainer()->get(ConnectionRepository::class)->findOneBy(['slug' => 'internal']);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame(ConnectionStatus::Failed, $connection->getStatus());
        self::assertStringContainsString('10.0.0.5', (string) $connection->getLastError());
    }

    public function testRejectsAForgedSync(): void
    {
        $connection = new Connection('GitHub', 'https://api.githubcopilot.com/mcp/', ConnectionAuth::OAuth);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        $this->client->request('POST', '/connections/'.$connection->getId().'/sync', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }
}
