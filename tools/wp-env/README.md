# Local development with wp-env

This plugin uses [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) for local testing. It runs WordPress inside Docker, with the plugin mounted from the working tree and auto-activated.

## Prerequisites

- Docker (Docker Desktop or equivalent) running on your machine.
- Node + npm — install dev dependencies once with `npm install` from the repo root.

## Bring the stack up

```bash
npm run env:start
```

When it finishes you can:

- Visit <http://localhost:8888> for the front-end.
- Visit <http://localhost:8888/wp-admin/> and log in with `admin` / `password`.
- Run WP-CLI commands against the stack via `npm run env:cli -- <subcommand>` (e.g. `npm run env:cli -- plugin list`).

The plugin lives at `wp-content/plugins/telegram-auth/` inside the container, mounted directly from your working tree — no rebuild needed when you save PHP. For JS/CSS, run `npm run dev` in another terminal so wp-build watches the sub-packages.

## Wiring the OIDC flow

The mu-plugin at `tools/wp-env/mu-plugin.php` (mounted into `wp-content/mu-plugins/` automatically) reads two environment variables and defines them as PHP constants:

- `TELEGRAM_AUTH_CLIENT_ID`
- `TELEGRAM_AUTH_CLIENT_SECRET`

Export them in your shell **before** running `npm run env:start` (wp-env reads them once when starting):

```bash
export TELEGRAM_AUTH_CLIENT_ID=123456789
export TELEGRAM_AUTH_CLIENT_SECRET=your-bot-oidc-secret
npm run env:start
```

In BotFather, register your local redirect URI as:

```
http://localhost:8888/wp-login.php?action=telegram_auth_callback
```

If you start the stack without those vars set, the plugin still loads — it just shows a "not configured" admin notice, which is the correct dev UX until you supply credentials.

## Companion scripts

- `npm run env:stop` — stop the containers (state preserved).
- `npm run env:reset` — destroy and recreate the stack (use after a major schema change).
- `npm run env:logs` — tail container logs.
