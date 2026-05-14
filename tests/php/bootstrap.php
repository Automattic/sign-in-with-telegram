<?php
/**
 * PHPUnit bootstrap. Loads Composer + Brain Monkey so unit tests can stub
 * WP functions without booting WordPress.
 *
 * @package Telegram_Auth\Tests
 */

declare(strict_types=1);

// Polyfill the WordPress constants our class files reference at load time.
// The plugin uses `defined( 'ABSPATH' ) || exit;` as a direct-access guard,
// and `HOUR_IN_SECONDS` shows up in default cache TTLs. These are core
// constants, not plugin-owned globals, so the prefix sniff is suppressed.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * 60 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Polyfill the bare minimum of WP we touch. Brain Monkey takes care of
// most function stubbing, but a few things need to exist as real types or
// callables before any test loads.
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in for unit tests that don't boot WordPress.
	 */
	class WP_Error {
		public function __construct(
			private string|int $code = '',
			private string $message = '',
			private mixed $data = null
		) {}
		public function get_error_code(): string|int {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Minimal WP_User stand-in for unit tests. Real WP_User has dozens of
	 * methods we don't touch in unit tests; the public ID property + a
	 * settable user_login is enough surface for the resolve_user tests.
	 */
	class WP_User { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Polyfilling WP core class.
		public int $ID         = 0; // phpcs:ignore Squiz.NamingConventions.ValidVariableName -- WP core class uses uppercase ID.
		public string $user_login = '';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stand-in. Real WP_REST_Request supports
	 * headers, files, attributes, and a half-dozen other affordances —
	 * tests only need to ferry a params bag and let the handler under
	 * test pull values out by name.
	 */
	class WP_REST_Request { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Polyfilling WP core class.
		/**
		 * @param array<string,mixed> $params
		 */
		public function __construct( private array $params = array() ) {}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function has_param( string $key ): bool {
			return array_key_exists( $key, $this->params );
		}

		/**
		 * @return array<string,mixed>
		 */
		public function get_params(): array {
			return $this->params;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stand-in. Just stores data + status so
	 * tests can assert on the response shape.
	 */
	class WP_REST_Response { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Polyfilling WP core class.
		public function __construct(
			private mixed $data = null,
			private int $status = 200,
		) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

// Translation helpers used inside class files. The real WP versions live in
// wp-includes/l10n.php; we provide pass-through stand-ins so unit tests can
// exercise code paths that call __() / esc_html__() without bringing in WP.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Polyfilling WP core function.
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Polyfilling WP core function.
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Polyfilling WP core function.
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Polyfilling WP core function.
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Polyfill, not booting WP.
	}
}
