<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Browser-based MCP clients discover, register and redeem tokens from another origin. These
 * endpoints use no cookies, so any origin may call them.
 */
final class OAuthCors
{
    private const string PATTERN = '#^/(\.well-known/oauth-|oauth/(token|register|revoke)$)#';

    #[AsEventListener(KernelEvents::REQUEST, priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && $request->isMethod('OPTIONS') && self::applies($request)) {
            $event->setResponse(self::allow(new Response(null, 204)));
        }
    }

    #[AsEventListener(KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && self::applies($event->getRequest())) {
            self::allow($event->getResponse());
        }
    }

    private static function applies(Request $request): bool
    {
        return 1 === preg_match(self::PATTERN, $request->getPathInfo());
    }

    private static function allow(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, MCP-Protocol-Version');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
