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
use Telegram_Auth\Admin\Users_List_Columns;
use Telegram_Auth\Auth\Login_Handler;
use Telegram_Auth\Auth\Transaction;
use Telegram_Auth\Http\Endpoints;
use Telegram_Auth\Http\Failure_Renderer;
use Telegram_Auth\Privacy\Personal_Data;
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
		// @wordpress/boot's asset.php declares @wordpress/lazy-editor as a
		// dynamic-import dependency, but neither Core nor the polyfills
		// package ships it yet. Register an empty stub on the settings
		// page so WP 6.9.1+'s WP_Script_Modules::register doesn't fire
		// a "doing it wrong" notice when the polyfills enqueue boot.
		//
		// Hooked at wp_default_scripts priority 15 — after Core's
		// default priority-10 registration, before the polyfills'
		// own priority-20 registration. WP_Script_Modules::register
		// is documented as "first wins", so a real lazy-editor
		// shipped by a future WP/Gutenberg automatically takes
		// precedence over this stub. Scoped to our page so we don't
		// shadow other surfaces that actually consume lazy-editor.
		add_action(
			'wp_default_scripts',
			static function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL inspection to scope a no-op script-module stub.
				$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
				if ( self::PAGE_SLUG !== $page ) {
					return;
				}
				if ( function_exists( 'wp_register_script_module' ) ) {
					wp_register_script_module( '@wordpress/lazy-editor', 'data:text/javascript;charset=utf-8,' );
				}
			},
			15
		);

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
		$login_button_block = new Login_Button_Block( $login_button, $settings );
		$avatar_provider    = new Avatar_Provider();
		$profile_section    = new Profile_Section( $settings );
		$personal_data      = new Personal_Data();
		$users_list_columns = new Users_List_Columns( $settings );

		$settings->register();
		$endpoints->register();
		$failure_renderer->register();
		$avatar_provider->register();
		$users_list_columns->register();
		$login_button->register();
		$login_button_block->register();
		$profile_section->register();
		$personal_data->register();

		$plugin_file = defined( 'TELEGRAM_AUTH_PLUGIN_FILE' )
			? TELEGRAM_AUTH_PLUGIN_FILE
			: dirname( __DIR__ ) . '/telegram-auth.php';
		add_filter( 'plugin_action_links_' . plugin_basename( $plugin_file ), array( self::class, 'add_settings_action_link' ) );
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );

		add_action(
			'admin_print_scripts-settings_page_' . self::PAGE_SLUG,
			static function () use ( $settings ): void {
				$site_origin  = self::site_origin();
				$redirect_uri = add_query_arg( 'action', 'telegram_auth_callback', wp_login_url() );

				$data = array(
					'settings'     => $settings->get_all(),
					'settingsMeta' => array(
						'client_id_source'     => $settings->get_client_id_source(),
						'client_secret_source' => $settings->get_client_secret_source(),
					),
					'siteOrigin'   => $site_origin,
					'redirectUri'  => $redirect_uri,
				);
				printf(
					'<script>window.telegramAuthData = %s;</script>',
					wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode escapes appropriately for a script context.
				);
			}
		);
	}

	/**
	 * Add a Settings link to the plugin row on wp-admin/plugins.php.
	 *
	 * @param string[] $links Existing plugin action links.
	 *
	 * @return string[]
	 */
	public static function add_settings_action_link( array $links ): array {
		$settings_url  = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) );
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Settings', 'telegram-auth' )
		);

		return array_merge( array( 'settings' => $settings_link ), $links );
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

	/**
	 * Build the canonical site origin (scheme://host[:port]) from home_url().
	 *
	 * Telegram matches Trusted Origins exactly, so a non-default port — e.g.
	 * the `:8888` on the local wp-env stack — has to be preserved.
	 *
	 * @return string
	 */
	private static function site_origin(): string {
		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}
}
