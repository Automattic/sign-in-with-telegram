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

if ( ! defined( 'TELEGRAM_AUTH_CLIENT_ID' ) ) {
	$client_id = getenv( 'TELEGRAM_AUTH_CLIENT_ID' );
	if ( false !== $client_id && '' !== $client_id ) {
		define( 'TELEGRAM_AUTH_CLIENT_ID', $client_id );
	}
}

if ( ! defined( 'TELEGRAM_AUTH_CLIENT_SECRET' ) ) {
	$client_secret = getenv( 'TELEGRAM_AUTH_CLIENT_SECRET' );
	if ( false !== $client_secret && '' !== $client_secret ) {
		define( 'TELEGRAM_AUTH_CLIENT_SECRET', $client_secret );
	}
}
