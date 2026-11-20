<?php

declare(strict_types=1);

// The MCP servers of Polar Coffee Store, the demo company: /shop/mcp and /support/mcp.
// Served with `php -S` and PHP_CLI_SERVER_WORKERS > 1, since a subscriptions/listen stream holds a worker.

use Demo\PolarCoffee\Shop;
use Demo\PolarCoffee\Store;
use Demo\PolarCoffee\Support;
use Mcp\Server\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(\is_string($uri) ? $uri : '/', \PHP_URL_PATH);
$store = Store::fromEnvironment();
$system = match ($path) {
    '/shop/mcp' => new Shop($store),
    '/support/mcp' => new Support($store),
    default => null,
};

if (null === $system) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Polar Coffee Store demo: MCP servers at /shop/mcp and /support/mcp.\n";

    return;
}

$builder = Server::builder()
    ->setSession(new FileSessionStore(sys_get_temp_dir().'/polar-coffee-sessions'))
    ->setNotificationBus($store->bus())
    ->setSubscriptionLifetime(25);
$server = $system->register($builder)->build();

$factory = new Psr17Factory();
$request = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest(Request::createFromGlobals());
// Reached by its compose service name, not localhost, so the DNS rebinding check is off.
$response = $server->run(new StreamableHttpTransport($request, $factory, $factory, middleware: []));

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name.': '.$value, false);
    }
}

// Streamed, so the notifications of a listen stream leave as they happen.
$body = $response->getBody();
while (!$body->eof()) {
    echo $body->read(8192);
    flush();
}
