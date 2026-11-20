# Triggers

A reflex runs when its trigger fires. The trigger is picked in the reflex form, and the run gets what fired it as its event: the payload of a webhook, the new contents of a resource, the new items of a poll.

| Trigger | Fires when | Event type of the run |
| --- | --- | --- |
| [Webhook](#webhooks) | A system posts an event | The event's own type, e.g. `issues.opened` |
| [MCP subscription](#mcp-subscriptions) | An MCP server says a resource changed | `resource.updated` or `resources.list_changed` |
| [Poll](#polls) | An MCP tool returns something new | `items.new` or `result.changed` |
| [X without Y](#x-without-y) | An event isn't followed by another in time | e.g. `order.paid.without.order.shipped` |
| [Schedule](#schedules) | A cron expression is due | `tick` |

Whatever fires a reflex reaches the model marked as data, never instructions.

## Webhooks

Events arrive at `POST /webhook/{source}`, through Symfony's Webhook and RemoteEvent components. A reflex reacts to one event type of a source, or to all of them with `*`.

| Source | Endpoint | Signature | Event type |
| --- | --- | --- | --- |
| `generic` | `/webhook/generic` | `X-Reflex-Signature: sha256=` + hex HMAC-SHA256 of the raw body, with `REFLEX_WEBHOOK_SECRET` | The `X-Reflex-Event` header |
| `github` | `/webhook/github` | GitHub's `X-Hub-Signature-256`, with `GITHUB_WEBHOOK_SECRET` | `X-GitHub-Event` and the payload's action, e.g. `issues.opened` |
| `stripe` | `/webhook/stripe` | Stripe's `Stripe-Signature`, with `STRIPE_WEBHOOK_SECRET` | The payload's `type`, e.g. `invoice.payment_failed` |

A source without a secret rejects every request. Each event is stored once: a redelivery with the same id (`X-Reflex-Delivery`, `X-GitHub-Delivery` or the Stripe event id) doesn't start a second run.

For example, with the generic source:

```bash
body='{"order_id": "PC-1101", "customer": {"email": "ana@example.com"}}'
curl -X POST http://localhost:8088/webhook/generic \
  -H 'Content-Type: application/json' \
  -H 'X-Reflex-Event: payment.failed' \
  -H "X-Reflex-Delivery: $(uuidgen)" \
  -H "X-Reflex-Signature: sha256=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$REFLEX_WEBHOOK_SECRET" | cut -d' ' -f2)" \
  -d "$body"
```

## MCP subscriptions

The reflex subscribes to resources of a connection, one URI per line, and runs when the server says one of them changed. The run gets the resource's new contents. It can also run when the server's list of resources changes.

The `listener` service holds the subscriptions open with `subscriptions/listen`, from MCP 2026-07-28. The MCP PHP SDK's client didn't have it, so Reflex uses a fork that adds it. A server's stream doesn't replay what happened while it was closed, so every time a stream opens again, the listener reads the subscribed resources and fires for those that changed in between.

The server must support resource subscriptions. In the demo, the helpdesk notifies ticket `T-501`.

## Polls

The reflex calls a tool of a connection every few minutes, with fixed JSON arguments. The tool must be automatic in the reflex's policy, since nobody approves a poll.

With an **item id field**, the result is read as a list and the reflex runs on items with an id not seen before, all of them in one run. Without it, the reflex runs whenever the result changes.

The first poll only takes note of what is there, so existing items don't fire a run.

## X without Y

The reflex runs when a webhook event X isn't followed by an event Y of the same source within some minutes, for the same value of a field. For example: `order.paid` without `order.shipped` within 2 minutes, for the same `order_id`.

The field is a dot path into both payloads, like `customer.email`. Its values are stored hashed, since they are often personal data. If Y arrives in time, nothing runs; if both Y and the deadline come at once, only one of them wins.

## Schedules

The reflex runs on a cron expression, in UTC, e.g. `0 9 * * 1` for Mondays at 09:00. The Scheduler checks once a minute. Slots missed while the worker was down collapse into one run.

## Running a reflex by hand

**Run now**, on the reflex page, starts a run without an event. Its tool calls follow the policy like any other run. Without an API key it is disabled, since the recorded answers only cover events.
