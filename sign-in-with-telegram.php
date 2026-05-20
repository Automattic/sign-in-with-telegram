<?php
/**
 * Plugin Name:       Sign in with Telegram
 * Plugin URI:        https://github.com/Automattic/sign-in-with-telegram
 * Description:       Let your visitors sign in to WordPress with their Telegram account.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Automattic
 * Author URI:        https://automattic.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sign-in-with-telegram
 * Domain Path:       /languages
 *
 * @package Automattic\Telegram\SignIn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin entry point. Loads the Composer autoloader and the build registry,
 * then bootstraps the plugin. If either dependency hasn't been installed yet
 * (e.g. on a fresh checkout that hasn't run `composer install` and
 * `npm run build`), surface a clear admin notice instead of fatalling.
 */
( static function (): void {
	$plugin_dir = plugin_dir_path( __FILE__ );

	$missing = array();
	if ( ! file_exists( $plugin_dir . 'vendor/autoload_packages.php' ) ) {
		$missing[] = 'composer install';
	}
	if ( ! file_exists( $plugin_dir . 'build/build.php' ) ) {
		$missing[] = 'npm install && npm run build';
	}

	if ( ! empty( $missing ) ) {
		add_action(
			'admin_notices',
			static function () use ( $missing ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p>%s <code>%s</code></p></div>',
					esc_html__( 'Sign in with Telegram is missing build artifacts. Run:', 'sign-in-with-telegram' ),
					esc_html( implode( ' && ', $missing ) )
				);
			}
		);
		return;
	}

	require_once $plugin_dir . 'vendor/autoload_packages.php';
	require_once $plugin_dir . 'build/build.php';

	\Automattic\Telegram\SignIn\Bootstrap::init();
} )();
