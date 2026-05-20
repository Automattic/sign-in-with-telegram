<?php
/**
 * Unit tests for Automattic\Telegram\SignIn\Endpoints.
 *
 * @package Automattic\Telegram\SignIn\Tests\Http
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn\Tests\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Automattic\Telegram\SignIn\Settings;
use Automattic\Telegram\SignIn\Login_Handler;
use Automattic\Telegram\SignIn\Started_Transaction;
use Automattic\Telegram\SignIn\Transaction;
use Automattic\Telegram\SignIn\Endpoints;
use Automattic\Telegram\SignIn\Failure_Renderer;
use Automattic\Telegram\SignIn\Client;
use WP_Error;

/**
 * Brain Monkey-stubbed coverage of endpoint dispatch behavior.
 */
final class Endpoints_Test extends TestCase {

	/**
	 * Valid OIDC discovery document fixture.
	 *
	 * @var array<string,string>
	 */
	private const VALID_DISCOVERY = array(
		'issuer'                 => 'https://oauth.example.test',
		'authorization_endpoint' => 'https://oauth.example.test/auth',
		'token_endpoint'         => 'https://oauth.example.test/token',
		'jwks_uri'               => 'https://oauth.example.test/jwks',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_GET = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $value ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'rest_sanitize_boolean' )->alias(
			static function ( $value ): bool {
				if ( is_bool( $value ) ) {
					return $value;
				}
				if ( is_string( $value ) ) {
					return ! in_array( strtolower( $value ), array( '', '0', 'false' ), true );
				}
				return (bool) $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => match ( $key ) {
				Settings::OPTION_KEY => array(
					'client_id'     => '123456789',
					'client_secret' => 'secret',
					'request_phone' => true,
					'request_dm'    => true,
				),
				'default_role'       => 'subscriber',
				default              => $default,
			}
		);
		Functions\when( 'wp_roles' )->justReturn(
			(object) array(
				'roles' => array(
					'subscriber' => array( 'name' => 'Subscriber' ),
				),
			)
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = null ): string {
				if ( is_array( $key ) ) {
					$args = $key;
					$url  = (string) $value;
				} else {
					$args = array( $key => $value );
					$url  = (string) $url;
				}
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof WP_Error
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => $response['response']['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ) => $response['body'] ?? ''
		);
		Functions\when( 'wp_remote_get' )->justReturn( self::http_response( 200, self::VALID_DISCOVERY ) );
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_start_passes_requested_optional_scopes_to_transaction_and_authorize_url(): void {
		$_GET = array(
			'action'   => Endpoints::ACTION_START,
			'_wpnonce' => 'nonce-fixture',
		);

		$transaction = $this->getMockBuilder( Transaction::class )->disableOriginalConstructor()->onlyMethods( array( 'start' ) )->getMock();
		$transaction
			->expects( $this->once() )
			->method( 'start' )
			->with( 'login', null, '', array( 'phone', 'telegram:bot_access' ) )
			->willReturn( new Started_Transaction( 'state-token', 'nonce-token', 'challenge-token' ) );

		$login_handler = $this->getMockBuilder( Login_Handler::class )->disableOriginalConstructor()->getMock();

		$redirect = null;
		Functions\when( 'wp_redirect' )->alias(
			function ( string $url ) use ( &$redirect ) {
				$redirect = $url;
				throw new Redirect_Captured( $url );
			}
		);

		try {
			( new Endpoints( new Settings(), $transaction, $login_handler, new Failure_Renderer() ) )->dispatch();
			$this->fail( 'Expected Redirect_Captured.' );
		} catch ( Redirect_Captured ) {
			$this->assertIsString( $redirect );
		}

		$query = array();
		parse_str( (string) parse_url( (string) $redirect, PHP_URL_QUERY ), $query );

		$scope_set = explode( ' ', (string) $query['scope'] );
		$this->assertContains( 'openid', $scope_set );
		$this->assertContains( 'profile', $scope_set );
		$this->assertContains( 'phone', $scope_set );
		$this->assertContains( 'telegram:bot_access', $scope_set );
	}

	/**
	 * Build a fake wp_remote_* response.
	 *
	 * @param int   $status HTTP status.
	 * @param mixed $body   Response body.
	 *
	 * @return array<string,mixed>
	 */
	private static function http_response( int $status, mixed $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
		);
	}
}

/**
 * Test-only exception used to short-circuit wp_redirect → exit.
 */
final class Redirect_Captured extends \RuntimeException {
	public function __construct( public readonly string $url ) {
		parent::__construct( $url );
	}
}
