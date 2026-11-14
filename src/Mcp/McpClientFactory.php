<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Connection;
use App\Entity\ConnectionAuth;
use App\Security\SecretBox;
use Doctrine\DBAL\Connection as DbalConnection;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Auth\OAuthAuthenticator;
use Mcp\Client\Auth\RedirectAuthorizationHandler;
use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds SDK clients for connections, with OAuth state kept per connection.
 */
final readonly class McpClientFactory
{
    private const string CLIENT_NAME = 'Reflex';
    private const string CLIENT_VERSION = '0.1.0';

    /**
     * @param list<string> $allowedPrivateHosts hosts on a private network that connections may reach (e.g. bundled demo servers)
     */
    public function __construct(
        private DbalConnection $db,
        private SecretBox $secretBox,
        private UrlGeneratorInterface $urlGenerator,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%env(csv:REFLEX_ALLOWED_PRIVATE_HOSTS)%')]
        private array $allowedPrivateHosts = [],
    ) {
    }

    /**
     * Opens a connection. Throws AuthorizationPendingException when the user must authorize in the browser.
     */
    public function connect(Connection $connection): Client
    {
        $headers = [];
        $auth = null;

        match ($connection->getAuth()) {
            ConnectionAuth::Bearer => $headers['Authorization'] = 'Bearer '.$this->secret($connection),
            ConnectionAuth::OAuth => $auth = $this->createAuthenticator($connection),
            ConnectionAuth::None => null,
        };

        $client = Client::builder()
            ->setClientInfo(self::CLIENT_NAME, self::CLIENT_VERSION)
            ->setLogger($this->logger)
            ->build();

        $client->connect(new HttpTransport(
            $connection->getUrl(),
            $headers,
            $this->psr18Client($connection),
            logger: $this->logger,
            auth: $auth,
        ));

        return $client;
    }

    public function createAuthenticator(Connection $connection): OAuthAuthenticator
    {
        $oauth = OAuth::forApplication(self::CLIENT_NAME)
            ->setApplicationType('web')
            ->setRedirectUri($this->urlGenerator->generate('app_connection_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL))
            ->setAuthorizationHandler(new RedirectAuthorizationHandler())
            ->setCredentialStorage($this->credentialStorage($connection))
            ->setHttpClient($this->psr18Client($connection))
            ->setLogger($this->logger);

        $clientId = $connection->getOauthClientId();
        $issuer = $connection->getOauthIssuer();
        if (null !== $clientId && null !== $issuer) {
            $secret = $connection->getEncryptedSecret();
            $oauth->setClientCredentials($clientId, null === $secret ? null : $this->secretBox->decrypt($secret))
                ->setExpectedIssuer($issuer);
        }

        return $oauth->build();
    }

    public function credentialStorage(Connection $connection): ConnectionCredentialStorage
    {
        return new ConnectionCredentialStorage($this->db, $this->secretBox, $connection->getId());
    }

    private function psr18Client(Connection $connection): Psr18Client
    {
        $host = parse_url($connection->getUrl(), \PHP_URL_HOST);
        $httpClient = \in_array($host, $this->allowedPrivateHosts, true)
            ? $this->httpClient
            : new NoPrivateNetworkHttpClient($this->httpClient);

        return new Psr18Client($httpClient);
    }

    private function secret(Connection $connection): string
    {
        $secret = $connection->getEncryptedSecret();
        if (null === $secret) {
            throw new \LogicException(\sprintf('Connection "%s" has no token.', $connection->getName()));
        }

        return $this->secretBox->decrypt($secret);
    }
}
