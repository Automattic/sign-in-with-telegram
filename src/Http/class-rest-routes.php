<?php
/**
 * REST routes specific to Telegram Auth.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Http;

use Telegram_Auth\Admin\Settings;
use Telegram_Auth\OIDC\Client;
use Telegram_Auth\OIDC\Config;
use Telegram_Auth\OIDC\OIDC_Exception;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the small set of plugin-owned REST routes.
 *
 * The settings option itself is exposed through WordPress core's
 * `/wp/v2/settings` endpoint via `register_setting()` — we don't duplicate
 * that here. This class only adds endpoints WP core can't give us; today
 * that's just `POST /telegram-auth/v1/test-credentials`, which validates
 * a tentative credentials pair against Telegram's discovery + JWKS so an
 * admin can verify the values before committing them to the option.
 */
class Rest_Routes {

	/**
	 * REST namespace for our plugin-owned routes.
	 */
	public const NAMESPACE = 'telegram-auth/v1';

	/**
	 * Hook REST registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Wire up the routes. Called on `rest_api_init`.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/test-credentials',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission_check' ),
				'callback'            => array( $this, 'handle_test_credentials' ),
				'args'                => array(
					'client_id'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'client_secret' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Cap-check shared by every route this controller owns.
	 *
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Validate a tentative credentials pair against Telegram's OIDC provider.
	 *
	 * Builds a temporary OIDC Client from the *posted* `client_id` /
	 * `client_secret` (not the stored values) so the admin can verify a new
	 * pair before saving it. We deliberately call `get_discovery()` followed
	 * by `get_jwks()` — both pull from the JWKS-rotation cache when
	 * available, which makes this cheap to call repeatedly.
	 *
	 * The response shape is deliberately tiny:
	 *   { ok: true } on success
	 *   { ok: false, reason: <failure_code> } on any OIDC_Exception
	 *
	 * Exception messages are NOT echoed to the client — the failure_code
	 * is the only public surface, mirroring the wp-login.php error-code
	 * pattern. The full message gets a `telegram_auth_debug` action firing
	 * for admin-side log capture.
	 *
	 * @param WP_REST_Request $request Posted credentials.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_test_credentials( WP_REST_Request $request ): WP_REST_Response {
		$config = new Config(
			client_id:     (string) $request->get_param( 'client_id' ),
			client_secret: (string) $request->get_param( 'client_secret' ),
			// The redirect URI doesn't matter for discovery/JWKS lookup; we
			// just need a syntactically valid one so Config's constructor is
			// happy.
			redirect_uri:  add_query_arg( 'action', 'telegram_auth_callback', wp_login_url() ),
		);

		$client = new Client( $config );

		try {
			$client->get_discovery();
			$client->get_jwks();
		} catch ( OIDC_Exception $e ) {
			do_action(
				'telegram_auth_debug',
				'test_credentials_failed',
				array(
					'failure_code' => $e->get_failure_code(),
					'message'      => $e->getMessage(),
				)
			);
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => $e->get_failure_code(),
				)
			);
		}

		return new WP_REST_Response( array( 'ok' => true ) );
	}
}
