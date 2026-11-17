<?php

declare(strict_types=1);

namespace App\Tests\Webhook;

use App\Webhook\GitHubRequestParser;
use App\Webhook\StripeRequestParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class ProviderRequestParserTest extends TestCase
{
    public function testGitHubJoinsEventAndAction(): void
    {
        $body = '{"action":"opened","issue":{"number":7}}';
        $event = (new GitHubRequestParser())->parse($this->request($body, [
            'X-GitHub-Event' => 'issues',
            'X-GitHub-Delivery' => 'delivery-7',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'gh-secret'),
        ]), 'gh-secret');

        self::assertInstanceOf(\Symfony\Component\RemoteEvent\RemoteEvent::class, $event);
        self::assertSame('issues.opened', $event->getName());
        self::assertSame('delivery-7', $event->getId());
    }

    public function testGitHubIgnoresPingAndRejectsBadSignatures(): void
    {
        $body = '{"zen":"Keep it logically awesome."}';
        $headers = ['X-GitHub-Event' => 'ping', 'X-GitHub-Delivery' => 'd1', 'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'gh-secret')];
        self::assertNull((new GitHubRequestParser())->parse($this->request($body, $headers), 'gh-secret'));

        $this->expectException(RejectWebhookException::class);
        (new GitHubRequestParser())->parse($this->request($body, $headers), 'other-secret');
    }

    public function testStripeVerifiesSignatureAndTimestamp(): void
    {
        $clock = new MockClock('2026-11-18 12:00:00');
        $body = '{"id":"evt_1","type":"invoice.payment_failed","data":{"object":{}}}';
        $timestamp = $clock->now()->getTimestamp();

        $event = (new StripeRequestParser($clock))->parse($this->request($body, [
            'Stripe-Signature' => \sprintf('t=%d,v1=%s,v0=ignored', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_x')),
        ]), 'whsec_x');

        self::assertInstanceOf(\Symfony\Component\RemoteEvent\RemoteEvent::class, $event);
        self::assertSame('invoice.payment_failed', $event->getName());
        self::assertSame('evt_1', $event->getId());
    }

    public function testStripeRejectsReplays(): void
    {
        $clock = new MockClock('2026-11-18 12:00:00');
        $body = '{"id":"evt_1","type":"invoice.payment_failed"}';
        $old = $clock->now()->getTimestamp() - StripeRequestParser::TOLERANCE - 1;

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('tolerance');
        (new StripeRequestParser($clock))->parse($this->request($body, [
            'Stripe-Signature' => \sprintf('t=%d,v1=%s', $old, hash_hmac('sha256', $old.'.'.$body, 'whsec_x')),
        ]), 'whsec_x');
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $body, array $headers): Request
    {
        $request = Request::create('/webhook/test', 'POST', content: $body);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->add($headers);

        return $request;
    }
}
