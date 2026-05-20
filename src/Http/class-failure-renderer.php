<?php
/**
 * Renders auth-pipeline failures as login-page redirects + login_errors notices.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

use Automattic\Telegram\SignIn\Transaction_Exception;
use Automattic\Telegram\SignIn\OIDC_Exception;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Two responsibilities:
 *  1. From a thrown exception or a `WP_Error`, redirect the user to
 *     `wp-login.php?telegram_signin_error=<code>` (so the public-facing error
 *     surface stays generic and consistent) and exit.
 *  2. When the login page renders with that query param present, inject a
 *     translated message into `wp_login_errors` so the user sees something
 *     readable.
 */
class Failure_Renderer {

	/**
	 * Query-string param key for surfaced errors.
	 *
	 * Plugin-prefixed to avoid colliding with anything else that happens to
	 * touch `wp-login.php` (Telegram's own widget plugin, custom themes, etc.).
	 */
	public const ERROR_QUERY_PARAM = 'telegram_signin_error';

	/**
	 * Maximum length of the failure-code value we'll accept from the URL.
	 *
	 * Defends against absurdly long inputs being passed through to the
	 * sanitize/match pipeline; legitimate codes are short identifiers.
	 */
	private const MAX_CODE_LENGTH = 32;

	/**
	 * Hook the login_errors filter so query-param errors render readable copy.
	 */
	public function register(): void {
		add_filter( 'wp_login_errors', array( $this, 'inject_login_error' ) );
	}

	/**
	 * Map a thrown exception to a stable failure code and redirect.
	 *
	 * @param \Throwable $error Thrown by Transaction::consume(), Client / Token_Validator, or Login_Handler::resolve_user.
	 *
	 * @return never
	 */
	public function render_throwable( \Throwable $error ): void {
		$code = $this->code_for_throwable( $error );
		$this->render_code( $code );
	}

	/**
	 * Redirect to wp-login.php with the failure code attached, then exit.
	 *
	 * @param string $code One of the codes returned by code_for_throwable().
	 *
	 * @return never
	 */
	public function render_code( string $code ): void {
		$url = add_query_arg( self::ERROR_QUERY_PARAM, $code, wp_login_url() );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Extract the failure code from a Throwable.
	 *
	 * @param \Throwable $error Exception or error.
	 *
	 * @return string Stable failure-code identifier.
	 */
	public function code_for_throwable( \Throwable $error ): string {
		if ( $error instanceof Transaction_Exception ) {
			return $error->get_failure_code();
		}
		if ( $error instanceof OIDC_Exception ) {
			return $error->get_failure_code();
		}
		return 'unknown_error';
	}

	/**
	 * Inject a readable error message into the login screen when the URL
	 * carries our failure-code query param.
	 *
	 * @param WP_Error|mixed $errors The current login_errors instance.
	 *
	 * @return WP_Error|mixed Returned unchanged when no code is present;
	 *                       otherwise augmented with our error string.
	 */
	public function inject_login_error( $errors ) {
		if ( ! ( $errors instanceof WP_Error ) ) {
			$errors = new WP_Error();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of an error code in the URL; no state change.
		$raw = isset( $_GET[ self::ERROR_QUERY_PARAM ] )
			? sanitize_key( wp_unslash( $_GET[ self::ERROR_QUERY_PARAM ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $raw || strlen( $raw ) > self::MAX_CODE_LENGTH ) {
			return $errors;
		}

		$message = $this->message_for_code( $raw );
		$errors->add( 'telegram_signin_' . $raw, $message, 'error' );
		return $errors;
	}

	/**
	 * Translation table from failure-code → user-facing message.
	 *
	 * The copy is intentionally generic: we never echo exception messages
	 * verbatim (those go in admin debug logs), and we don't tell the user
	 * which specific check failed (avoids account enumeration).
	 *
	 * @param string $code Failure-code identifier.
	 *
	 * @return string Localized user-facing message.
	 */
	private function message_for_code( string $code ): string {
		return match ( $code ) {
			Transaction_Exception::STATE_INVALID => __( 'Login session was invalid or has already been used. Please try again.', 'sign-in-with-telegram' ),
			Transaction_Exception::STATE_EXPIRED => __( 'Your sign-in link timed out. Please try again.', 'sign-in-with-telegram' ),
			OIDC_Exception::PROVIDER_UNREACHABLE => __( 'Telegram could not be reached. Please try again.', 'sign-in-with-telegram' ),
			OIDC_Exception::TOKEN_INVALID        => __( 'Telegram returned an invalid response. Please try again.', 'sign-in-with-telegram' ),
			OIDC_Exception::DISCOVERY_INVALID    => __( 'Telegram returned an unexpected configuration. Please contact the site administrator.', 'sign-in-with-telegram' ),
			OIDC_Exception::CLOCK_SKEW           => __( 'Your sign-in attempt and our server are out of sync. Please check your device clock and try again.', 'sign-in-with-telegram' ),
			'cancelled'                          => __( 'Sign-in was canceled.', 'sign-in-with-telegram' ),
			'not_configured'                     => __( 'Telegram sign-in is not yet configured. Please contact the site administrator.', 'sign-in-with-telegram' ),
			'wrong_intent'                       => __( 'That link can only be used while signed in.', 'sign-in-with-telegram' ),
			'already_linked'                     => __( 'That Telegram account is already linked to a different user on this site.', 'sign-in-with-telegram' ),
			'signup_disabled'                    => __( 'New account creation is disabled. Sign in with an existing account first to link Telegram.', 'sign-in-with-telegram' ),
			default                              => __( 'Something went wrong with Telegram sign-in. Please try again.', 'sign-in-with-telegram' ),
		};
	}
}
