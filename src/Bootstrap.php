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
	 * Slug used as the @wordpress/build page id and the wp-admin menu slug.
	 */
	public const PAGE_SLUG = 'telegram-auth';

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

		// wp-build generates this name as PREFIX (wpPlugin.name) + page slug
		// with hyphens converted to underscores. Both are "telegram_auth"
		// here, hence the duplication.
		$render_callback = 'telegram_auth_telegram_auth_render_page';
		if ( ! function_exists( $render_callback ) ) {
			return;
		}

		add_menu_page(
			__( 'Telegram Auth', 'telegram-auth' ),
			__( 'Telegram Auth', 'telegram-auth' ),
			'manage_options',
			self::PAGE_SLUG,
			$render_callback,
			'dashicons-rest-api',
			80
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
