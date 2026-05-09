<?php
/**
 * Routes wp-login.php?action=telegram_auth_* to the right auth handler.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Http;

use Telegram_Auth\Admin\Settings;
use Telegram_Auth\Auth\Login_Handler;
use Telegram_Auth\Auth\Transaction;
use Telegram_Auth\Auth\Transaction_Exception;
use Telegram_Auth\OIDC\Client;
use Telegram_Auth\OIDC\OIDC_Exception;

defined( 'ABSPATH' ) || exit;

// See header comment in Login_Handler — same rationale.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Dispatcher for the three plugin actions on wp-login.php:
 *  - `telegram_auth_start`    — kicks off the login flow (redirects to Telegram).
 *  - `telegram_auth_link`     — kicks off the link flow (logged-in user only).
 *  - `telegram_auth_callback` — Telegram returns here; delegated to Login_Handler.
 *
 * We hook `login_init` (not `init`) so we run on wp-login.php specifically
 * and we deliberately avoid `admin-post.php` because many membership/LMS
 * plugins gate `/wp-admin/` for non-admin users.
 */
class Endpoints {

	/**
	 * Action values we handle.
	 */
	public const ACTION_START    = 'telegram_auth_start';
	public const ACTION_CALLBACK = 'telegram_auth_callback';
	public const ACTION_LINK     = 'telegram_auth_link';
	public const ACTION_UNLINK   = 'telegram_auth_unlink';

	/**
	 * Build the endpoints dispatcher.
	 *
	 * @param Settings         $settings         Reads OIDC credentials + redirect URI.
	 * @param Transaction      $transaction      Issues + consumes the state/nonce/PKCE bundle.
	 * @param Login_Handler    $login_handler    Drives the callback flow.
	 * @param Failure_Renderer $failure_renderer Maps thrown errors to login redirects.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Transaction $transaction,
		private readonly Login_Handler $login_handler,
		private readonly Failure_Renderer $failure_renderer,
	) {}

	/**
	 * Hook the dispatcher into login_init.
	 */
	public function register(): void {
		add_action( 'login_init', array( $this, 'dispatch' ) );
	}

