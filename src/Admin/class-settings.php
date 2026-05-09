<?php
/**
 * Plugin settings reader.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Admin;

use Telegram_Auth\OIDC\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the plugin's runtime settings.
 *
 * For A4 this is constant-backed only — the OIDC client credentials come
 * from `wp-config.php` constants, and `default_role` is hard-coded. The
 * full settings UI (B1, Track B) will extend this with persisted-option
 * fallback and the rest of the schema (allow_signups, email_mode, etc.).
 *
 * The split lets Track A move forward without waiting on Track B's UI:
 * developers configure the plugin via constants, the auth pipeline reads
 * them here, and once B1 lands, this class grows option-backed accessors
 * that fall back to the constants.
 */
class Settings {

	/**
	 * Constant name for the OIDC client id (Telegram bot's numeric id).
	 */
	public const CONSTANT_CLIENT_ID = 'TELEGRAM_AUTH_CLIENT_ID';

	/**
	 * Constant name for the OIDC client secret (BotFather → Web Login).
	 */
	public const CONSTANT_CLIENT_SECRET = 'TELEGRAM_AUTH_CLIENT_SECRET';

	/**
	 * Read the configured OIDC client id, or null when none is set.
	 *
	 * @return string|null
	 */
	public function get_client_id(): ?string {
		if ( ! defined( self::CONSTANT_CLIENT_ID ) ) {
			return null;
		}
		$value = (string) constant( self::CONSTANT_CLIENT_ID );
		return '' === $value ? null : $value;
	}

	/**
	 * Read the configured OIDC client secret, or null when none is set.
	 *
	 * @return string|null
	 */
	public function get_client_secret(): ?string {
		if ( ! defined( self::CONSTANT_CLIENT_SECRET ) ) {
			return null;
		}
		$value = (string) constant( self::CONSTANT_CLIENT_SECRET );
		return '' === $value ? null : $value;
	}

	/**
	 * Build the redirect URI we hand to Telegram's authorize endpoint.
	 *
	 * Always points at `wp-login.php?action=telegram_auth_callback`. The
	 * caller is expected to register this exact URL in BotFather → Web Login.
	 *
	 * @return string
	 */
	public function get_redirect_uri(): string {
		return (string) add_query_arg( 'action', 'telegram_auth_callback', wp_login_url() );
	}

	/**
	 * Default role assigned to users created via OIDC sign-up.
	 *
	 * Hard-coded to `subscriber` in A4. B1 will replace this with a
	 * setting-backed accessor once the settings UI is in place.
	 *
	 * @return string
	 */
	public function get_default_role(): string {
		return 'subscriber';
	}

	/**
	 * Whether new users may be created via OIDC sign-up.
	 *
	 * Hard-coded to `true` in A4 (subject to WP's own
	 * `users_can_register` option), with B1 adding a separate plugin-level
	 * toggle.
	 *
	 * @return bool
	 */
	public function allow_signups(): bool {
		return (bool) get_option( 'users_can_register', false );
	}

	/**
	 * Build a fully-populated OIDC Config from settings, or null if either
	 * of the two required credentials is missing.
	 *
	 * @return Config|null
	 */
	public function build_oidc_config(): ?Config {
		$client_id     = $this->get_client_id();
		$client_secret = $this->get_client_secret();
		if ( null === $client_id || null === $client_secret ) {
			return null;
		}
		return new Config(
			client_id:     $client_id,
			client_secret: $client_secret,
			redirect_uri:  $this->get_redirect_uri(),
		);
	}
}
