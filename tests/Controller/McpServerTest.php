<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Decision;
use App\Entity\IncomingEvent;
use App\Entity\Precedent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\User;
use App\Entity\Waitpoint;
use App\Entity\WaitpointKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An MCP client connects with OAuth as a person and uses the inbox through Reflex's MCP server.
 */
final class McpServerTest extends WebTestCase
{
    private const string REDIRECT = 'http://localhost:33418/callback';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Decision $decision;
    private User $alice;
    private User $vera;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $reflex = new Reflex('Triage', 'generic', '*', 'Close duplicates.');
        $event = new IncomingEvent('generic', 'delivery-1', 'issue.opened', ['title' => 'Same as #3']);
        $run = new Run($reflex, $event);
        $run->start();
        $waitpoint = new Waitpoint($run, WaitpointKind::Decisions);
        $run->wait('Proposed closing #7.', []);
        $this->decision = new Decision($run, $waitpoint, null, 'tracker_close_issue', 'close_issue', ['id' => 7], 'Duplicate of #3.');
        $this->alice = new User('alice@example.com', 'Alice', true);
        $this->vera = new User('vera@example.com', 'Vera', false);
        foreach ([$reflex, $event, $run, $waitpoint, $this->decision, $this->alice, $this->vera] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testWithoutATokenItPointsToTheAuthorizationServer(): void
    {
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));

