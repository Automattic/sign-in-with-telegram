<?php
/**
 * Drives the OIDC callback: token exchange → claim validation → user resolution → wp_set_auth_cookie.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Auth;

use Telegram_Auth\Admin\Settings;
use Telegram_Auth\Http\Failure_Renderer;
use Telegram_Auth\OIDC\Client;
use Telegram_Auth\OIDC\OIDC_Exception;
use Telegram_Auth\OIDC\Token_Validator;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

// Exception messages thrown below are escaped at the call sites; failure
// codes passed as the second exception arg are programmatic identifiers.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Handles `?action=telegram_auth_callback` requests on `wp-login.php`.
 *
 * Flow:
 *  1. Telegram returns to us with `code` + `state` (or `error` on cancel).
 *  2. We consume the matching transaction via state, exchange the code for
 *     tokens via the OIDC client, validate the id_token, and resolve the
 *     `sub` claim to a WP user.
 *  3. wp_set_auth_cookie + wp_safe_redirect to the post-login URL.
 *
 * For A4 only the `intent === 'login'` branch is implemented:
 *   - Existing `telegram_auth_sub` mapping → log that user in.
 *   - No mapping + signups allowed → wp_insert_user with default role.
 *   - No mapping + signups disabled → fail with `signup_disabled`.
 *
 * `intent === 'link'` is reserved for A6 and currently fails closed with
 * `wrong_intent` so a stray /callback in link mode doesn't silently behave
 * like a login.
 */
class Login_Handler {

	/**
	 * Usermeta key storing the Telegram OIDC `sub` claim per WP user.
	 */
	public const USERMETA_SUB = 'telegram_auth_sub';

	/**
	 * Build the handler.
	 *
	 * @param Settings         $settings         Plugin settings (credentials + redirect URI).
	 * @param Transaction      $transaction      Transaction store for consume().
	 * @param Failure_Renderer $failure_renderer Maps thrown errors to login redirects.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Transaction $transaction,
		private readonly Failure_Renderer $failure_renderer,
	) {}

	/**
	 * Entry point invoked by Endpoints when `?action=telegram_auth_callback`.
	 *
	 * @param array<string,mixed> $get The request's `$_GET` (caller passes it raw; we sanitize here).
	 *
	 * @return never On success or failure: redirects + exits.
	 */
	public function handle( array $get ): void {
		try {
			$this->run( $get );
		} catch ( Transaction_Exception $e ) {
			$this->log_failure( 'transaction_failed', $e );
			$this->failure_renderer->render_throwable( $e );
		} catch ( OIDC_Exception $e ) {
			$this->log_failure( 'oidc_failed', $e );
			$this->failure_renderer->render_throwable( $e );
		}
	}