	/**
	 * Inspect $_GET['action'] and route to the matching handler.
	 *
	 * @return void
	 */
	public function dispatch(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is checked per-action below; see handle_start.
		if ( ! isset( $_GET['action'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same.
		$action = sanitize_key( wp_unslash( (string) $_GET['action'] ) );

		try {
			switch ( $action ) {
				case self::ACTION_START:
					$this->handle_start( 'login', null );
					break;
				case self::ACTION_LINK:
					$this->handle_start( 'link', $this->require_logged_in_user_id() );
					break;
				case self::ACTION_UNLINK:
					$this->handle_unlink();
					break;
				case self::ACTION_CALLBACK:
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Telegram-supplied state acts as CSRF token; verified by Transaction::consume().
					$this->login_handler->handle( $_GET );
					break;
				default:
					return;
			}
		} catch ( Transaction_Exception $e ) {
			$this->failure_renderer->render_throwable( $e );
		} catch ( OIDC_Exception $e ) {
			$this->failure_renderer->render_throwable( $e );
		}
	}

	/**
	 * Build the authorize URL and redirect to Telegram.
	 *
	 * @param string   $intent  'login' or 'link'.
	 * @param int|null $user_id Current user id when intent === 'link'.
	 *
	 * @return never
	 *
	 * @throws OIDC_Exception When discovery can't be fetched.
	 */
	private function handle_start( string $intent, ?int $user_id ): void {
		$this->verify_start_nonce( $intent );

		$config = $this->settings->build_oidc_config();
		if ( null === $config ) {
			$this->failure_renderer->render_code( 'not_configured' );
		}

		$client  = new Client( $config );
		$started = $this->transaction->start( $intent, $user_id );

		$url = $client->build_authorize_url(
			state:          $started->state,
			nonce:          $started->nonce,
			code_challenge: $started->code_challenge,
		);

		do_action( 'telegram_auth_debug', 'authorize_redirect', array( 'intent' => $intent ) );

		// wp_redirect (not wp_safe_redirect) — we deliberately leave-site to
		// the IdP. wp_safe_redirect rewrites cross-host targets to the
		// fallback URL unless the host is allow-listed via the
		// allowed_redirect_hosts filter; we don't need that protection
		// because $url comes from our own discovery-doc fetch, not user
		// input.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- See comment above.
		exit;
	}

	/**
	 * Verify the WP nonce that protects the start/link buttons.
	 *
	 * @param string $intent 'login' or 'link'. Different nonce action per flow.
	 *
	 * @return never on failure (renders an error and exits); void on success.
	 */
	private function verify_start_nonce( string $intent ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified two lines below.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		$valid = wp_verify_nonce( $nonce, 'login' === $intent ? self::ACTION_START : self::ACTION_LINK );
		if ( false === $valid ) {
			$this->failure_renderer->render_code( Transaction_Exception::STATE_INVALID );
		}
	}

	/**
	 * Run the unlink action: clear the stored sub + avatar URL for the
	 * targeted user, then bounce back to where the request came from with a
	 * `telegram_auth_unlinked=1` flag the profile UI can show as a notice.
	 *
	 * Targets `?user_id=` when present (admin viewing someone else's
	 * profile) and falls back to the current user. Authorization is gated
	 * by `current_user_can( 'edit_user', $target )` so a non-admin can only
	 * disconnect their own account.
	 *
	 * @return never
	 */
	private function handle_unlink(): void {
		$current_id = $this->require_logged_in_user_id();
		$target_id  = $this->resolve_unlink_target( $current_id );

		$this->verify_unlink_nonce( $target_id );

		if ( ! current_user_can( 'edit_user', $target_id ) ) {
			$this->failure_renderer->render_code( 'wrong_intent' );
		}

		$this->login_handler->unlink( $target_id );

		$redirect = add_query_arg(
			'telegram_auth_unlinked',
			'1',
			$this->resolve_post_unlink_redirect()
		);
		// Same-site only — wp_safe_redirect rewrites cross-host targets to
		// the fallback URL, which is exactly what we want for an unlink that
		// originates from our own profile UI.
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Pick the unlink target user. Falls back to the current user when no
	 * `?user_id=` is supplied.
	 *
	 * @param int $current_id Current user id (already known to be > 0).
	 *
	 * @return int Target user id.
	 */
	private function resolve_unlink_target( int $current_id ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by verify_unlink_nonce immediately after; absint clamps to a safe int regardless.
		$raw = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		return 0 !== $raw ? $raw : $current_id;
	}

	/**
	 * Verify the WP nonce protecting the unlink link.
	 *
	 * The nonce action is scoped to the target user id so a nonce captured
	 * for one profile can't be replayed against another.
	 *
	 * @param int $target_id User id the unlink is acting on.
	 *
	 * @return never on failure (renders an error and exits); void on success.
	 */
	private function verify_unlink_nonce( int $target_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified two lines below.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( false === wp_verify_nonce( $nonce, self::ACTION_UNLINK . '_' . $target_id ) ) {
			$this->failure_renderer->render_code( Transaction_Exception::STATE_INVALID );
		}
	}

	/**
	 * Pick the post-unlink redirect target. Honors `?redirect_to=` when present
	 * (filtered through esc_url_raw + same-site enforcement by wp_safe_redirect),
	 * otherwise falls back to the user's profile screen.
	 *
	 * @return string Absolute URL.
	 */
	private function resolve_post_unlink_redirect(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; redirect target is sanitized below and constrained to same-host by wp_safe_redirect.
		$raw = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ) : '';
		return '' !== $raw ? $raw : admin_url( 'profile.php' );
	}

	/**
	 * Resolve the current user id, requiring an authenticated session.
	 *
	 * The link flow is meaningless for anonymous visitors — surface
	 * `wrong_intent` rather than starting a transaction whose result we
	 * couldn't apply. Renders a redirect + exits when no user is logged in.
	 *
	 * @return int Current user id.
	 */
	private function require_logged_in_user_id(): int {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			$this->failure_renderer->render_code( 'wrong_intent' );
		}
		return $user_id;
	}
}
