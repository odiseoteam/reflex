<?php

declare(strict_types=1);

namespace App\Webhook;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Stripe webhooks, verified with the Stripe-Signature header ("t=…,v1=…") and a
 * five-minute window against replays. The event type is Stripe's, e.g. "invoice.payment_failed".
 */
final class StripeRequestParser extends AbstractRequestParser
{
    public const int TOLERANCE = 300;

    public function __construct(private readonly ClockInterface $clock = new NativeClock())
    {
    }

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

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $request->headers->get('Stripe-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            match ($key) {
                't' => $timestamp = $value,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        if (null === $timestamp || !ctype_digit($timestamp) || [] === $signatures) {
            throw new RejectWebhookException(401, 'Missing Stripe-Signature.');
        }

        if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE) {
            throw new RejectWebhookException(401, 'Signature timestamp outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        $valid = array_filter($signatures, static fn (string $signature): bool => hash_equals($expected, $signature));
        if ([] === $valid) {
            throw new RejectWebhookException(401, 'Invalid signature.');
        }

        $payload = $request->toArray();
        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        if (!\is_string($id) || !\is_string($type)) {
            throw new RejectWebhookException(400, 'Payload is not a Stripe event.');
        }

        return new RemoteEvent($type, $id, $payload);
    }
}
