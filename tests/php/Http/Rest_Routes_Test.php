<?php
/**
 * Unit tests for Telegram_Auth\Http\Rest_Routes.
 *
 * @package Telegram_Auth\Tests\Http
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\Http\Rest_Routes;
use Telegram_Auth\OIDC\OIDC_Exception;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Brain Monkey-stubbed coverage of the REST routes the plugin owns.
 *
 * The settings GET/POST routes are served by WP core's `/wp/v2/settings`
 * via `register_setting()` and aren't reimplemented in Rest_Routes; only
 * `/telegram-auth/v1/test-credentials` lives here.
 */
final class Rest_Routes_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				if ( is_array( $args ) ) {
					return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
				}
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $args . '=' . rawurlencode( (string) func_get_arg( 1 ) );
			}
		);
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'do_action' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_routes_on_rest_api_init(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'rest_api_init', \Mockery::type( 'array' ) );

		( new Rest_Routes() )->register();

		$this->addToAssertionCount( 1 );
	}

	public function test_register_routes_calls_register_rest_route_with_manage_options_gate(): void {
		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				Rest_Routes::NAMESPACE,
				'/test-credentials',
				\Mockery::on(
					static function ( $args ): bool {
						return is_array( $args )
							&& 'POST' === $args['methods']
							&& is_callable( $args['permission_callback'] )
							&& is_callable( $args['callback'] )
							&& isset( $args['args']['client_id'], $args['args']['client_secret'] );
					}
				)
			);

		( new Rest_Routes() )->register_routes();

		$this->addToAssertionCount( 1 );
	}

	public function test_permission_check_requires_manage_options(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn( string $cap ): bool => 'manage_options' === $cap
		);

		$this->assertTrue( ( new Rest_Routes() )->permission_check() );
	}

	public function test_permission_check_denies_when_capability_missing(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( ( new Rest_Routes() )->permission_check() );
	}

	/**
	 * Wire up the wp_remote_* stubs the probe path needs. `$post_response`
	 * is what wp_remote_post returns (the token-endpoint response we use
	 * to probe credentials); leave it as the default `invalid_grant / 400`
	 * shape to simulate authenticated-but-bogus-code (the success case
	 * from the probe's perspective).
	 *
	 * @param array<string,mixed>|\WP_Error $post_response Override the token-endpoint response.
	 */
	private function stub_http( array|\WP_Error $post_response = array(
		'response' => array( 'code' => 400 ),
		'body'     => '{"error":"invalid_grant"}',
	) ): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ) => is_array( $response ) ? ( $response['body'] ?? '' ) : ''
		);
		Functions\when( 'wp_remote_get' )->alias(
			static function () {
				$discovery = array(
					'issuer'                 => 'https://oauth.example.test',
					'authorization_endpoint' => 'https://oauth.example.test/auth',
					'token_endpoint'         => 'https://oauth.example.test/token',
					'jwks_uri'               => 'https://oauth.example.test/jwks',
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( $discovery ),
				);
			}
		);
		Functions\when( 'wp_remote_post' )->justReturn( $post_response );
	}

	public function test_test_credentials_returns_ok_when_probe_accepts_credentials(): void {
		// Token endpoint returns 400 invalid_grant — credentials authenticated,
		// the deliberately-bogus code was rejected. That's the success case.
		$this->stub_http();

		$response = ( new Rest_Routes() )->handle_test_credentials(
			new WP_REST_Request(
				array(
					'client_id'     => '123',
					'client_secret' => 'secret',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'ok' => true ), $response->get_data() );
	}

	public function test_test_credentials_returns_invalid_client_when_probe_rejects_credentials(): void {
		// Token endpoint returns 401 invalid_client — credentials are wrong.
		$this->stub_http(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"error":"invalid_client"}',
			)
		);

		$response = ( new Rest_Routes() )->handle_test_credentials(
			new WP_REST_Request(
				array(
					'client_id'     => 'wrong',
					'client_secret' => 'wrong',
				)
			)
		);

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'invalid_client', $data['reason'] );
	}

	public function test_test_credentials_returns_invalid_client_on_400_invalid_client_body(): void {
		// Some IdPs return 400 (not 401) with invalid_client in the body.
		// We pick that up via the body, not just the status.
		$this->stub_http(
			array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"error":"invalid_client"}',
			)
		);

		$response = ( new Rest_Routes() )->handle_test_credentials(
			new WP_REST_Request(
				array(
					'client_id'     => 'wrong',
					'client_secret' => 'wrong',
				)
			)
		);

		$this->assertSame( 'invalid_client', $response->get_data()['reason'] );
	}

	public function test_test_credentials_returns_failure_code_when_discovery_unreachable(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'connection refused' ) );

		$response = ( new Rest_Routes() )->handle_test_credentials(
			new WP_REST_Request(
				array(
					'client_id'     => '123',
					'client_secret' => 'secret',
				)
			)
		);

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( OIDC_Exception::PROVIDER_UNREACHABLE, $data['reason'] );
	}

	public function test_test_credentials_uses_posted_credentials_not_stored_ones(): void {
		$captured_get_option_keys = array();
		Functions\when( 'get_option' )->alias(
			function ( $key ) use ( &$captured_get_option_keys ) {
				$captured_get_option_keys[] = $key;
				return false;
			}
		);

		// Capture the Authorization header the probe sends so we can assert
		// the *posted* credentials made it onto the wire.
		$captured_auth = null;
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ) => is_array( $response ) ? ( $response['body'] ?? '' ) : ''
		);
		Functions\when( 'wp_remote_get' )->alias(
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode(
						array(
							'issuer'                 => 'https://oauth.example.test',
							'authorization_endpoint' => 'https://oauth.example.test/auth',
							'token_endpoint'         => 'https://oauth.example.test/token',
							'jwks_uri'               => 'https://oauth.example.test/jwks',
						)
					),
				);
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$captured_auth ) {
				$captured_auth = $args['headers']['Authorization'] ?? null;
				return array(
					'response' => array( 'code' => 400 ),
					'body'     => '{"error":"invalid_grant"}',
				);
			}
		);

		( new Rest_Routes() )->handle_test_credentials(
			new WP_REST_Request(
				array(
					'client_id'     => 'posted-id',
					'client_secret' => 'posted-secret',
				)
			)
		);

		$expected_auth = 'Basic ' . base64_encode( 'posted-id:posted-secret' );
		$this->assertSame( $expected_auth, $captured_auth );
		$this->assertNotContains( 'telegram_auth_settings', $captured_get_option_keys );
	}
}
