<?php
/**
 * Sign-in-with-Telegram button: shortcode + login_form integration.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\UI;

use Telegram_Auth\Admin\Settings;
use Telegram_Auth\Http\Endpoints;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the user-facing affordance that kicks off the OIDC login flow.
 *
 * Two surfaces, one shared markup helper:
 *  - `[telegram_auth_button]` shortcode for inserting into pages.
 *  - `login_form` action for printing on `wp-login.php` after the password field.
 *
 * Both produce a link to `wp-login.php?action=telegram_auth_start&_wpnonce=…`
 * with an optional `telegram_auth_redirect_to` carrying the post-login URL.
 * The block (Phase 3) renders the same URL via the same helper.
 */
class Login_Button {

	/**
	 * Build the renderer.
	 *
	 * @param Settings $settings Used for the default redirect target.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Hook the shortcode + login_form integration.
	 */
	public function register(): void {
		add_shortcode( 'telegram_auth_button', array( $this, 'render_shortcode' ) );
		add_action( 'login_form', array( $this, 'render_on_login_form' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'print_login_form_styles' ) );
	}

	/**
	 * Render the button as a shortcode return value.
	 *
	 * @param array<string,mixed>|string $attrs   Shortcode attributes.
	 * @param string|null                $content Inner content (unused, kept for shortcode-callback signature compatibility).
	 *
	 * @return string Rendered HTML.
	 */
	public function render_shortcode( $attrs = array(), $content = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $content is part of the shortcode-callback contract.
		$resolved = shortcode_atts(
			array(
				'label'       => $this->settings->get_button_label(),
				'redirect_to' => $this->settings->get_post_login_redirect(),
			),
			is_array( $attrs ) ? $attrs : array(),
			'telegram_auth_button'
		);

		// Sanitize at the boundary: label is text (HTML-escaped on render),
		// redirect_to is a URL (esc_url_raw filters down to known-safe schemes).
		$label       = sanitize_text_field( (string) $resolved['label'] );
		$redirect_to = esc_url_raw( (string) $resolved['redirect_to'] );

		return $this->render( $label, $redirect_to );
	}

	/**
	 * Print the button on wp-login.php below the password field.
	 *
	 * Gated by the `telegram_auth_show_on_login_form` filter (default true).
	 */
	public function render_on_login_form(): void {
		/**
		 * Filter whether the Telegram sign-in button auto-renders on wp-login.php.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $show True to print, false to suppress.
		 */
		if ( ! apply_filters( 'telegram_auth_show_on_login_form', true ) ) {
			return;
		}
		echo '<p class="telegram-auth-login-form-button">';
		echo $this->render( $this->settings->get_button_label(), $this->settings->get_post_login_redirect() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes its own output.
		echo '</p>';
	}

	/**
	 * Print a small stylesheet on wp-login.php so the Telegram button gets
	 * breathing room between the password field and the submit row.
	 */
	public function print_login_form_styles(): void {
		echo '<style id="telegram-auth-login-form-button-styles">'
			. '#login form p.telegram-auth-login-form-button{margin-top:1em;margin-bottom:1em;}'
			. '#login form .telegram-auth-login-form-button .telegram-auth-login-button{display:inline-flex;align-items:center;gap:0.25em;}'
			. '</style>';
	}

	/**
	 * Build the URL that kicks off the OIDC login flow.
	 *
	 * @param string|null $redirect_to Optional post-login URL. Empty/null
	 *                                 means the caller didn't request a custom target.
	 *
	 * @return string Fully-formed start URL with WP nonce.
	 */
	public function get_start_url( ?string $redirect_to = null ): string {
		$args = array(
			'action'   => Endpoints::ACTION_START,
			'_wpnonce' => wp_create_nonce( Endpoints::ACTION_START ),
		);
		// Defense in depth: sanitize even when callers pass an already-clean URL.
		$safe_redirect = esc_url_raw( (string) ( $redirect_to ?? '' ) );
		if ( '' !== $safe_redirect ) {
			$args['telegram_auth_redirect_to'] = $safe_redirect;
		}
		return (string) add_query_arg( $args, wp_login_url() );
	}

	/**
	 * Render the button HTML.
	 *
	 * @param string $label       Human-readable button text.
	 * @param string $redirect_to Optional post-login URL.
	 *
	 * @return string Escaped HTML.
	 */
	public function render( string $label, string $redirect_to ): string {
		$url   = $this->get_start_url( '' === $redirect_to ? null : $redirect_to );
		$icon  = self::paper_plane_svg();
		$label = trim( $label );

		return sprintf(
			'<a href="%1$s" class="button button-secondary telegram-auth-login-button" aria-label="%2$s">%3$s&nbsp;<span class="telegram-auth-login-button__label">%4$s</span></a>',
			esc_url( $url ),
			esc_attr( $label ),
			$icon,
			esc_html( $label )
		);
	}

	/**
	 * Inline Telegram-style paper-plane SVG. Stamped with `aria-hidden`
	 * because the surrounding `<a>` already carries the accessible name.
	 *
	 * @return string Pre-escaped SVG markup.
	 */
	private static function paper_plane_svg(): string {
		// SVGs default to `vertical-align: baseline`, which sits the icon
		// above the text x-height. Force `middle` so the icon aligns
		// against the label regardless of whether the surrounding
		// anchor is inline-block (default WP button) or inline-flex.
		return '<svg class="telegram-auth-login-button__icon" style="vertical-align:middle" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M21.426 2.574 2.39 10.434c-.84.34-.85 1.518-.014 1.872l4.668 1.984 1.808 5.802c.232.745 1.16.97 1.7.408l2.61-2.713 4.78 3.512c.706.519 1.71.142 1.91-.722l3.43-15.04c.21-.928-.69-1.732-1.564-1.36-.144.06-.28.123-.292.12Zm-3.16 4.42-7.84 7.06-1.022 4.05-1.184-3.802 7.834-7.054c.37-.333.92.13.586.546l-.004.004c-.27.34-1.91 2.226-3.296 3.806l-.32-.286 4.992-4.494c.094-.084.218.04.124.124l-.04.024.018.018c-.084.094-.06-.04.152.004Z"/></svg>';
	}
}
