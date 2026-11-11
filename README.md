# Reflex

Self-hosted Symfony app that connects to a company's systems over MCP, reacts to their events, acts within per-tool policies and escalates big decisions to people.

> Work in progress for the Symfony AI & MCP Hackathon.

## Run it

Requires Docker with Compose v2.

```bash
git clone <repo-url> reflex && cd reflex
docker compose up --wait
```

Open <http://localhost:8088/health>. Set `HTTP_PORT` to use another port.

## Develop

```bash
docker compose exec php composer ci        # CS, PHPStan and tests
docker compose exec php composer cs:fix    # fix code style
```

## Stack

Symfony 8.1 on FrankenPHP (worker mode), PHP 8.5, PostgreSQL 18 with pgvector, Messenger (Doctrine transport), Symfony AI and the MCP PHP SDK.

## License

MIT
