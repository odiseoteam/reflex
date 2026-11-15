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
 * Webhooks from any system that signs its JSON body with HMAC-SHA256.
 *
 * Headers: X-Reflex-Event (event type), X-Reflex-Delivery (unique id),
 * X-Reflex-Signature ("sha256=" + hex HMAC of the raw body).
 */
final class GenericRequestParser extends AbstractRequestParser
{
    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsJsonRequestMatcher(),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): RemoteEvent
    {
        if ('' === $secret) {
            throw new RejectWebhookException(500, 'No webhook secret configured.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if (!hash_equals($expected, (string) $request->headers->get('X-Reflex-Signature'))) {
            throw new RejectWebhookException(401, 'Invalid signature.');
        }

        $type = (string) $request->headers->get('X-Reflex-Event');
        if (1 !== preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $type)) {
            throw new RejectWebhookException(400, 'Missing or invalid X-Reflex-Event header.');
        }

        $delivery = (string) $request->headers->get('X-Reflex-Delivery');
        if ('' === $delivery || \strlen($delivery) > 255) {
            throw new RejectWebhookException(400, 'Missing or invalid X-Reflex-Delivery header.');
        }

        return new RemoteEvent($type, $delivery, $request->toArray());
    }
}
