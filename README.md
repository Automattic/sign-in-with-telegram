# Telegram Auth

Let your visitors sign in to WordPress with their Telegram account.

## Description

Telegram Auth lets visitors sign in to your WordPress site using their Telegram account, via Telegram's OpenID Connect provider. Unlike older Telegram-login plugins that embed Telegram's JavaScript "Login Widget", this plugin uses a standard server-side OIDC redirect flow — no third-party scripts on your pages, no widget cookies, works in any browser including those with strict privacy and tracker-blocking settings.

### Features

- **"Sign in with Telegram" button** on the standard `wp-login.php` screen, as a `[telegram_auth_button]` shortcode anywhere on your site, and as a Block Editor block — one helper, three surfaces, identical HTML.
- **Account linking** from the user profile screen. A logged-in WordPress user can connect their Telegram account; disconnect is gated by a sole-auth-method guard so nobody locks themselves out.
- **Profile sync** — display name and avatar from the Telegram `id_token` flow through to the WordPress profile automatically.
- **No email-based account merging** — the only path from a Telegram identity to an existing WordPress user is an explicit, click-through link from a logged-in session, sidestepping the classic auto-merge account-takeover bug. (Telegram doesn't supply an `email` claim, so even an attacker with a Telegram account matching a victim's email can't cross the boundary.)
- **Modern OIDC under the hood** — Authorization Code + PKCE (`S256`), browser-bound `state` + `nonce`, kid-aware JWKS rotation with rate-limited refresh, `alg === 'RS256'` enforced via an explicit allowed-algs list. The whole auth path lives on `wp-login.php`, which keeps it compatible with membership / LMS plugins that gate `/wp-admin/` for non-admin users.
- **Settings UI** built with `@wordpress/dataviews` `DataForm` — paste your Client ID + Client Secret, choose a default role for new users, opt in to the `phone` or `telegram:bot_access` scopes, and validate your bot's credentials with a one-click Test Connection.

### How it compares to the legacy Login Widget

Telegram's older [Login Widget](https://core.telegram.org/widgets/login-legacy) (which most of the existing Telegram-login plugins on wp.org still wrap) is **not** OAuth or OpenID Connect. It loads a JavaScript file from `telegram.org` that renders Telegram's button on your page and then hands the auth result either to a JavaScript callback (`data-onauth`) or to a server URL (`data-auth-url`). Either mode still needs the embedded script to render the button in the first place. That setup is increasingly fragile:

- Browsers with strict third-party-script blocking — Brave with default shields, Firefox Enhanced Tracking Protection on **Strict**, Safari Lockdown Mode, uBlock Origin filter lists — frequently block the embedded `<script>` outright, so the button never renders and visitors have no way to start the flow.
- The widget's authentication hash is an HMAC-SHA256 over your bot token, so anyone who wants to verify a login has to hold a copy of that secret. There's no standard JWT / JWKS story to lean on.
- Key rotation is manual — changing the HMAC key means rotating the bot token in BotFather and updating it on every server that verifies logins.

Telegram Auth uses Telegram's newer OpenID Connect provider instead — a standard server-side redirect flow with a properly signed RS256 `id_token`. No third-party scripts on your pages, no shared bot-token secret with verifiers, automatic key rotation via JWKS. It behaves the same regardless of how privacy-locked-down the visitor's browser is.

## Installation

1. Install and activate the plugin.
2. Open [@BotFather](https://t.me/BotFather) in Telegram and launch its mini app from the attachment menu (the paperclip icon in the chat).
3. Pick your bot under **My bots**, then open **Login widget**. If your bot is still on the legacy widget, click **Switch to OpenID Connect Login** and confirm.
4. Register the callback URL under **Redirect URIs** — `https://yoursite.com/wp-login.php?action=telegram_auth_callback` — and add the matching site origin (`https://yoursite.com`) to **Trusted Origins**. HTTPS is required.
5. Copy the **Client ID** and **Client Secret** that BotFather shows you and paste them into **Settings → Telegram Auth** in wp-admin.
6. Optionally drop the **Telegram Login Button** block on your homepage, or add the `[telegram_auth_button]` shortcode anywhere you want a sign-in button.

## Frequently Asked Questions

### Where do I get the Client ID and Client Secret?

Open [@BotFather](https://t.me/BotFather) in Telegram and launch its mini app from the attachment menu. Pick your bot under **My bots**, go to **Login widget**, and if you haven't already, click **Switch to OpenID Connect Login** and confirm. BotFather then shows the Client ID and Client Secret and lets you register the **Redirect URIs** and **Trusted Origins** your site will use. The Client Secret is **not** the bot token — they're different values, and the settings page warns you if you paste the wrong one.

### Do my visitors need to install anything?

No. Telegram's OpenID Connect provider works through a normal browser redirect — visitors are sent to Telegram's login page, approve the sign-in, and land back on your site. They don't need the Telegram desktop / mobile app open or any browser extension.

### Will it work in browsers with strict privacy or tracker blocking?

Yes. The plugin doesn't load any third-party scripts on your pages. Sign-in happens through a server-side redirect, the same way "Sign in with Google" or other OpenID Connect integrations do. Browsers that block `telegram.org`'s Login Widget script (Brave on default shields, Firefox ETP on Strict, Safari with Lockdown Mode, etc.) handle this flow fine.

### How does this handle email addresses?

Telegram's OIDC provider doesn't supply an email claim, so the plugin creates new users without an email by default. Settings let you instead require a synthetic placeholder email (so password recovery still nominally works), or block sign-ups entirely and only allow existing WordPress users to connect their Telegram account from the profile screen.

### Is my visitor's password ever sent to Telegram?

No. The plugin doesn't touch WordPress passwords. Sign-in happens entirely through Telegram's authentication system; your WordPress site receives a signed token verifying that the user is who they say they are.

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

Get the values from BotFather's mini app — open [@BotFather](https://t.me/BotFather), pick your bot under **My bots**, choose **Login widget**, and switch to **OpenID Connect Login** if you haven't already. wp-env merges the override file on every start; the `config` block is materialized as PHP constants in `wp-config.php`.

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
