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
	 * Usermeta key storing the Telegram-supplied avatar URL.
	 *
	 * Read by Avatar_Provider's pre_get_avatar_data filter; written here when
	 * the id_token includes a `picture` claim.
	 */
	public const USERMETA_PICTURE_URL = 'telegram_auth_picture_url';

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

		// Link flow: the user is already authenticated; we just attached
		// (or refreshed) the mapping. Send them back to their profile with
		// a success flag the UI can render as an admin notice.
		if ( 'link' === $tx->intent ) {
			do_action(
				'telegram_auth_debug',
				'link_succeeded',
				array(
					'user_id' => $user->ID,
					'sub'     => $claims['sub'] ?? null,
				)
			);

			wp_safe_redirect( add_query_arg( 'telegram_auth_linked', '1', admin_url( 'profile.php' ) ) );
			exit;
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
	 * Login flow:
	 *  - Existing mapping → return that user.
	 *  - Currently logged in + no mapping → attach to current user (no dup).
	 *  - Anonymous + signups allowed → create.
	 *
	 * Link flow (intent === 'link', tx->user_id is the originating user):
	 *  - Sub already mapped to the SAME user → idempotent success.
	 *  - Sub mapped to a DIFFERENT user → `already_linked` (refuse to switch
	 *    sessions silently).
	 *  - No existing mapping → attach to the originating user.
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

		$existing = $this->find_user_by_sub( $sub );

		// Link flow runs through a stricter path so we never clobber an
		// existing mapping or silently switch the session to a different user.
		if ( 'link' === $tx->intent ) {
			return $this->resolve_link( $existing, $claims, $sub, $tx );
		}

		// 1. Already linked? Sign in (and refresh profile fields) as that user.
		if ( $existing instanceof WP_User ) {
			$this->update_profile_from_claims( $existing, $claims );
			return $existing;
		}

		// 2. The visitor is currently authenticated — attach the new sub to
		// them rather than creating a duplicate. Covers the "I'm logged in
		// but I clicked Sign in with Telegram" case, where we'd otherwise
		// spawn a second account and clobber the existing session.
		$current_id = get_current_user_id();
		if ( 0 !== $current_id ) {
			$current = get_user_by( 'id', $current_id );
			if ( $current instanceof WP_User ) {
				update_user_meta( $current_id, self::USERMETA_SUB, $sub );
				$this->update_profile_from_claims( $current, $claims );
				return $current;
			}
		}

		// 3. Sign-up.
		if ( ! $this->settings->allow_signups() ) {
			return new WP_Error( 'signup_disabled', __( 'Sign-up is disabled on this site.', 'telegram-auth' ) );
		}

		return $this->create_user_from_claims( $claims );
	}

	/**
	 * Drive the `intent === 'link'` branch of resolve_user.
	 *
	 * @param WP_User|null         $existing The user currently holding this sub mapping, if any.
	 * @param array<string,mixed>  $claims   Validated id_token claims.
	 * @param string               $sub      Telegram subject identifier.
	 * @param Consumed_Transaction $tx       Decoded transaction (carries the originating user id).
	 *
	 * @return WP_User|WP_Error
	 */
	private function resolve_link( ?WP_User $existing, array $claims, string $sub, Consumed_Transaction $tx ): WP_User|WP_Error {
		if ( null === $tx->user_id || 0 === $tx->user_id ) {
			return new WP_Error( 'wrong_intent', __( 'Account linking requires being signed in first.', 'telegram-auth' ) );
		}

		if ( $existing instanceof WP_User && $existing->ID !== $tx->user_id ) {
			return new WP_Error( 'already_linked', __( 'This Telegram account is already linked to a different user on this site.', 'telegram-auth' ) );
		}

		$user = get_user_by( 'id', $tx->user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'token_invalid', __( 'Could not load the user that started the link flow.', 'telegram-auth' ) );
		}

		// Idempotent — writing the same sub a second time is a no-op.
		update_user_meta( $tx->user_id, self::USERMETA_SUB, $sub );
		$this->update_profile_from_claims( $user, $claims );
		return $user;
	}

	/**
	 * Remove the Telegram link from a user.
	 *
	 * Wipes both the `sub` mapping and the cached avatar URL. Safe to call
	 * on a user that was never linked.
	 *
	 * @param int $user_id Target user.
	 */
	public function unlink( int $user_id ): void {
		delete_user_meta( $user_id, self::USERMETA_SUB );
		delete_user_meta( $user_id, self::USERMETA_PICTURE_URL );

		do_action(
			'telegram_auth_debug',
			'unlinked',
			array( 'user_id' => $user_id )
		);
	}

	/**
	 * Look up a WP user by Telegram `sub`.
	 *
	 * @param string $sub Subject identifier.
	 *
	 * @return WP_User|null
	 */
	private function find_user_by_sub( string $sub ): ?WP_User {
		$users = get_users(
			array(
				'meta_key'   => self::USERMETA_SUB, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded set, indexed via usermeta queries.
				'meta_value' => $sub,                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
				'number'     => 1,
				'fields'     => 'all',
			)
		);
		if ( empty( $users ) ) {
			return null;
		}
		$user = $users[0];
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Sync first/last/display name and the picture URL from claims.
	 *
	 * Only fills in fields the user hasn't already populated themselves —
	 * we avoid overwriting customizations on every sign-in. Picture URL is
	 * always refreshed (Telegram rotates the path; the latest one wins).
	 *
	 * @param WP_User             $user   Target user.
	 * @param array<string,mixed> $claims Validated id_token claims.
	 */
	private function update_profile_from_claims( WP_User $user, array $claims ): void {
		$updates = array();
		$name    = isset( $claims['name'] ) ? trim( (string) $claims['name'] ) : '';

		// Telegram's `name` is a single free-form display string. We don't
		// guess at first/last splits (different cultures order them
		// differently); we just use it as the display name and only when
		// the user hasn't customized theirs already.
		if ( '' !== $name && ( $user->display_name === $user->user_login || '' === (string) $user->display_name ) ) {
			$updates['display_name'] = $name;
		}

		if ( ! empty( $updates ) ) {
			$updates['ID'] = $user->ID;
			wp_update_user( $updates );
		}

		if ( ! empty( $claims['picture'] ) && is_string( $claims['picture'] ) ) {
			$picture = esc_url_raw( $claims['picture'] );
			if ( '' !== $picture ) {
				update_user_meta( $user->ID, self::USERMETA_PICTURE_URL, $picture );
			}
		}
	}

	/**
	 * Create a new WP user from validated id_token claims.
	 *
	 * Username generated defensively (never trusts `preferred_username`),
	 * email left empty (Telegram never supplies one and `wp_insert_user`
	 * accepts an empty `user_email`), role per Settings::get_default_role().
	 * First/last/display name and picture URL come from the `name` and
	 * `picture` claims when present.
	 *
	 * @param array<string,mixed> $claims Validated claim set.
	 *
	 * @return WP_User|WP_Error
	 */
	private function create_user_from_claims( array $claims ): WP_User|WP_Error {
		$sub      = (string) $claims['sub'];
		$username = $this->generate_unique_username( $sub );
		$name     = isset( $claims['name'] ) ? trim( (string) $claims['name'] ) : '';
		$args     = array(
			'user_login' => $username,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => '',
			'role'       => $this->settings->get_default_role(),
		);

		if ( '' !== $name ) {
			$args['display_name'] = $name;
			$args['nickname']     = $name;
		}

		$user_id = wp_insert_user( $args );
		if ( $user_id instanceof WP_Error ) {
			return $user_id;
		}

		update_user_meta( (int) $user_id, self::USERMETA_SUB, $sub );

		if ( ! empty( $claims['picture'] ) && is_string( $claims['picture'] ) ) {
			$picture = esc_url_raw( $claims['picture'] );
			if ( '' !== $picture ) {
				update_user_meta( (int) $user_id, self::USERMETA_PICTURE_URL, $picture );
			}
		}

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
		if ( in_array( $code, array( 'wrong_intent', 'signup_disabled', 'token_invalid', 'already_linked' ), true ) ) {
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
