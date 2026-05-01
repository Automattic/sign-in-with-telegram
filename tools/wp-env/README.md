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

Bot credentials are injected as PHP constants via wp-env's standard override mechanism — `.wp-env.override.json`, which wp-env merges over `.wp-env.json` on every start.

```bash
cp .wp-env.override.json.example .wp-env.override.json
# edit .wp-env.override.json
npm run env:start
```

Fill in the values from BotFather → **Bot Settings → Web Login**:

```json
{
	"config": {
		"TELEGRAM_AUTH_CLIENT_ID": "123456789",
		"TELEGRAM_AUTH_CLIENT_SECRET": "your-bot-oidc-secret"
	}
}
```

`.wp-env.override.json` is gitignored. `.wp-env.override.json.example` is committed as the template.

In BotFather, register your local redirect URI as:

```
http://localhost:8888/wp-login.php?action=telegram_auth_callback
```

If you start the stack without the override file, the plugin still loads — it just shows a "not configured" admin notice, which is the correct dev UX until you supply credentials.

## Companion scripts

- `npm run env:stop` — stop the containers (state preserved).
- `npm run env:reset` — destroy and recreate the stack (use after a major schema change, or after editing `.wp-env.override.json`).
- `npm run env:logs` — tail container logs.
