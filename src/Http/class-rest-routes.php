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
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the small set of plugin-owned REST routes.
 *
 * The settings option itself is exposed through WordPress core's
 * `/wp/v2/settings` endpoint via `register_setting()` — we don't duplicate
 * that here. This class only adds endpoints WP core can't give us; today
 * that's just `POST /telegram-auth/v1/test-credentials`, which validates
 * a tentative client_id / client_secret pair against Telegram's token
 * endpoint so an admin can verify the values before committing them to
 * the option.
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
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'permission_check' ),
				'callback'            => array( $this, 'handle_test_credentials' ),
				'args'                => array(
					'client_id'     => array(
						'type'     => 'string',
						'required' => true,
						'format'   => 'text-field',
					),
					'client_secret' => array(
						'type'     => 'string',
						'required' => true,
						'format'   => 'text-field',
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
	 * `client_secret` (not the stored values), then calls
	 * `Client::probe_credentials()` which sends a deliberately-bogus
	 * authorization-code grant to the token endpoint and distinguishes
	 * `invalid_client` (creds wrong) from `invalid_grant` (creds fine,
	 * code bad). Discovery is exercised as a side-effect since the probe
	 * needs the token endpoint URL.
	 *
	 * Response shape:
	 *   { ok: true } when credentials authenticate.
	 *   { ok: false, reason: 'invalid_client' } when Telegram rejects them.
	 *   { ok: false, reason: <failure_code> } on any OIDC_Exception
	 *     (network / discovery failure). Exception messages are NOT echoed
	 *     to the client — only stable failure codes surface, with the full
	 *     detail captured server-side via `telegram_auth_debug`.
	 *
	 * @param WP_REST_Request $request Posted credentials.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_test_credentials( WP_REST_Request $request ): WP_REST_Response {
		$config = new Config(
			client_id:     (string) $request->get_param( 'client_id' ),
			client_secret: (string) $request->get_param( 'client_secret' ),
			// The redirect URI doesn't matter for the probe; we just need a
			// syntactically valid one so Config's constructor is happy.
			redirect_uri:  add_query_arg( 'action', 'telegram_auth_callback', wp_login_url() ),
		);

		$client = new Client( $config );

		try {
			$authenticated = $client->probe_credentials();
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

		if ( ! $authenticated ) {
			do_action( 'telegram_auth_debug', 'test_credentials_rejected', array() );
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => 'invalid_client',
				)
			);
		}

		return new WP_REST_Response( array( 'ok' => true ) );
	}
}
