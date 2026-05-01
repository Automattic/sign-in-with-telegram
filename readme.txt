=== Telegram Auth ===
Contributors: automattic
Tags: telegram, login, oidc, authentication, sign-in
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Telegram login to your WordPress site. Visitors sign in with their existing Telegram account — no new password to remember.

== Description ==

Telegram Auth lets visitors sign in to your WordPress site using their Telegram account, via Telegram's OpenID Connect login.

Includes a "Sign in with Telegram" button block for the editor, account linking from the user profile screen, and a settings page to configure your bot.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/telegram-auth/` or install via the WordPress plugin directory.
2. Activate the plugin.
3. Go to **Settings → Telegram Auth** and paste your bot's Client ID and Client Secret (generated in BotFather → Web Login).

== Frequently Asked Questions ==

= Where do I get the Client ID and Client Secret? =

Open BotFather in Telegram, choose your bot, then **Bot Settings → Web Login**. Register your site's redirect URI and BotFather will display the Client ID and Client Secret. The Client Secret is **not** the bot token — they're different values.

== Changelog ==

= 0.1.0 =
* Initial release.
