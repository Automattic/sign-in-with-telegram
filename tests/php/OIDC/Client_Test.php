<?php
/**
 * Unit tests for Automattic\Telegram\SignIn\Client.
 *
 * @package Automattic\Telegram\SignIn\Tests\OIDC
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn\Tests\OIDC;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Automattic\Telegram\SignIn\Client;
use Automattic\Telegram\SignIn\Config;
use Automattic\Telegram\SignIn\OIDC_Exception;
use WP_Error;

/**
 * Brain Monkey-stubbed coverage of the OIDC client. We don't boot WordPress
 * here; every wp_remote_*, get_transient, set_transient, etc. call is faked.
 */
final class Client_Test extends TestCase {

	private const DISCOVERY_URL = 'https://oauth.example.test/.well-known/openid-configuration';

	private const VALID_DISCOVERY = array(
		'issuer'                 => 'https://oauth.example.test',
		'authorization_endpoint' => 'https://oauth.example.test/auth',
		'token_endpoint'         => 'https://oauth.example.test/token',
		'jwks_uri'               => 'https://oauth.example.test/jwks',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Defaults so each test only stubs what it cares about. These are simple
		// Patchwork stubs (Functions\when), not Mockery expectations — they
		// don't track calls, but they don't need to: per-test assertions check
		// behavior via the public API (return values, exceptions, wp_remote_get
		// call counts) rather than the cache layer.
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function client(): Client {
		return new Client(
			new Config(
				client_id:     '123456789',
				client_secret: 'secret',
				redirect_uri:  'https://example.test/wp-login.php?action=telegram_signin_callback',
				discovery_url: self::DISCOVERY_URL,
			)
		);
	}

	/**
	 * Build a fake wp_remote_* response array (status + body).
	 *
	 * @param int   $status HTTP status code.
	 * @param mixed $body   Response body — array gets JSON-encoded; strings pass through.
	 *
	 * @return array<string,mixed>
	 */
	private static function http_response( int $status, mixed $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
		);
	}

	private static function is_discovery_url( string $url ): bool {
		return str_ends_with( $url, '.well-known/openid-configuration' );
	}

	public function test_get_discovery_returns_cached_value_without_hitting_network(): void {
		Functions\when( 'get_transient' )->alias(
			static fn( $key ) => Client::DISCOVERY_TRANSIENT === $key ? self::VALID_DISCOVERY : false
		);
		Functions\expect( 'wp_remote_get' )->never();

		$discovery = $this->client()->get_discovery();

		$this->assertSame( self::VALID_DISCOVERY, $discovery );
	}

	public function test_get_discovery_fetches_when_transient_is_empty_and_caches_in_process(): void {
		// One wp_remote_get call total even though we ask for the discovery
		// twice — the second call hits the per-instance discovery_cache.
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( self::DISCOVERY_URL, array( 'timeout' => Client::HTTP_TIMEOUT ) )
			->andReturn( self::http_response( 200, self::VALID_DISCOVERY ) );

		$client = $this->client();
		$first  = $client->get_discovery();
		$second = $client->get_discovery();

		$this->assertSame( self::VALID_DISCOVERY, $first );
		$this->assertSame( $first, $second );
	}

