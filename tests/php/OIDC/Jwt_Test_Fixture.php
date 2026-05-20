<?php
/**
 * RSA-keypair + JWT fixture helper for Token_Validator_Test.
 *
 * @package Automattic\Telegram\SignIn\Tests\OIDC
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn\Tests\OIDC;

use Firebase\JWT\JWT;

/**
 * Generates a fresh RSA keypair per test process and provides:
 *  - a JWKS document containing the public key, parameterized by `kid`
 *  - a sign() helper that produces a signed JWT with the given header overrides
 *
 * Tests instantiate this once (typically in setUp) and use it to mint
 * fixture tokens with whatever claims/header values they need to exercise
 * the Token_Validator branches.
 */
final class Jwt_Test_Fixture {

	/**
	 * @var resource|\OpenSSLAsymmetricKey RSA private key resource.
	 */
	private mixed $private_key;

	/**
	 * @var array<string,mixed> RSA public key details (n, e, type, etc.) from openssl_pkey_get_details.
	 */
	private array $public_key_details;

	/**
	 * @param int $bits RSA key size. 2048 is fast enough for tests; bigger only slows them down.
	 */
	public function __construct( int $bits = 2048 ) {
		$config = array(
			'private_key_bits' => $bits,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);
		$key    = openssl_pkey_new( $config );
		if ( false === $key ) {
			throw new \RuntimeException( 'Could not generate RSA test keypair: ' . openssl_error_string() );
		}
		$this->private_key        = $key;
		$this->public_key_details = openssl_pkey_get_details( $key );
	}

	/**
	 * Return a JWKS document containing one public key with the given kid.
	 *
	 * @param string $kid           Key ID to assign to the JWK.
	 * @param bool   $include_use   Whether to include `use: "sig"` (Telegram's real JWKS omits it).
	 *
	 * @return array<string,mixed> JWKS document of shape `{ "keys": [ ... ] }`.
	 */
	public function jwks( string $kid, bool $include_use = false ): array {
		$rsa = $this->public_key_details['rsa'];
		$jwk = array(
			'kty' => 'RSA',
			'kid' => $kid,
			'alg' => 'RS256',
			'n'   => self::base64url_encode( $rsa['n'] ),
			'e'   => self::base64url_encode( $rsa['e'] ),
		);
		if ( $include_use ) {
			$jwk['use'] = 'sig';
		}
		return array( 'keys' => array( $jwk ) );
	}

	/**
	 * Sign a JWT with the fixture's private key.
	 *
	 * @param array<string,mixed>  $payload Claim set to encode as the JWT body.
	 * @param string               $kid     kid to put in the JWT header.
	 * @param string               $alg     Algorithm to use (e.g. 'RS256', 'HS256', 'none').
	 * @param array<string,mixed>  $header_overrides Extra header fields, or overrides for kid/alg.
	 *
	 * @return string Compact-serialized JWT.
	 */
	public function sign(
		array $payload,
		string $kid = 'test-kid',
		string $alg = 'RS256',
		array $header_overrides = array()
	): string {
		// firebase/php-jwt's encode signs with the alg requested. To produce
		// alg=none / alg=HS256 fixtures (for negative tests), we sign manually.
		if ( 'none' === $alg ) {
			$header  = array_merge( array( 'alg' => 'none', 'typ' => 'JWT', 'kid' => $kid ), $header_overrides );
			$h_b64   = self::base64url_encode( (string) wp_json_encode( $header ) );
			$p_b64   = self::base64url_encode( (string) wp_json_encode( $payload ) );
			return $h_b64 . '.' . $p_b64 . '.';
		}
		if ( 'HS256' === $alg ) {
			$header   = array_merge( array( 'alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid ), $header_overrides );
			$h_b64    = self::base64url_encode( (string) wp_json_encode( $header ) );
			$p_b64    = self::base64url_encode( (string) wp_json_encode( $payload ) );
			$signing  = $h_b64 . '.' . $p_b64;
			$sig      = hash_hmac( 'sha256', $signing, 'test-secret', true );
			$sig_b64  = self::base64url_encode( $sig );
			return $signing . '.' . $sig_b64;
		}

		$head = array_merge( array( 'kid' => $kid ), $header_overrides );
		return JWT::encode( $payload, $this->private_key, $alg, $head['kid'] ?? null, $head );
	}

	/**
	 * Sign a JWT with the *wrong* private key — used to test signature rejection.
	 *
	 * @param array<string,mixed> $payload Claim set.
	 * @param string              $kid     kid header value.
	 *
	 * @return string Compact-serialized JWT signed with a fresh, unrelated keypair.
	 */
	public function sign_with_wrong_key( array $payload, string $kid = 'test-kid' ): string {
		$other = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		if ( false === $other ) {
			throw new \RuntimeException( 'Could not generate alternate RSA keypair: ' . openssl_error_string() );
		}
		return JWT::encode( $payload, $other, 'RS256', $kid );
	}

	/**
	 * Build a default valid claim set, parameterized for the test.
	 *
	 * Tests typically take this and mutate one field to exercise a branch.
	 *
	 * @param string $issuer   Issuer to embed.
	 * @param string $audience Audience.
	 * @param string $sub      Subject.
	 * @param string $nonce    Nonce.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_claims(
		string $issuer = 'https://oauth.example.test',
		string $audience = '123456789',
		string $sub = 'tg-user-1',
		string $nonce = 'nonce-abc'
	): array {
		$now = time();
		return array(
			'iss'                => $issuer,
			'aud'                => $audience,
			'sub'                => $sub,
			'nonce'              => $nonce,
			'iat'                => $now,
			'exp'                => $now + 300,
			'preferred_username' => 'tg_user',
		);
	}

	/**
	 * URL-safe base64 encoding (RFC 7515 § 2 — JOSE Base64url).
	 *
	 * @param string $data Raw bytes to encode.
	 *
	 * @return string URL-safe base64 with no padding.
	 */
	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
