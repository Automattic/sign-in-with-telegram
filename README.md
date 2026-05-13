# Telegram Auth

Let your visitors sign in to WordPress with their Telegram account.

A WordPress plugin built around Telegram's OpenID Connect login. See [`readme.txt`](readme.txt) for the wp.org-facing description.

## Local development

Requirements: Docker, Node 24+, PHP 8.1+, Composer.

```bash
composer install
npm install
```

Bring up a fresh WordPress + plugin in Docker:

```bash
npm run env:start
```

The site is at <http://localhost:8888> (admin login: `admin` / `password`). The plugin is mounted from the working tree, so PHP edits show up on refresh; for JS/CSS, run `npm run dev` in another terminal.

### Bot credentials

Create a `.wp-env.override.json` at the repo root (gitignored) with this shape:

```json
{
	"$schema": "https://schemas.wp.org/trunk/wp-env.json",
	"config": {
		"TELEGRAM_AUTH_CLIENT_ID": "123456789",
		"TELEGRAM_AUTH_CLIENT_SECRET": "your-bot-oidc-secret"
	}
}
```

If you route outbound HTTP from the container through a host-side proxy (e.g. to capture the OIDC traffic in a debugging proxy), add the proxy settings here too — those are local-machine settings and shouldn't be tracked:

```json
{
	"config": {
		"WP_PROXY_HOST": "socks://host.docker.internal",
		"WP_PROXY_PORT": "8080"
	}
}
```

Get the values from BotFather → **Bot Settings → Web Login**. wp-env merges the override file on every start; the `config` block is materialized as PHP constants in `wp-config.php`.

**Telegram requires a public HTTPS redirect URI** — `http://localhost:8888/...` is rejected at the BotFather step. To work against the local stack, tunnel it through a public hostname using [Jurassic Tube](https://jurassic.tube) (Automattic), [ngrok](https://ngrok.com), or [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/), then register the tunneled URL with BotFather:

```
https://<your-tunnel-host>/wp-login.php?action=telegram_auth_callback
```

Match `WP_SITEURL` / `WP_HOME` in `.wp-env.override.json` to the tunnel host so WordPress generates the same URLs it tells Telegram about, otherwise the callback redirect mismatches.

If you start the stack without the override file, the plugin still loads — it just shows a "not configured" admin notice.

### Companion scripts

| Script                     | Purpose                                                                     |
| -------------------------- | --------------------------------------------------------------------------- |
| `npm run dev`              | wp-build watch mode for the JS/SCSS sub-packages.                           |
| `npm run build`            | One-shot production build of `build/`.                                      |
| `npm run env:stop`         | Stop the wp-env containers (state preserved).                               |
| `npm run env:reset`        | Destroy and recreate the stack (use after editing `.wp-env.override.json`). |
| `npm run env:cli -- <cmd>` | Run WP-CLI inside the container.                                            |
| `npm run env:logs`         | Tail container logs.                                                        |

### Quality gates

Run the same checks CI does:

```bash
composer phpcs
composer phpunit
npm run lint:js
npm run lint:css
npm run typecheck
npm test
```

See [`docs/contributing.md`](docs/contributing.md) for the full contributor workflow.

## License

[GPL-2.0-or-later](LICENSE).
