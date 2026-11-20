# Demo scenarios

The demo company is **Polar Coffee Store**, a small online coffee shop. Its shop and its helpdesk are two MCP servers that run in the `demo` service of Docker Compose, with their data in a JSON file, so nothing outside the repository is needed.

`bin/console reflex:demo:setup` creates its people, connects both servers and creates five reflexes. Run it again at any time: it updates what it created and leaves the rest alone. Add `--reset-data` to put the shop and the helpdesk back to their starting data and forget the demo's runs and decisions.

## People

| Email | Name | Can |
| --- | --- | --- |
| `alice@polarcoffee.test` | Alice (owner) | Approve decisions and manage reflexes |
| `bob@polarcoffee.test` | Bob (support lead) | Approve decisions and manage reflexes |
| `vera@polarcoffee.test` | Vera (viewer) | See decisions and runs, not approve them |

They all use the password `polarcoffee`, or the one given with `--password`.

## Scenarios

Make something happen from the **Simulate event** button of the dashboard (signed in as Alice or Bob), or from the console:

```bash
docker compose exec php bin/console reflex:simulate --list
docker compose exec php bin/console reflex:simulate payment-failed --customer=bruno
```

| Scenario | Trigger | Reflex | What it proposes |
| --- | --- | --- | --- |
| `payment-failed` | Webhook `payment.failed` | Rescue failed payments | Ana is a loyal subscriber: a 10% coupon with a warm note. Bruno is a new customer: a ticket asking him to update his card |
| `customer-replies` | MCP subscription to ticket `T-501` | Answer ticket replies | Carla sent a photo of a torn bag: a reply on the ticket, and a partial refund. Approving the refund makes the shop ask whether to refund the card or give store credit |
| `order-paid` | `order.paid` without `order.shipped` within 2 minutes | Chase late shipments | An apology ticket saying the order ships within a day. Simulate `order-shipped` in time and nothing runs |
| `bad-review` | Poll of `list_reviews`, every minute | Reply to bad reviews | A 1-star review from Bruno: a public reply that owns the problem, and a 15% coupon |
| `weekly-digest` | Schedule, Mondays at 09:00 UTC | Weekly digest | Nothing: it only reads orders, tickets and reviews, and writes a summary for the owners |

The `customer-replies` scenario needs the `listener` service, which holds the MCP subscriptions open. It starts with `docker compose up`.

## Recorded answers

Without an API key, runs replay model answers recorded in `demo/recordings`, and show a **Replayed model** badge. Tools and decisions stay live: the shop really issues the coupon you approve.

The recordings cover each scenario above with every combination of approving and rejecting its proposals. For `payment-failed`, they cover Ana and Bruno; Carla, the third customer, needs a real model.

A path nobody recorded still ends: the run closes with a summary of what happened, written without the model. And a reflex can't be run by hand without a key, since its recordings answer events, not a run started by hand.

To go beyond the recordings, [use a real model](../README.md#use-a-real-model).