	/**
	 * The actual callback flow, separated so handle() can wrap in try/catch.
	 *
	 * @param array<string,mixed> $get $_GET payload.
	 *
	 * @return never
	 *
	 * @throws OIDC_Exception When the token response is malformed; other exceptions pass through from consume()/exchange_code()/validate().
	 */
	private function run( array $get ): void {
		// Telegram signals a user-side cancel via ?error=access_denied (or similar).
		if ( ! empty( $get['error'] ) ) {
			$this->failure_renderer->render_code( 'cancelled' );
		}

		$code  = isset( $get['code'] ) ? sanitize_text_field( wp_unslash( (string) $get['code'] ) ) : '';
		$state = isset( $get['state'] ) ? sanitize_text_field( wp_unslash( (string) $get['state'] ) ) : '';

		if ( '' === $code || '' === $state ) {
			$this->failure_renderer->render_code( OIDC_Exception::TOKEN_INVALID );
		}

		// Consume first; releases the transient + cookie regardless of what comes next.
		$tx = $this->transaction->consume( $state );

		$config = $this->settings->build_oidc_config();
		if ( null === $config ) {
			$this->failure_renderer->render_code( 'not_configured' );
		}

		$client    = new Client( $config );
		$discovery = $client->get_discovery();

		$tokens = $client->exchange_code( $code, $tx->code_verifier );
		if ( empty( $tokens['id_token'] ) || ! is_string( $tokens['id_token'] ) ) {
			throw new OIDC_Exception(
				esc_html__( 'Token response is missing id_token.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID
			);
		}

		$validator = new Token_Validator( $client, (string) $discovery['issuer'], $config->client_id );
		$claims    = $validator->validate( (string) $tokens['id_token'], $tx->nonce );

		$user = $this->resolve_user( $claims, $tx );
		if ( $user instanceof WP_Error ) {
			$this->failure_renderer->render_code( $this->code_for_user_error( $user ) );
		}

		wp_set_auth_cookie( $user->ID, false );

		do_action(
			'telegram_auth_debug',
			'login_succeeded',
			array(
				'user_id' => $user->ID,
				'sub'     => $claims['sub'] ?? null,
			)
		);

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Resolve a Telegram `sub` claim to a WP_User, creating one if appropriate.
	 *
	 * For A4 only the `intent === 'login'` branch is implemented; `'link'`
	 * fails closed pending A6.
	 *
	 * @param array<string,mixed>  $claims Validated id_token claims.
	 * @param Consumed_Transaction $tx     Decoded transaction (for intent + originating user_id).
	 *
	 * @return WP_User|WP_Error The user to sign in, or a WP_Error keyed by failure code.
	 */
	public function resolve_user( array $claims, Consumed_Transaction $tx ): WP_User|WP_Error {
		$sub = isset( $claims['sub'] ) ? (string) $claims['sub'] : '';
		if ( '' === $sub ) {
			return new WP_Error( 'token_invalid', __( 'Token is missing the sub claim.', 'telegram-auth' ) );
		}

		$existing = get_users(
			array(
				'meta_key'   => self::USERMETA_SUB, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded set, indexed via usermeta queries.
				'meta_value' => $sub,                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
				'number'     => 1,
				'fields'     => 'all',
			)
		);
		if ( ! empty( $existing ) ) {
			$user = $existing[0];
			return $user instanceof WP_User ? $user : new WP_Error( 'token_invalid', __( 'Unexpected user lookup result.', 'telegram-auth' ) );
		}

		// link flow not yet implemented; A6 will resolve $tx->user_id + AccountLinker.
		if ( 'login' !== $tx->intent ) {
			return new WP_Error( 'wrong_intent', __( 'Account linking is not yet supported.', 'telegram-auth' ) );
		}

		if ( ! $this->settings->allow_signups() ) {
			return new WP_Error( 'signup_disabled', __( 'Sign-up is disabled on this site.', 'telegram-auth' ) );
		}

		return $this->create_user_from_claims( $claims );
	}

	/**
	 * Create a new WP user from validated id_token claims.
	 *
	 * Username generated defensively (never trusts `preferred_username`),
	 * email left empty (Telegram never supplies one and `wp_insert_user`
	 * accepts an empty `user_email`), role per Settings::get_default_role().
	 *
	 * @param array<string,mixed> $claims Validated claim set.
	 *
	 * @return WP_User|WP_Error
	 */
	private function create_user_from_claims( array $claims ): WP_User|WP_Error {
		$sub      = (string) $claims['sub'];
		$username = $this->generate_unique_username( $sub );

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => '',
				'role'       => $this->settings->get_default_role(),
			)
		);

		if ( $user_id instanceof WP_Error ) {
			return $user_id;
		}

		update_user_meta( (int) $user_id, self::USERMETA_SUB, $sub );

		$user = get_user_by( 'id', $user_id );
		return $user instanceof WP_User
			? $user
			: new WP_Error( 'token_invalid', __( 'Could not load freshly-created user.', 'telegram-auth' ) );
	}

	/**
	 * Generate a unique WP username from the Telegram `sub`.
	 *
	 * Format: `tg_<first 12 chars of sha256(sub)>`. Falls back to numeric
	 * suffix if a collision somehow exists. Never trusts `preferred_username`.
	 *
	 * @param string $sub Telegram subject identifier.
	 *
	 * @return string A username that's available for wp_insert_user.
	 */
	private function generate_unique_username( string $sub ): string {
		$base = 'tg_' . substr( hash( 'sha256', $sub ), 0, 12 );
		if ( ! username_exists( $base ) ) {
			return $base;
		}
		for ( $i = 2; $i < 1000; $i++ ) {
			$candidate = $base . '_' . $i;
			if ( ! username_exists( $candidate ) ) {
				return $candidate;
			}
		}
		// Extreme edge case; just append a few random hex chars.
		return $base . '_' . bin2hex( \random_bytes( 4 ) );
	}

	/**
	 * Map a WP_Error (from resolve_user) to one of our failure codes.
	 *
	 * @param WP_Error $error Error returned by resolve_user.
	 *
	 * @return string Stable failure-code identifier.
	 */
	private function code_for_user_error( WP_Error $error ): string {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'wrong_intent', 'signup_disabled', 'token_invalid' ), true ) ) {
			return (string) $code;
		}
		return OIDC_Exception::TOKEN_INVALID;
	}

	/**
	 * Emit a debug action with sensitive fields scrubbed.
	 *
	 * @param string     $event Stable event identifier.
	 * @param \Throwable $error Original exception.
	 */
	private function log_failure( string $event, \Throwable $error ): void {
		do_action(
			'telegram_auth_debug',
			$event,
			array(
				'failure_code' => method_exists( $error, 'get_failure_code' ) ? $error->get_failure_code() : null,
				'message'      => $error->getMessage(),
			)
		);
	}
}