	public function test_get_discovery_throws_provider_unreachable_on_wp_error(): void {
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'connection refused' ) );

		try {
			$this->client()->get_discovery();
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::PROVIDER_UNREACHABLE, $e->get_failure_code() );
		}
	}

	public function test_get_discovery_throws_discovery_invalid_when_required_field_missing(): void {
		$incomplete = self::VALID_DISCOVERY;
		unset( $incomplete['jwks_uri'] );

		Functions\when( 'wp_remote_get' )->justReturn( self::http_response( 200, $incomplete ) );

		try {
			$this->client()->get_discovery();
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::DISCOVERY_INVALID, $e->get_failure_code() );
		}
	}

	public function test_build_authorize_url_includes_required_params_and_default_scopes(): void {
		Functions\when( 'wp_remote_get' )->justReturn( self::http_response( 200, self::VALID_DISCOVERY ) );

		$url = $this->client()->build_authorize_url(
			state:          'state-token',
			nonce:          'nonce-token',
			code_challenge: 'challenge-token',
			scopes:         array( 'phone' )
		);

		$this->assertStringStartsWith( 'https://oauth.example.test/auth?', $url );

		$query = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( '123456789', $query['client_id'] );
		$this->assertSame( 'https://example.test/wp-login.php?action=telegram_signin_callback', $query['redirect_uri'] );
		$this->assertSame( 'state-token', $query['state'] );
		$this->assertSame( 'nonce-token', $query['nonce'] );
		$this->assertSame( 'challenge-token', $query['code_challenge'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );

		$scope_set = explode( ' ', $query['scope'] );
		$this->assertContains( 'openid', $scope_set );
		$this->assertContains( 'profile', $scope_set );
		$this->assertContains( 'phone', $scope_set );
	}

	public function test_exchange_code_returns_decoded_token_response(): void {
		Functions\when( 'wp_remote_get' )->justReturn( self::http_response( 200, self::VALID_DISCOVERY ) );

		$token_response = array(
			'id_token'     => 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.payload.signature',
			'access_token' => 'access-xyz',
			'token_type'   => 'Bearer',
			'expires_in'   => 3600,
		);

		$expected_basic = 'Basic ' . base64_encode( '123456789:secret' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://oauth.example.test/token',
				\Mockery::on(
					static function ( $args ) use ( $expected_basic ): bool {
						if ( $args['headers']['Authorization'] !== $expected_basic ) {
							return false;
						}
						$body = $args['body'];
						return 'authorization_code' === $body['grant_type']
							&& 'auth-code' === $body['code']
							&& 'verifier' === $body['code_verifier']
							&& 'https://example.test/wp-login.php?action=telegram_signin_callback' === $body['redirect_uri'];
					}
				)
			)
			->andReturn( self::http_response( 200, $token_response ) );

		$result = $this->client()->exchange_code( 'auth-code', 'verifier' );

		$this->assertSame( $token_response, $result );
	}

	public function test_exchange_code_throws_token_invalid_on_non_2xx(): void {
		Functions\when( 'wp_remote_get' )->justReturn( self::http_response( 200, self::VALID_DISCOVERY ) );
		Functions\when( 'wp_remote_post' )->justReturn( self::http_response( 400, '{"error":"invalid_grant"}' ) );

		try {
			$this->client()->exchange_code( 'auth-code', 'verifier' );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
		}
	}

	public function test_exchange_code_throws_token_invalid_on_malformed_json(): void {
		Functions\when( 'wp_remote_get' )->justReturn( self::http_response( 200, self::VALID_DISCOVERY ) );
		Functions\when( 'wp_remote_post' )->justReturn( self::http_response( 200, 'not json' ) );

		try {
			$this->client()->exchange_code( 'auth-code', 'verifier' );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
		}
	}

	public function test_get_jwks_caches_and_returns_keys(): void {
		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url ) {
				if ( self::is_discovery_url( $url ) ) {
					return self::http_response( 200, self::VALID_DISCOVERY );
				}
				return self::http_response(
					200,
					array(
						'keys' => array(
							array(
								'kid' => 'oidc-1',
								'kty' => 'RSA',
								'alg' => 'RS256',
							),
						),
					)
				);
			}
		);

		$jwks = $this->client()->get_jwks();

		$this->assertArrayHasKey( 'keys', $jwks );
		$this->assertCount( 1, $jwks['keys'] );
		$this->assertSame( 'oidc-1', $jwks['keys'][0]['kid'] );
	}

	public function test_get_jwks_throws_when_keys_missing(): void {
		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url ) {
				if ( self::is_discovery_url( $url ) ) {
					return self::http_response( 200, self::VALID_DISCOVERY );
				}
				return self::http_response( 200, array( 'foo' => 'bar' ) );
			}
		);

		try {
			$this->client()->get_jwks();
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::PROVIDER_UNREACHABLE, $e->get_failure_code() );
		}
	}

	public function test_refresh_jwks_preserves_cached_keyset_when_refetch_fails(): void {
		$old_keyset = array( 'keys' => array( array( 'kid' => 'old-kid' ) ) );

		// Cache returns the old keyset. The JWKS HTTP call fails on refresh.
		Functions\when( 'get_transient' )->alias(
			static fn( $key ) => Client::JWKS_TRANSIENT === $key ? $old_keyset : false
		);

		// set_transient must NOT be called with anything other than the discovery
		// doc — the failed refresh should leave the JWKS cache untouched.
		$jwks_transient_writes = 0;
		Functions\when( 'set_transient' )->alias(
			static function ( string $key ) use ( &$jwks_transient_writes ) {
				if ( Client::JWKS_TRANSIENT === $key ) {
					++$jwks_transient_writes;
				}
				return true;
			}
		);

		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url ) {
				if ( self::is_discovery_url( $url ) ) {
					return self::http_response( 200, self::VALID_DISCOVERY );
				}
				return new WP_Error( 'http_request_failed', 'connection refused' );
			}
		);

		// The refresh itself should bubble up the network failure.
		try {
			$this->client()->refresh_jwks();
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::PROVIDER_UNREACHABLE, $e->get_failure_code() );
		}

		// And critically, the cache wasn't touched, so a subsequent get_jwks()
		// still returns the old keyset.
		$this->assertSame( 0, $jwks_transient_writes, 'A failed refresh must not overwrite the JWKS cache.' );
		$this->assertSame( $old_keyset, $this->client()->get_jwks() );
	}
}
