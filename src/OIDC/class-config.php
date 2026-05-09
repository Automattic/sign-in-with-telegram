<?php
/**
 * OIDC client configuration value object.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\OIDC;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable container for the credentials and endpoints the OIDC client needs.
 */
final class Config {

	/**
	 * Default OIDC discovery URL for Telegram's OAuth/OIDC provider.
	 */
	public const TELEGRAM_DISCOVERY_URL = 'https://oauth.telegram.org/.well-known/openid-configuration';

	/**
	 * Build the value object.
	 *
	 * @param string $client_id     OIDC client identifier (the bot's numeric id from BotFather → Web Login).
	 * @param string $client_secret OIDC client secret from BotFather → Web Login (NOT the bot token).
	 * @param string $redirect_uri  Absolute redirect URI registered with the bot in BotFather.
	 * @param string $discovery_url Override for the OIDC discovery document URL. Defaults to Telegram's.
	 */
	public function __construct(
		public readonly string $client_id,
		public readonly string $client_secret,
		public readonly string $redirect_uri,
		public readonly string $discovery_url = self::TELEGRAM_DISCOVERY_URL,
	) {}
}
