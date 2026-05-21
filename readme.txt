=== Sign in with Telegram ===
Contributors: automattic, gmjuhasz, manzoorwanijk
Tags: telegram, login, oidc, authentication, sign-in
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
<!-- x-release-please-start-version -->
Stable tag: 0.1.0
<!-- x-release-please-end -->
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Telegram login to your WordPress site. Visitors sign in with their existing Telegram account — no new password to remember.

== Description ==

Sign in with Telegram lets visitors sign in to your WordPress site using their Telegram account, via [Telegram's OpenID Connect provider](https://core.telegram.org/bots/telegram-login). Unlike older Telegram-login plugins that embed Telegram's JavaScript "Login Widget", this plugin uses a standard server-side OIDC redirect flow — no third-party scripts on your pages, no widget cookies, works in any browser including those with strict privacy and tracker-blocking settings.

= Features =

* **"Sign in with Telegram" button** on the standard `wp-login.php` screen, as a `[telegram_signin_button]` shortcode anywhere on your site, or as a Block Editor block.
* **Account linking** from the user profile screen — existing WordPress users can connect or disconnect their Telegram account.
* **Profile sync** — display name and avatar from the user's Telegram profile flow through to the WordPress profile automatically.
* **No email-based account merging** — the only path from a Telegram identity to an existing WordPress user is an explicit, click-through link from a logged-in session, which closes the door on the classic SSO account-takeover bug.
* **Modern OIDC** — Authorization Code + PKCE (S256), state and nonce protection, JWKS key rotation handled correctly, RS256-only signature verification.
* **Settings page** in wp-admin where you paste the bot's Client ID + Client Secret, pick the default role for new users, and optionally collect the visitor's verified phone number or request permission for your bot to message them directly.

= How it compares to the legacy Login Widget =

Telegram's older Login Widget (used by most existing Telegram-login plugins on the directory) is **not** OAuth or OpenID Connect. It loads a JavaScript file from telegram.org that renders Telegram's button on your page and then hands the auth result either to a JavaScript callback or to a server URL. Either mode still needs the embedded script to render the button in the first place. That setup is increasingly fragile:

* Browsers with strict third-party-script blocking — Brave with default shields, Firefox Enhanced Tracking Protection on Strict, Safari Lockdown Mode, uBlock Origin filter lists — frequently block the embedded script outright, so the button never renders and visitors have no way to start the flow.
* The widget's authentication hash is an HMAC-SHA256 over your bot token, so anyone who wants to verify a login has to hold a copy of that secret. There's no standard JWT / JWKS story to lean on.
* Key rotation is manual — changing the HMAC key means rotating the bot token in BotFather and updating it on every server that verifies logins.

Sign in with Telegram uses Telegram's newer OpenID Connect provider instead — a standard server-side redirect flow with a properly signed RS256 `id_token`. No third-party scripts on your pages, no shared bot-token secret with verifiers, automatic key rotation via JWKS. It behaves the same regardless of how privacy-locked-down the visitor's browser is.

== Installation ==

1. Install and activate the plugin.
2. Open [@BotFather](https://t.me/BotFather) in Telegram and launch its mini app from the attachment menu (the paperclip icon in the chat).
3. Pick your bot under **My bots**, then open **Login widget**. If your bot is still on the legacy widget, click **Switch to OpenID Connect Login** and confirm.
4. Register the callback URL under **Redirect URIs** — `https://yoursite.com/wp-login.php?action=telegram_signin_callback` — and add the matching site origin (`https://yoursite.com`) to **Trusted Origins**. HTTPS is required.
5. Copy the **Client ID** and **Client Secret** that BotFather shows you and paste them into **Settings → Sign in with Telegram** in wp-admin.
6. Optionally drop the **Telegram Login Button** block on your homepage, or add the `[telegram_signin_button]` shortcode anywhere you want a sign-in button.

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

= Where can I find the source code? =

The plugin is developed in the open at [github.com/Automattic/sign-in-with-telegram](https://github.com/Automattic/sign-in-with-telegram). The repository contains the full TypeScript source for the React-based settings UI and the Block Editor block, the build tooling (npm scripts driving [@wordpress/build](https://www.npmjs.com/package/@wordpress/build)), and the test suite. Issues and pull requests are welcome.

= Where is the user's phone number stored? =

When the `phone` scope is granted, Telegram returns the phone number as a claim in the signed `id_token`. The plugin stores that value in its own usermeta key — `telegram_signin_phone`. Read the verified value via `Automattic\Telegram\SignIn\Phone::for_user( $user_id )`, and hook the `telegram_signin_phone` filter to redact or normalize it. Site authors on WooCommerce can surface the verified value as the customer's billing phone by hooking `woocommerce_customer_get_billing_phone`.

== Screenshots ==

1. Configure bot credentials, sign-up policy, email handling, and optional permissions.
2. Instructions show the Redirect URI and Trusted Origin for @BotFather.
3. The WordPress login form gains a Sign in with Telegram button.
4. User profiles can connect or disconnect Telegram.
5. The Users list can show Telegram-verified phone numbers.

== External services ==

This plugin connects to Telegram's OpenID Connect provider at `oauth.telegram.org` so visitors can sign in with their Telegram account. No data is sent to Telegram unless a visitor actively starts a sign-in.

What is sent, and when:

* **Sign-in start.** When a visitor clicks the "Sign in with Telegram" button, their browser is redirected to `oauth.telegram.org` with the bot's Client ID, the requested scopes (always `openid` and `profile`; additionally `phone` and / or `telegram:bot_access` if you enabled those in **Settings → Sign in with Telegram**), a random `state`, a random `nonce`, and a PKCE `code_challenge` (SHA-256). The only user-specific traffic at this step is the browser redirect itself. If the discovery cache is cold (see below), building the redirect URL also triggers an anonymous server-side GET of the discovery document — no user data in that request.
* **Sign-in callback.** After the visitor approves the sign-in on Telegram's side, Telegram redirects them back to your site with an authorization `code`. The plugin then makes a single server-to-server POST to Telegram's token endpoint, sending the Client ID + Client Secret (as HTTP Basic auth), the `code`, the matching PKCE `code_verifier`, and the redirect URI. Telegram responds with a signed `id_token` containing the visitor's Telegram identifier, name, profile picture URL, and (if the `phone` scope was granted) phone number.
* **Discovery + JWKS lookup.** The first sign-in after activation (and again after the local cache expires, 12 hours) triggers a one-off, anonymous GET to Telegram's OpenID Connect discovery document and JSON Web Key Set (JWKS) at `oauth.telegram.org`. Both responses are cached in WordPress transients. If a later `id_token` references a signing key that isn't in the cache (Telegram rotated keys), the JWKS is re-fetched once; a short cooldown prevents repeated refresh attempts. No user data is sent in any of these requests.

This service is provided by Telegram. Refer to Telegram's [Terms of Service](https://telegram.org/tos) and [Privacy Policy](https://telegram.org/privacy) for details on how Telegram handles the sign-in.

== Changelog ==

= 0.1.0 =

* Initial release.
