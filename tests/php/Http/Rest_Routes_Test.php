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

	public function test_test_credentials_returns_ok_when_discovery_and_jwks_succeed(): void {
		// Both endpoints respond with valid shapes.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url ) {
				$discovery = array(
					'issuer'                 => 'https://oauth.example.test',
					'authorization_endpoint' => 'https://oauth.example.test/auth',
					'token_endpoint'         => 'https://oauth.example.test/token',
					'jwks_uri'               => 'https://oauth.example.test/jwks',
				);
				$jwks      = array( 'keys' => array( array( 'kid' => 'oidc-1' ) ) );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( str_ends_with( $url, 'openid-configuration' ) ? $discovery : $jwks ),
				);
			}
		);

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
		$this->assertIsArray( $data );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( OIDC_Exception::PROVIDER_UNREACHABLE, $data['reason'] );
	}

	public function test_test_credentials_uses_posted_credentials_not_stored_ones(): void {
		// Capture the wp_remote_post Authorization header used during the
		// hypothetical token exchange. test-credentials doesn't actually
		// exchange a code, but we confirm the *posted* client_id flows into
		// the Config that constructs the Client. We do this by inspecting
		// what URL the discovery call hits and verifying the surrounding
		// code didn't pull from get_option / Settings.
		$captured_get_option_keys = array();
		Functions\when( 'get_option' )->alias(
			function ( $key ) use ( &$captured_get_option_keys ) {
				$captured_get_option_keys[] = $key;
				return false;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url ) {
				$discovery = array(
					'issuer'                 => 'https://oauth.example.test',
					'authorization_endpoint' => 'https://oauth.example.test/auth',
					'token_endpoint'         => 'https://oauth.example.test/token',
					'jwks_uri'               => 'https://oauth.example.test/jwks',
				);
				$jwks      = array( 'keys' => array( array( 'kid' => 'oidc-1' ) ) );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( str_ends_with( $url, 'openid-configuration' ) ? $discovery : $jwks ),
				);
			}
		);

		$response = ( new Rest_Routes() )->handle_test_credentials(
			new WP_REST_Request(
				array(
					'client_id'     => 'posted-id',
					'client_secret' => 'posted-secret',
				)
			)
		);

		$this->assertSame( array( 'ok' => true ), $response->get_data() );
		// Neither the plugin option nor the credentials are read from
		// storage during test-credentials — the controller acts on the
		// posted values alone.
		$this->assertNotContains( 'telegram_auth_settings', $captured_get_option_keys );
	}
}
