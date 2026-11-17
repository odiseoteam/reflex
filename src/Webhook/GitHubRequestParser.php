<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * GitHub webhooks, verified with X-Hub-Signature-256. The event type joins the
 * X-GitHub-Event header and the payload action, e.g. "issues.opened".
 */
final class GitHubRequestParser extends AbstractRequestParser
{
    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsJsonRequestMatcher(),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): ?RemoteEvent
    {
        if ('' === $secret) {
            throw new RejectWebhookException(500, 'No webhook secret configured.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if (!hash_equals($expected, (string) $request->headers->get('X-Hub-Signature-256'))) {
            throw new RejectWebhookException(401, 'Invalid signature.');
        }

        $event = (string) $request->headers->get('X-GitHub-Event');
        $delivery = (string) $request->headers->get('X-GitHub-Delivery');
        if ('' === $event || '' === $delivery) {
            throw new RejectWebhookException(400, 'Missing X-GitHub-Event or X-GitHub-Delivery header.');
        }

        // Sent once when the webhook is created.
        if ('ping' === $event) {
            return null;
        }

        $payload = $request->toArray();
        $action = $payload['action'] ?? null;

        return new RemoteEvent(\is_string($action) ? $event.'.'.$action : $event, $delivery, $payload);
    }
}