        $this->client->request('GET', '/.well-known/oauth-protected-resource/mcp');
        $metadata = $this->json();
        self::assertSame('http://localhost/mcp', $metadata['resource']);
        self::assertSame(['http://localhost'], $metadata['authorization_servers']);

        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertSame(['S256'], $this->json()['code_challenge_methods_supported']);
    }

    public function testAnAdminConnectsAnAppAndRejectsThroughIt(): void
    {
        $tokens = $this->connect($this->alice);
        self::assertSame('reflex:read reflex:write', $tokens['scope']);

        $tools = $this->mcp($tokens['access_token'], 'tools/list');
        self::assertStringContainsString('"name":"approve_decision"', json_encode($tools, \JSON_THROW_ON_ERROR));

        $pending = $this->structured($this->mcp($tokens['access_token'], 'tools/call', ['name' => 'list_decisions', 'arguments' => new \stdClass()]));
        self::assertStringContainsString($this->decision->getId()->toRfc4122(), json_encode($pending, \JSON_THROW_ON_ERROR));

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'reject_decision', 'arguments' => ['id' => $this->decision->getId()->toRfc4122(), 'note' => 'Not a duplicate.']]);

        $decision = $this->entityManager->find(Decision::class, $this->decision->getId());
        self::assertSame(Decision::REJECTED, $decision?->getStatus());
        $precedent = $this->entityManager->getRepository(Precedent::class)->findOneBy(['decision' => $decision]);
        self::assertSame('Alice', $precedent?->getDecidedBy());
        self::assertSame('Not a duplicate.', $precedent->getNote());
    }

    public function testAViewerGetsReadOnlyAccessAndWriteToolsAskForMore(): void
    {
        $tokens = $this->connect($this->vera, consent: 'You are a viewer, so it can only read.');
        self::assertSame('reflex:read', $tokens['scope']);

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'get_decision', 'arguments' => ['id' => $this->decision->getId()->toRfc4122()]]);
        self::assertResponseIsSuccessful();

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'reject_decision', 'arguments' => ['id' => $this->decision->getId()->toRfc4122()]], expectJson: false);
        self::assertResponseStatusCodeSame(403);
        $challenge = (string) $this->client->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="reflex:read reflex:write"', $challenge);
    }

    public function testACodeWorksOnceWithItsVerifierAndAReplayRevokesItsTokens(): void
    {
        [$clientId, $verifier, $code] = $this->authorize($this->alice);

        $this->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => str_repeat('x', 64)]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_grant', $this->json()['error']);

        $tokens = $this->tokens(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier, 'resource' => 'http://localhost/mcp']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier]);
        self::assertSame('invalid_grant', $this->json()['error']);

        $this->mcp($tokens['access_token'], 'tools/list', expectJson: false);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshTokensRotateAndAReusedOneEndsTheGrant(): void
    {
        [$clientId, $verifier, $code] = $this->authorize($this->alice);
        $first = $this->tokens(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier]);

        $second = $this->tokens(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $first['refresh_token']]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->mcp($second['access_token'], 'tools/list');

        $this->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $first['refresh_token']]);
        self::assertSame('invalid_grant', $this->json()['error']);

        $this->mcp($second['access_token'], 'tools/list', expectJson: false);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDisconnectingAnAppStopsItsTokens(): void
    {
        $tokens = $this->connect($this->alice);

        $this->client->request('GET', '/apps');
        self::assertSelectorTextContains('main section li', 'Test client');
        self::assertSelectorCount(2, '[data-controller="clipboard"] button[data-action="clipboard#copy"]');
        self::assertSelectorTextContains('[data-controller="clipboard"] code', 'http://localhost/mcp');
        $this->client->submitForm('Disconnect');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'No apps connected.');

        $this->mcp($tokens['access_token'], 'tools/list', expectJson: false);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnUnregisteredRedirectStaysOnReflex(): void
    {
        $clientId = $this->register();
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeParams($clientId, 'challenge', ['redirect_uri' => 'https://evil.example/steal'])));

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('main', 'an address it did not register');
    }

    public function testTokensAreOnlyForThisMcpEndpoint(): void
    {
        $clientId = $this->register();
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeParams($clientId, rtrim(strtr(base64_encode(hash('sha256', 'v', true)), '+/', '-_'), '='), ['resource' => 'https://other.example/mcp'])));

        self::assertResponseRedirects();
        self::assertStringContainsString('error=invalid_target', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @return array{access_token: string, refresh_token: string, scope: string}
     */
    private function connect(User $user, ?string $consent = null): array
    {
        [$clientId, $verifier, $code] = $this->authorize($user, $consent);

        return $this->tokens(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier]);
    }

    /**
     * @return array{string, string, string} client id, code verifier and authorization code
     */
    private function authorize(User $user, ?string $consent = null): array
    {
        $clientId = $this->register();
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->client->loginUser($user);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeParams($clientId, $challenge)));
        self::assertSelectorTextContains('h1', 'Connect Test client to Reflex?');
        if (null !== $consent) {
            self::assertSelectorTextContains('main', $consent);
        }

        $this->client->submitForm('Allow');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('xyz', $query['state']);
        self::assertSame('http://localhost', $query['iss']);
        self::assertIsString($query['code']);

        return [$clientId, $verifier, $query['code']];
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function authorizeParams(string $clientId, string $challenge, array $overrides = []): array
    {
        return $overrides + [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'resource' => 'http://localhost/mcp',
        ];
    }

    private function register(): string
    {
        $this->client->request('POST', '/oauth/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'client_name' => 'Test client',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => 'none',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $clientId = $this->json()['client_id'];
        self::assertIsString($clientId);

        return $clientId;
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function token(array $params): array
    {
        $this->client->request('POST', '/oauth/token', $params);

        return $this->json();
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{access_token: string, refresh_token: string, scope: string}
     */
    private function tokens(array $params): array
    {
        $response = $this->token($params);
        self::assertIsString($response['access_token'] ?? null, json_encode($response, \JSON_THROW_ON_ERROR));
        self::assertIsString($response['refresh_token'] ?? null);
        self::assertIsString($response['scope'] ?? null);

        return ['access_token' => $response['access_token'], 'refresh_token' => $response['refresh_token'], 'scope' => $response['scope']];
    }

    /**
     * A modern-era (stateless) JSON-RPC request, with its required headers and _meta.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function mcp(string $token, string $method, array $params = [], bool $expectJson = true): array
    {
        $version = '2026-07-28';
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => $version,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1.0'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_MCP_PROTOCOL_VERSION' => $version,
            'HTTP_MCP_METHOD' => $method,
        ];
        if (\is_string($params['name'] ?? null)) {
            $headers['HTTP_MCP_NAME'] = $params['name'];
        }

        $this->client->request('POST', '/mcp', [], [], $headers, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));
        if (!$expectJson) {
            return [];
        }

        self::assertResponseIsSuccessful();
        $response = $this->json();
        self::assertArrayNotHasKey('error', $response, json_encode($response, \JSON_THROW_ON_ERROR));

        return $response;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function structured(array $response): array
    {
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false, json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertIsArray($result['content'] ?? null);
        self::assertIsArray($result['content'][0] ?? null);
        $text = $result['content'][0]['text'] ?? null;
        self::assertIsString($text);
        /** @var array<string, mixed> $data */
        $data = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data, (string) $this->client->getResponse()->getContent());

        return $data;
    }
}
