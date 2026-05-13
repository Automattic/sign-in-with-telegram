<?php
/**
 * Unit tests for Telegram_Auth\OIDC\Token_Validator.
 *
 * @package Telegram_Auth\Tests\OIDC
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests\OIDC;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\OIDC\Client;
use Telegram_Auth\OIDC\Config;
use Telegram_Auth\OIDC\OIDC_Exception;
use Telegram_Auth\OIDC\Token_Validator;

require_once __DIR__ . '/Jwt_Test_Fixture.php';

/**
 * Brain Monkey-stubbed coverage of the OIDC id_token validator.
 *
 * Each branch of the validation contract gets one named test, per the plan:
 * RS256 happy path, alg=none / HS256 / missing-kid rejected, kid refresh +
 * fail-closed behavior, iss / aud (single + array + azp) checks, expired exp,
 * future iat (clock_skew), nonce mismatch, missing sub, JWKS without `use=sig`.
 */
final class Token_Validator_Test extends TestCase {

	private const ISSUER   = 'https://oauth.example.test';
	private const AUDIENCE = '123456789';
	private const NONCE    = 'nonce-abc';
	private const KID      = 'test-kid';

	private Jwt_Test_Fixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->fixture = new Jwt_Test_Fixture();

		// Sane defaults — individual tests override what they care about.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => $response['response']['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ) => $response['body'] ?? ''
		);
		// telegram_auth_debug do_action — Brain Monkey would otherwise warn.
		Functions\when( 'do_action' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Token_Validator wired to a real Client whose JWKS / discovery
	 * fetches return the supplied fixture data.
	 *
	 * @param array<string,mixed>      $jwks JWKS to return from `wp_remote_get` against the JWKS URI.
	 * @param array<string,mixed>|null $jwks_after_refresh If non-null, the JWKS returned on the SECOND call
	 *                                                    (simulates Telegram rotating keys between requests).
	 *
	 * @return Token_Validator
	 */
	private function validator( array $jwks, ?array $jwks_after_refresh = null ): Token_Validator {
		$discovery = array(
			'issuer'                 => self::ISSUER,
			'authorization_endpoint' => 'https://oauth.example.test/auth',
			'token_endpoint'         => 'https://oauth.example.test/token',
			'jwks_uri'               => 'https://oauth.example.test/jwks',
		);

		$call_count = 0;
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url ) use ( $discovery, $jwks, $jwks_after_refresh, &$call_count ) {
				++$call_count;
				if ( str_ends_with( $url, '.well-known/openid-configuration' ) ) {
					return self::http_response( 200, $discovery );
				}
				// JWKS URI. The first call returns $jwks; if we ever get a second
				// (only when refresh_jwks() is invoked), return $jwks_after_refresh.
				static $jwks_calls = 0;
				++$jwks_calls;
				if ( null !== $jwks_after_refresh && $jwks_calls > 1 ) {
					return self::http_response( 200, $jwks_after_refresh );
				}
				return self::http_response( 200, $jwks );
			}
		);

		$client = new Client(
			new Config(
				client_id:     self::AUDIENCE,
				client_secret: 'secret',
				redirect_uri:  'https://example.test/wp-login.php?action=telegram_auth_callback',
				discovery_url: 'https://oauth.example.test/.well-known/openid-configuration',
			)
		);

		return new Token_Validator( $client, self::ISSUER, self::AUDIENCE );
	}

	private static function http_response( int $status, mixed $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
		);
	}

	// 1. Happy path.
	public function test_rs256_signed_token_with_correct_claims_validates(): void {
		$token  = $this->fixture->sign( Jwt_Test_Fixture::default_claims(), self::KID );
		$claims = $this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );

		$this->assertSame( 'tg-user-1', $claims['sub'] );
		$this->assertSame( self::ISSUER, $claims['iss'] );
	}

	// 2. alg=none rejected.
	public function test_alg_none_is_rejected(): void {
		$token = $this->fixture->sign( Jwt_Test_Fixture::default_claims(), self::KID, 'none' );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
			$this->assertStringContainsString( 'alg must be RS256', $e->getMessage() );
		}
	}

	// 3. alg=HS256 (alg-confusion attempt) rejected.
	public function test_alg_hs256_is_rejected(): void {
		$token = $this->fixture->sign( Jwt_Test_Fixture::default_claims(), self::KID, 'HS256' );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
		}
	}

	// 4. Missing kid rejected.
	public function test_token_without_kid_is_rejected(): void {
		// firebase/php-jwt's encode insists on a kid; sign without one by going manual.
		$payload = Jwt_Test_Fixture::default_claims();
		$header  = array( 'alg' => 'RS256', 'typ' => 'JWT' ); // No kid.
		$h_b64   = Jwt_Test_Fixture::base64url_encode( (string) wp_json_encode( $header ) );
		$p_b64   = Jwt_Test_Fixture::base64url_encode( (string) wp_json_encode( $payload ) );
		$signing = $h_b64 . '.' . $p_b64;
		$ref     = new \ReflectionClass( $this->fixture );
		$prop    = $ref->getProperty( 'private_key' );
		$prop->setAccessible( true );
		$pk = $prop->getValue( $this->fixture );
		openssl_sign( $signing, $sig, $pk, OPENSSL_ALGO_SHA256 );
		$token = $signing . '.' . Jwt_Test_Fixture::base64url_encode( $sig );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
			$this->assertStringContainsString( 'missing kid', $e->getMessage() );
		}
	}

	// 5. Unknown kid + JWKS refresh succeeds + valid → accepted.
	public function test_unknown_kid_triggers_refresh_and_validates_when_new_jwks_has_the_key(): void {
		$token = $this->fixture->sign( Jwt_Test_Fixture::default_claims(), 'rotated-kid' );

		// Stale JWKS has a different kid; refreshed JWKS has the right one.
		$stale = $this->fixture->jwks( 'old-kid' );
		$fresh = $this->fixture->jwks( 'rotated-kid' );

		$claims = $this->validator( $stale, $fresh )->validate( $token, self::NONCE );
		$this->assertSame( 'tg-user-1', $claims['sub'] );
	}

	// 6. Unknown kid + refresh cooldown active → token_invalid (we already
	// have the freshly-cached JWKS; the kid genuinely isn't there).
	public function test_unknown_kid_during_refresh_cooldown_yields_token_invalid(): void {
		// Cooldown transient is set → no second refresh allowed.
		Functions\when( 'get_transient' )->alias(
			static fn( $key ) =>
				Token_Validator::REFRESH_LOCKOUT_TRANSIENT === $key ? true : false
		);

		$token = $this->fixture->sign( Jwt_Test_Fixture::default_claims(), 'unknown-kid' );

		try {
			$this->validator( $this->fixture->jwks( 'something-else' ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
			$this->assertStringContainsString( 'cooldown', $e->getMessage() );
		}
	}

	// 7. Wrong iss.
	public function test_wrong_iss_is_rejected(): void {
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['iss'] = 'https://attacker.example/';
		$token         = $this->fixture->sign( $claims, self::KID );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
			$this->assertStringContainsString( 'iss does not match', $e->getMessage() );
		}
	}

	// 8. Wrong aud.
	public function test_wrong_aud_is_rejected(): void {
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['aud'] = 'someone-else';
		$token         = $this->fixture->sign( $claims, self::KID );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
		}
	}

	// 9. aud is array but azp missing → rejected.
	public function test_array_aud_without_azp_is_rejected(): void {
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['aud'] = array( self::AUDIENCE, 'someone-else' );
		// No azp.
		$token = $this->fixture->sign( $claims, self::KID );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
			$this->assertStringContainsString( 'azp', $e->getMessage() );
		}
	}

	// 10. aud is array, azp matches → accepted.
	public function test_array_aud_with_matching_azp_is_accepted(): void {
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['aud'] = array( self::AUDIENCE, 'someone-else' );
		$claims['azp'] = self::AUDIENCE;
		$token         = $this->fixture->sign( $claims, self::KID );

		$result = $this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
		$this->assertSame( 'tg-user-1', $result['sub'] );
	}

	// 11. Expired exp (well past skew tolerance) → token_invalid.
	public function test_expired_exp_yields_token_invalid(): void {
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['iat'] = time() - 200; // Fresh enough that the iat check passes.
		$claims['exp'] = time() - 1200; // Expired ~20 minutes ago — past the 5-minute leeway.
		$token         = $this->fixture->sign( $claims, self::KID );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
		}
	}

	// 12. iat far enough in the future to exceed skew tolerance → clock_skew.
	public function test_future_iat_yields_clock_skew(): void {
		$now           = time();
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['iat'] = $now + 3600; // 1 hour ahead — well past the 5-minute tolerance.
		$claims['exp'] = $now + 7200;
		$claims['nbf'] = $now - 60; // Don't trip nbf path.
		$token         = $this->fixture->sign( $claims, self::KID );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::CLOCK_SKEW, $e->get_failure_code() );
		}
	}

	// 13. nbf far enough in the future to exceed leeway → clock_skew (firebase/php-jwt's BeforeValidException).
	public function test_future_nbf_yields_clock_skew(): void {
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['nbf'] = time() + 3600; // 1 hour ahead.
		$token         = $this->fixture->sign( $claims, self::KID );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::CLOCK_SKEW, $e->get_failure_code() );
		}
	}

	// 17. iat slightly off (within skew) → accepted. Guards against the prior
	// strict ±60s window that broke local dev with Docker-VM clock drift.
	public function test_iat_within_skew_tolerance_is_accepted(): void {
		$now           = time();
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['iat'] = $now + 120; // 2 minutes ahead — inside the 5-minute tolerance.
		$claims['exp'] = $now + 900;
		$token         = $this->fixture->sign( $claims, self::KID );

		$claims = $this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
		$this->assertSame( 'tg-user-1', $claims['sub'] );
	}

	// 18. exp slightly past (within leeway) → accepted via firebase/php-jwt leeway,
	// not rejected as token_invalid. Same dev-clock-drift concern as #17.
	public function test_exp_within_skew_tolerance_is_accepted(): void {
		$now           = time();
		$claims        = Jwt_Test_Fixture::default_claims();
		$claims['iat'] = $now - 200;
		$claims['exp'] = $now - 120; // Expired 2 minutes ago — inside the 5-minute leeway.
		$token         = $this->fixture->sign( $claims, self::KID );

		$claims = $this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
		$this->assertSame( 'tg-user-1', $claims['sub'] );
	}

	// 14. Wrong nonce.
	public function test_wrong_nonce_is_rejected(): void {
		$claims          = Jwt_Test_Fixture::default_claims();
		$claims['nonce'] = 'attacker-nonce';
		$token           = $this->fixture->sign( $claims, self::KID );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
			$this->assertStringContainsString( 'nonce', $e->getMessage() );
		}
	}

	// 15. Missing sub.
	public function test_missing_sub_is_rejected(): void {
		$claims = Jwt_Test_Fixture::default_claims();
		unset( $claims['sub'] );
		$token = $this->fixture->sign( $claims, self::KID );

		try {
			$this->validator( $this->fixture->jwks( self::KID ) )->validate( $token, self::NONCE );
			$this->fail( 'Expected OIDC_Exception.' );
		} catch ( OIDC_Exception $e ) {
			$this->assertSame( OIDC_Exception::TOKEN_INVALID, $e->get_failure_code() );
			$this->assertStringContainsString( 'sub', $e->getMessage() );
		}
	}

	// 16. JWKS key without `use=sig` field — Telegram's real JWKS omits it; must still validate.
	public function test_jwks_without_use_field_is_accepted(): void {
		$token  = $this->fixture->sign( Jwt_Test_Fixture::default_claims(), self::KID );
		$jwks   = $this->fixture->jwks( self::KID, include_use: false );
		$claims = $this->validator( $jwks )->validate( $token, self::NONCE );

		$this->assertSame( 'tg-user-1', $claims['sub'] );
	}
}
