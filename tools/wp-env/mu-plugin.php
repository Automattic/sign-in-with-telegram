<?php
/**
 * Plugin Name: Telegram Auth — wp-env dev helper
 * Description: Dev-only mu-plugin loaded inside the wp-env stack. Reads the OIDC client credentials from the host shell environment so contributors don't have to edit wp-config.php to test the plugin.
 *
 * Lives at /tools/wp-env/mu-plugin.php in the repo and is mounted into
 * wp-content/mu-plugins/ by .wp-env.json.
 *
 * @package Telegram_Auth
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	foreach ( array( 'TELEGRAM_AUTH_CLIENT_ID', 'TELEGRAM_AUTH_CLIENT_SECRET' ) as $name ) {
		if ( defined( $name ) ) {
			continue;
		}
		$value = getenv( $name );
		if ( false !== $value && '' !== $value ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Names are hard-coded above and all carry the TELEGRAM_AUTH_ prefix.
			define( $name, $value );
		}
	}
} )();
