<?php
/**
 * Plugin bootstrap. Registers the WP Build polyfills early so the
 * wp-build-generated pages can mount @wordpress/boot, then adds the
 * Settings → Telegram Auth submenu.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth;

use Automattic\Jetpack\WP_Build_Polyfills\WP_Build_Polyfills;
use Telegram_Auth\Admin\Settings;
use Telegram_Auth\Auth\Login_Handler;
use Telegram_Auth\Auth\Transaction;
use Telegram_Auth\Http\Endpoints;
use Telegram_Auth\Http\Failure_Renderer;
use Telegram_Auth\UI\Avatar_Provider;
use Telegram_Auth\UI\Login_Button;
use Telegram_Auth\UI\Login_Button_Block;
use Telegram_Auth\UI\Profile_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level plugin coordinator. Wires WordPress hooks at boot.
 */
class Bootstrap {

	/**
	 * Menu slug for the Settings → Telegram Auth submenu.
	 *
	 * The wp-build page id is "telegram-auth" (in package.json wpPlugin.pages).
	 * The integrated wp-admin render callback page-wp-admin.php intercepts the
	 * URL ?page=<page-id>-wp-admin, so we register the submenu under the
	 * matching slug.
	 */
	public const PAGE_SLUG = 'telegram-auth-wp-admin';

	/**
	 * Register WordPress hooks.
	 */
	public static function init(): void {
		// Polyfill the @wordpress/* packages our wp-build-generated pages
		// expect when running on WP < 7.0 without Gutenberg.
		WP_Build_Polyfills::register(
			'telegram-auth',
			array(
				// Force-replaced classic scripts: Core's wp-notices is missing
				// component exports @wordpress/boot needs, and wp-private-apis'
				// allowlist rejects @wordpress/theme/route on WP < 7.0.
				'wp-notices',
				'wp-private-apis',
				// Conditionally-registered classic scripts. wp-theme is listed
				// in the boot module's asset.php as a classic-script dependency,
				// and Core doesn't ship it; without this, WP silently refuses
				// to print the prerequisites script and the page renders blank.
				// wp-views is listed for completeness — boot may grow to need
				// it the same way.
				'wp-theme',
				'wp-views',
				// Script modules.
				'@wordpress/boot',
				'@wordpress/route',
				'@wordpress/a11y',
			)
		);

		// Wire the auth pipeline.
		$settings           = new Settings();
		$transaction        = new Transaction();
		$failure_renderer   = new Failure_Renderer();
		$login_handler      = new Login_Handler( $settings, $transaction, $failure_renderer );
		$endpoints          = new Endpoints( $settings, $transaction, $login_handler, $failure_renderer );
		$login_button       = new Login_Button( $settings );
		$login_button_block = new Login_Button_Block( $login_button );
		$avatar_provider    = new Avatar_Provider();
		$profile_section    = new Profile_Section();

		$settings->register();
		$endpoints->register();
		$failure_renderer->register();
		$login_button->register();
		$login_button_block->register();
		$avatar_provider->register();
		$profile_section->register();

		add_action( 'admin_menu', array( self::class, 'register_menu' ) );

		add_action(
			'admin_print_scripts-settings_page_' . self::PAGE_SLUG,
			static function () use ( $settings ): void {
				$data = array(
					'settings'     => $settings->get_all(),
					'settingsMeta' => array(
						'client_id_source'     => $settings->get_client_id_source(),
						'client_secret_source' => $settings->get_client_secret_source(),
					),
				);
				printf(
					'<script>window.telegramAuthData = %s;</script>',
					wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode escapes appropriately for a script context.
				);
			}
		);
	}

	/**
	 * Register the Settings → Telegram Auth submenu.
	 */
	public static function register_menu(): void {
		// wp-admin-mode render callback from wp-build's pages template.
		// Renders into a mount div inside the standard wp-admin chrome
		// (vs. the full-page render which replaces it). Function name is
		// PREFIX (wpPlugin.name) + page id (with hyphens → underscores)
		// + "_wp_admin_render_page".
		$render_callback = 'telegram_auth_telegram_auth_wp_admin_render_page';
		if ( ! function_exists( $render_callback ) ) {
			return;
		}

		add_submenu_page(
			'options-general.php',
			__( 'Telegram Auth', 'telegram-auth' ),
			__( 'Telegram Auth', 'telegram-auth' ),
			'manage_options',
			self::PAGE_SLUG,
			$render_callback
		);
	}
}
