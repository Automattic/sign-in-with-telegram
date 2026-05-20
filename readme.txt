=== Telegram Auth ===
Contributors: automattic
Tags: telegram, login, oidc, authentication, sign-in
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Telegram login to your WordPress site. Visitors sign in with their existing Telegram account — no new password to remember.

== Description ==

Telegram Auth lets visitors sign in to your WordPress site using their Telegram account, via [Telegram's OpenID Connect provider](https://core.telegram.org/bots/telegram-login). Unlike older Telegram-login plugins that embed Telegram's JavaScript "Login Widget", this plugin uses a standard server-side OIDC redirect flow — no third-party scripts on your pages, no widget cookies, works in any browser including those with strict privacy and tracker-blocking settings.

= Features =

* **"Sign in with Telegram" button** on the standard `wp-login.php` screen, as a `[telegram_auth_button]` shortcode anywhere on your site, or as a Block Editor block.
* **Account linking** from the user profile screen — existing WordPress users can connect their Telegram account, and disconnect again with a guard that refuses to leave anyone without a working sign-in method.
* **Profile sync** — display name and avatar from the user's Telegram profile flow through to the WordPress profile automatically.
* **No email-based account merging** — the only path from a Telegram identity to an existing WordPress user is an explicit, click-through link from a logged-in session, which closes the door on the classic SSO account-takeover bug.
* **Modern OIDC** — Authorization Code + PKCE (S256), state and nonce protection, JWKS key rotation handled correctly, RS256-only signature verification.
* **Settings page** with a Test Connection button so you can validate your bot's credentials without leaving wp-admin. Optional toggles for the `phone` and `telegram:bot_access` scopes.

= How it compares to the legacy Login Widget =

Telegram's older Login Widget (used by most existing Telegram-login plugins on the directory) is **not** OAuth or OpenID Connect. It loads a JavaScript file from telegram.org that renders Telegram's button on your page and then hands the auth result either to a JavaScript callback or to a server URL. Either mode still needs the embedded script to render the button in the first place. That setup is increasingly fragile:

* Browsers with strict third-party-script blocking — Brave with default shields, Firefox Enhanced Tracking Protection on Strict, Safari Lockdown Mode, uBlock Origin filter lists — frequently block the embedded script outright, so the button never renders and visitors have no way to start the flow.
* The widget's authentication hash is an HMAC-SHA256 over your bot token, so anyone who wants to verify a login has to hold a copy of that secret. There's no standard JWT / JWKS story to lean on.
* Key rotation is manual — changing the HMAC key means rotating the bot token in BotFather and updating it on every server that verifies logins.

Telegram Auth uses Telegram's newer OpenID Connect provider instead — a standard server-side redirect flow with a properly signed RS256 `id_token`. No third-party scripts on your pages, no shared bot-token secret with verifiers, automatic key rotation via JWKS. It behaves the same regardless of how privacy-locked-down the visitor's browser is.

== Installation ==

1. Install and activate the plugin.
2. Open [@BotFather](https://t.me/BotFather) in Telegram and launch its mini app from the attachment menu (the paperclip icon in the chat).
3. Pick your bot under **My bots**, then open **Login widget**. If your bot is still on the legacy widget, click **Switch to OpenID Connect Login** and confirm.
4. Register the callback URL under **Redirect URIs** — `https://yoursite.com/wp-login.php?action=telegram_auth_callback` — and add the matching site origin (`https://yoursite.com`) to **Trusted Origins**. HTTPS is required.
5. Copy the **Client ID** and **Client Secret** that BotFather shows you and paste them into **Settings → Telegram Auth** in wp-admin.
6. Optionally drop the **Telegram Login Button** block on your homepage, or add the `[telegram_auth_button]` shortcode anywhere you want a sign-in button.

== Frequently Asked Questions ==

= Where do I get the Client ID and Client Secret? =

Open [@BotFather](https://t.me/BotFather) in Telegram and launch its mini app from the attachment menu. Pick your bot under **My bots**, go to **Login widget**, and if you haven't already, click **Switch to OpenID Connect Login** and confirm. BotFather then shows the Client ID and Client Secret and lets you register the **Redirect URIs** and **Trusted Origins** your site will use. The Client Secret is **not** the bot token — they're different values, and the settings page warns you if you paste the wrong one.

= Do my visitors need to install anything? =

No. Telegram's OpenID Connect provider works through a normal browser redirect — visitors are sent to Telegram's login page, approve the sign-in, and land back on your site. They don't need the Telegram desktop / mobile app open or any browser extension.

= Will it work in browsers with strict privacy or tracker blocking? =

Yes. The plugin doesn't load any third-party scripts on your pages. Sign-in happens through a server-side redirect, the same way "Sign in with Google" or other OpenID Connect integrations do. Browsers that block `telegram.org`'s Login Widget script (Brave on default shields, Firefox ETP on Strict, Safari with Lockdown Mode, etc.) handle this flow fine.

= How does this handle email addresses? =

Telegram's OIDC provider doesn't supply an email claim, so the plugin creates new users without an email by default. Settings let you instead require a synthetic placeholder email (so password recovery still nominally works), or block sign-ups entirely and only allow existing WordPress users to connect their Telegram account from the profile screen.

= Is my visitor's password ever sent to Telegram? =

No. The plugin doesn't touch WordPress passwords. Sign-in happens entirely through Telegram's authentication system; your WordPress site receives a signed token verifying that the user is who they say they are.

== Changelog ==

= 0.1.0 =
* Initial release.
