<?php

declare(strict_types=1);

namespace App\OAuth;

use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Asks for more scope (403 insufficient_scope) before a tool the token can't call runs,
 * so the client can step up instead of getting a tool error. Runs after the firewall.
 */
final readonly class ScopeGuard
{
    /** Tools that change something; everything else needs reading only. */
    public const array WRITE_TOOLS = [
        'approve_decision', 'reject_decision', 'answer_question', 'decline_question', 'create_reflex', 'run_reflex',
    ];

    private const int MAX_INSPECTED_BYTES = 1_000_000;

    private ScopePolicy $policy;

    public function __construct(
        private CurrentGrant $current,
        private BearerChallenge $challenge,
    ) {
        $this->policy = new ScopePolicy(
            default: [Scope::Read->value],
            tools: array_fill_keys(self::WRITE_TOOLS, [Scope::Write->value]),
            implies: [Scope::Write->value => [Scope::Read->value]],
        );
    }

    #[AsEventListener(KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $grant = $this->current->get();
        if (!$event->isMainRequest() || Endpoints::MCP_ROUTE !== $request->attributes->get('_route') || null === $grant) {
            return;
        }

        $message = null;
        if ($request->isMethod('POST') && \strlen($request->getContent()) <= self::MAX_INSPECTED_BYTES) {
            $message = json_decode($request->getContent(), true);
        }

        $granted = $this->policy->expand($grant->getScopes());
        $missing = array_diff($this->policy->requiredFor($message), $granted);
        if ([] === $missing) {
            return;
        }

        $event->setResponse($this->challenge->challenge(403, 'insufficient_scope', 'This app was given read-only access to Reflex.', array_values(array_unique([...$grant->getScopes(), ...$missing]))));
    }
}
