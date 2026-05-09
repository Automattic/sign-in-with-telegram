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

		wp_safe_redirect( $url );
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
