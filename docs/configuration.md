# Configuration

## Services

`docker compose up --wait` starts everything Reflex needs:

| Service | What it does |
| --- | --- |
| `php` | The app on FrankenPHP in worker mode, at <http://localhost:8088> |
| `worker` | Messenger: runs reflexes, executes approved calls, and the Scheduler's ticks |
| `listener` | Holds the MCP resource subscriptions open (`reflex:listen`) |
| `database` | PostgreSQL 18 with pgvector |
| `embeddings` | Text Embeddings Inference with `intfloat/multilingual-e5-small`, for the memory |
| `demo` | The shop and helpdesk MCP servers of Polar Coffee Store |

In development, `tailwind` also rebuilds the CSS on every change.

## Environment variables

Defaults live in `.env`. Put your own values in `.env.local`, which git ignores, then restart with `docker compose restart php worker listener`.

| Variable | Default | What it sets |
| --- | --- | --- |
| `ANTHROPIC_API_KEY` | empty | Key for the Claude models |
| `HF_TOKEN` | empty | Hugging Face token for `openai/gpt-oss-120b`, fine-grained with "Make calls to Inference Providers" |
| `REFLEX_MODELS` | `auto` | `auto` replays recorded answers when the model's key is missing and calls it live otherwise; `live`, `replay`, or `record` to call live and save the answers to `demo/recordings` |
| `REFLEX_COMPANY_NAME` | `Polar Coffee Store` | Company shown next to the logo |
| `REFLEX_WEBHOOK_SECRET` | empty | HMAC secret of `POST /webhook/generic` |
| `GITHUB_WEBHOOK_SECRET` | empty | Secret of `POST /webhook/github` |
| `STRIPE_WEBHOOK_SECRET` | empty | Secret of `POST /webhook/stripe` |
| `REFLEX_ALLOWED_PRIVATE_HOSTS` | `demo` | Private hosts MCP connections may reach, comma-separated. Any other private or local address is refused |
| `REFLEX_RETENTION_DAYS` | `30` | Finished runs older than this are deleted every night |
| `EMBEDDINGS_URL` | `http://embeddings` | Text Embeddings Inference server of the memory |
| `DEMO_MCP_URL` | `http://demo:8090` | Base URL of the demo's MCP servers |
| `MCP_ALLOWED_HOSTS` | `localhost,127.0.0.1` | Hosts Reflex's own MCP server answers on, e.g. `reflex.example.com` when public |
| `HTTP_PORT` | `8088` | Port of the app on your machine, read by Docker Compose |

Credentials of MCP connections, like bearer tokens and OAuth tokens, are stored encrypted.

## Console commands

Run them with `docker compose exec php bin/console <command>`.

| Command | What it does |
| --- | --- |
| `reflex:demo:setup` | Sets up the demo company. `--reset-data` puts its data back to the start, `--password` sets the people's password |
| `reflex:simulate <scenario>` | Makes something happen at the demo company. `--list` shows the scenarios, `--customer` picks who a failed payment belongs to |
| `reflex:user:create <email>` | Creates a person, or updates one. `--admin` lets them manage reflexes and decide; `--name` and `--password` set the rest |
| `reflex:decide <decision> <action>` | Approves, rejects, answers or declines a decision. `--as` names who decides, `--note` and `--answer` (JSON) carry the rest |
| `reflex:listen` | Holds the subscriptions open; the `listener` service runs it |

## Development

```bash
docker compose exec php composer ci        # PHP CS Fixer, PHPStan and PHPUnit
docker compose exec php composer cs:fix    # fix the code style
```

Tests run against PostgreSQL inside a transaction per test, with the MCP servers and models replaced by test doubles. GitHub Actions runs the same checks on every push to `main` and every pull request, lints the container and the config, and starts the whole stack with Docker Compose as a smoke test.

To record new model answers for the demo, set `REFLEX_MODELS=record` and a key in `.env.local`, play the scenarios and decide them; the answers land in `demo/recordings`, one file per reflex.
