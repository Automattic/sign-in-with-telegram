<?php
/**
 * Plugin bootstrap. Wires up the admin menu when the runtime
 * (WP Core 7.0+ or the Gutenberg plugin) is present, and shows a
 * dependency notice otherwise.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth;

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
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_notices', array( self::class, 'maybe_show_dependency_notice' ) );
	}

	/**
	 * Whether the @wordpress/boot runtime is available on this install.
	 *
	 * @wordpress/boot ships with WordPress Core 7.0+ or with the Gutenberg
	 * plugin; the wp-build-generated pages won't render without it.
	 */
	public static function has_runtime(): bool {
		if ( version_compare( (string) get_bloginfo( 'version' ), '7.0', '>=' ) ) {
			return true;
		}
		$active = (array) get_option( 'active_plugins', array() );
		return in_array( 'gutenberg/gutenberg.php', $active, true );
	}

	/**
	 * Register the Telegram Auth menu page once the runtime is in place.
	 */
	public static function register_menu(): void {
		if ( ! self::has_runtime() ) {
			return;
		}

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
	 * Surface an admin notice when the runtime is missing.
	 */
	public static function maybe_show_dependency_notice(): void {
		if ( self::has_runtime() ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'Telegram Auth requires WordPress 7.0+ or the Gutenberg plugin. Activate Gutenberg to use the plugin.',
				'telegram-auth'
			)
		);
	}
}
