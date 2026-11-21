<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\Connection;
use App\Entity\ConnectionAuth;
use App\Mcp\McpClientFactory;
use App\Security\SecretBox;
use Doctrine\DBAL\Connection as DbalConnection;
use Mcp\Client\Exception\RequestException;
use Mcp\Server\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Against a server whose tool mirrors arguments into Mcp-Param-* headers, as GitHub's does.
 */
final class McpClientFactoryTest extends KernelTestCase
{
    private McpClientFactory $factory;
    private Connection $connection;

    protected function setUp(): void
    {
        $server = Server::builder()
            ->setSession(new InMemorySessionStore())
            ->addTool(static fn (string $owner, string $repo): array => ['issues' => [['number' => 1, 'repo' => $owner.'/'.$repo]]], name: 'list_issues', inputSchema: [
                'type' => 'object',
                'properties' => [
                    'owner' => ['type' => 'string', 'x-mcp-header' => 'owner'],
                    'repo' => ['type' => 'string', 'x-mcp-header' => 'repo'],
                ],
                'required' => ['owner', 'repo'],
            ])
            ->build();

        $psr = new Psr17Factory();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($server, $psr): MockResponse {
            $request = $psr->createServerRequest($method, $url);
            foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
                if (\is_string($line)) {
                    [$name, $value] = explode(': ', $line, 2);
                    $request = $request->withAddedHeader($name, $value);
                }
            }
            $request = $request->withBody($psr->createStream(\is_string($options['body'] ?? null) ? $options['body'] : ''));
            $response = $server->run(new StreamableHttpTransport($request, $psr, $psr, middleware: []));

            return new MockResponse((string) $response->getBody(), ['http_code' => $response->getStatusCode(), 'response_headers' => $response->getHeaders()]);
        });

        $container = self::getContainer();
        $this->factory = new McpClientFactory(
            $container->get(DbalConnection::class),
            $container->get(SecretBox::class),
            $container->get(UrlGeneratorInterface::class),
            $http,
            new NullLogger(),
            ['mcp.test'],
        );
        $this->connection = new Connection('GitHub', 'http://mcp.test/mcp', ConnectionAuth::None);
    }

    public function testACallCarriesTheParameterHeadersOfAToolNotListedYet(): void
    {
        $result = $this->factory->callTool($this->connection, 'list_issues', ['owner' => 'odiseoteam', 'repo' => 'reflex']);

        self::assertFalse($result->isError);
        self::assertSame(['issues' => [['number' => 1, 'repo' => 'odiseoteam/reflex']]], $result->structuredContent);
    }

    public function testTheServerRejectsACallWithoutThem(): void
    {
        $client = $this->factory->connect($this->connection);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Mcp-Param-owner');
        try {
            $client->callTool('list_issues', ['owner' => 'odiseoteam', 'repo' => 'reflex']);
        } finally {
            $client->disconnect();
        }
    }
}
