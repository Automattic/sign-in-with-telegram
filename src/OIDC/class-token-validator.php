<?php
/**
 * OIDC id_token validator.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\OIDC;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;

defined( 'ABSPATH' ) || exit;

// Exception messages thrown below are escaped at the call sites
// (esc_html__() format strings, esc_html() on dynamic args). The
// failure-code constants passed as the second constructor arg are
// programmatic identifiers, not user data, so the WPCS exception-output
// sniff is suppressed for the file.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Validates Telegram-issued id_tokens with explicit, conservative checks.
 *
 * Telegram's discovery doc only advertises RS256, so we hard-pin the allowed
 * algorithm rather than letting firebase/php-jwt's defaults negotiate. We
 * also do every claim check ourselves rather than trusting library defaults
 * — Telegram's profile is small and explicit checks are cheaper than auditing
 * what the library does on each version bump.
 *
 * Throws OIDC_Exception with one of three failure codes:
 *  - TOKEN_INVALID: signature, claim, or kid resolution failed.
 *  - PROVIDER_UNREACHABLE: JWKS refresh failed and the cache didn't have a usable key.
 *  - CLOCK_SKEW: iat / exp / nbf outside ±SKEW_TOLERANCE_SECONDS.
 */
class Token_Validator {

	/**
	 * Acceptable difference between `iat` (and exp/nbf via firebase/php-jwt's
	 * leeway) and current server time, in seconds.
	 */
	public const SKEW_TOLERANCE_SECONDS = 60;

	/**
	 * Transient key for the JWKS-refresh rate-limit lockout.
	 *
	 * When a JWKS refresh has just been triggered, this transient is set
	 * for SKEW_TOLERANCE_SECONDS to prevent stampedes against the JWKS
	 * endpoint if multiple bad-`kid` tokens arrive in quick succession.
	 */
	public const REFRESH_LOCKOUT_TRANSIENT = 'telegram_auth_jwks_refresh_lockout';

	/**
	 * Build the validator.
	 *
	 * @param Client $client            OIDC client used to fetch (and refresh) the JWKS.
	 * @param string $expected_issuer   Issuer the id_token must declare. Use Telegram's discovery `issuer` value.
	 * @param string $expected_audience Audience the id_token must declare. Equal to the OIDC client_id.
	 */
	public function __construct(
		private readonly Client $client,
		private readonly string $expected_issuer,
		private readonly string $expected_audience,
	) {}

	/**
	 * Validate a Telegram-issued id_token end-to-end.
	 *
	 * Returns the decoded claim set on success. On any validation failure,
	 * throws OIDC_Exception. The caller is expected to translate the
	 * failure_code into the public-facing error param (see plan).
	 *
	 * @param string $id_token       Compact-serialized JWT received from Telegram's token endpoint.
	 * @param string $expected_nonce Nonce stored at /authorize time, must match the token's claim.
	 *
	 * @return array<string,mixed> Decoded claim set.
	 *
	 * @throws OIDC_Exception When the token can't be validated for any reason.
	 */
	public function validate( string $id_token, string $expected_nonce ): array {
		$header = $this->parse_header( $id_token );
		$this->assert_alg_rs256( $header );
		$kid = $this->require_kid( $header );

		$key = $this->resolve_key( $kid );

		try {
			JWT::$leeway = 0;
			$payload     = JWT::decode( $id_token, $key );
		} catch ( BeforeValidException $e ) {
			throw new OIDC_Exception(
				esc_html__( 'Token nbf is in the future.', 'telegram-auth' ),
				OIDC_Exception::CLOCK_SKEW,
				$e
			);
		} catch ( ExpiredException $e ) {
			throw new OIDC_Exception(
				esc_html__( 'Token has expired.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID,
				$e
			);
		} catch ( SignatureInvalidException $e ) {
			throw new OIDC_Exception(
				esc_html__( 'Token signature did not verify.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID,
				$e
			);
		} catch ( \UnexpectedValueException $e ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: %s: error from the JWT library. */
					esc_html__( 'Token decoding failed: %s', 'telegram-auth' ),
					esc_html( $e->getMessage() )
				),
				OIDC_Exception::TOKEN_INVALID,
				$e
			);
		}

		$claims = (array) $payload;
		$this->assert_required_claims( $claims );
		$this->assert_issuer( $claims );
		$this->assert_audience( $claims );
		$this->assert_iat_within_skew( $claims );
		$this->assert_nonce( $claims, $expected_nonce );

		do_action(
			'telegram_auth_debug',
			'token_validated',
			array(
				'sub' => $claims['sub'] ?? null,
				'kid' => $kid,
			)
		);

		return $claims;
	}

	/**
	 * Decode the JWT header without trusting the library's claim parsing.
	 *
	 * @param string $id_token Compact-serialized JWT.
	 *
	 * @return array<string,mixed> Decoded header.
	 *
	 * @throws OIDC_Exception When the token isn't a 3-part JWT or the header isn't valid JSON.
	 */
	private function parse_header( string $id_token ): array {
		$parts = explode( '.', $id_token );
		if ( 3 !== count( $parts ) ) {
			throw new OIDC_Exception(
				esc_html__( 'Token is not a 3-segment JWT.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID
			);
		}

		$decoded = json_decode( JWT::urlsafeB64Decode( $parts[0] ), true );
		if ( ! is_array( $decoded ) ) {
			throw new OIDC_Exception(
				esc_html__( 'Token header is not valid JSON.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID
			);
		}

		return $decoded;
	}

	/**
	 * Reject anything other than alg=RS256.
	 *
	 * @param array<string,mixed> $header Decoded JWT header.
	 *
	 * @throws OIDC_Exception When alg is missing or not RS256.
	 */
	private function assert_alg_rs256( array $header ): void {
		if ( ! isset( $header['alg'] ) || 'RS256' !== $header['alg'] ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: %s: actual alg value from the token header (or "missing"). */
					esc_html__( 'Token alg must be RS256, got %s.', 'telegram-auth' ),
					esc_html( (string) ( $header['alg'] ?? 'missing' ) )
				),
				OIDC_Exception::TOKEN_INVALID
			);
		}
	}

	/**
	 * Extract a non-empty `kid` from the JWT header.
	 *
	 * @param array<string,mixed> $header Decoded JWT header.
	 *
	 * @return string The kid.
	 *
	 * @throws OIDC_Exception When kid is missing or empty.
	 */
	private function require_kid( array $header ): string {
		if ( empty( $header['kid'] ) || ! is_string( $header['kid'] ) ) {
			throw new OIDC_Exception(
				esc_html__( 'Token header missing kid.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID
			);
		}
		return $header['kid'];
	}

	/**
	 * Look up the JWK for `kid` in the cached JWKS, refreshing once if missing.
	 *
	 * @param string $kid Key ID from the JWT header.
	 *
	 * @return \Firebase\JWT\Key The key bound to RS256.
	 *
	 * @throws OIDC_Exception When the kid can't be found even after a (rate-limited) refresh.
	 */
	private function resolve_key( string $kid ) {
		$jwks = $this->client->get_jwks();
		$keys = JWK::parseKeySet( $jwks );

		if ( isset( $keys[ $kid ] ) ) {
			return $keys[ $kid ];
		}

		// Unknown kid — try a (rate-limited) refresh.
		if ( get_transient( self::REFRESH_LOCKOUT_TRANSIENT ) ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: %s: kid value from the JWT header. */
					esc_html__( 'JWKS refresh rate-limited; key for kid %s not available.', 'telegram-auth' ),
					esc_html( $kid )
				),
				OIDC_Exception::PROVIDER_UNREACHABLE
			);
		}

		set_transient( self::REFRESH_LOCKOUT_TRANSIENT, true, self::SKEW_TOLERANCE_SECONDS );

		try {
			$jwks = $this->client->refresh_jwks();
		} catch ( OIDC_Exception $e ) {
			throw new OIDC_Exception(
				esc_html__( 'JWKS refresh failed and no usable key in cache.', 'telegram-auth' ),
				OIDC_Exception::PROVIDER_UNREACHABLE,
				$e
			);
		}

		$keys = JWK::parseKeySet( $jwks );
		if ( isset( $keys[ $kid ] ) ) {
			return $keys[ $kid ];
		}

		throw new OIDC_Exception(
			sprintf(
				/* translators: %s: kid value from the JWT header. */
				esc_html__( 'JWKS does not contain a key for kid %s after refresh.', 'telegram-auth' ),
				esc_html( $kid )
			),
			OIDC_Exception::TOKEN_INVALID
		);
	}

	/**
	 * Assert every required claim is present.
	 *
	 * @param array<string,mixed> $claims Decoded claim set.
	 *
	 * @throws OIDC_Exception When a required claim is missing.
	 */
	private function assert_required_claims( array $claims ): void {
		$required = array( 'iss', 'aud', 'exp', 'iat', 'nonce', 'sub' );
		foreach ( $required as $claim ) {
			if ( ! array_key_exists( $claim, $claims ) ) {
				throw new OIDC_Exception(
					sprintf(
						/* translators: %s: claim name. */
						esc_html__( 'Token missing required claim "%s".', 'telegram-auth' ),
						esc_html( $claim )
					),
					OIDC_Exception::TOKEN_INVALID
				);
			}
		}
	}

	/**
	 * Assert iss matches what we expect from the discovery doc.
	 *
	 * @param array<string,mixed> $claims Decoded claim set.
	 *
	 * @throws OIDC_Exception When iss doesn't match.
	 */
	private function assert_issuer( array $claims ): void {
		if ( $claims['iss'] !== $this->expected_issuer ) {
			throw new OIDC_Exception(
				esc_html__( 'Token iss does not match expected issuer.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID
			);
		}
	}

	/**
	 * Assert aud contains our client_id.
	 *
	 * If aud is an array (multi-audience token), also validate azp per the
	 * OIDC core spec § 3.1.3.7: the recipient (us) must be the authorized
	 * party.
	 *
	 * @param array<string,mixed> $claims Decoded claim set.
	 *
	 * @throws OIDC_Exception When aud doesn't match or azp is missing/wrong.
	 */
	private function assert_audience( array $claims ): void {
		$aud = $claims['aud'];

		if ( is_array( $aud ) ) {
			if ( ! in_array( $this->expected_audience, $aud, true ) ) {
				throw new OIDC_Exception(
					esc_html__( 'Token aud does not contain expected audience.', 'telegram-auth' ),
					OIDC_Exception::TOKEN_INVALID
				);
			}
			if ( ! isset( $claims['azp'] ) || $claims['azp'] !== $this->expected_audience ) {
				throw new OIDC_Exception(
					esc_html__( 'Token aud is multi-valued but azp does not name us.', 'telegram-auth' ),
					OIDC_Exception::TOKEN_INVALID
				);
			}
			return;
		}

		if ( $aud !== $this->expected_audience ) {
			throw new OIDC_Exception(
				esc_html__( 'Token aud does not match expected audience.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID
			);
		}
	}

	/**
	 * Assert iat is within ±SKEW_TOLERANCE_SECONDS of current server time.
	 *
	 * Firebase/php-jwt only checks exp/nbf. The library's leeway is set to 0
	 * (we manage skew here, explicitly), and we additionally guard against
	 * absurd-future iat values that some misbehaving providers can produce.
	 *
	 * @param array<string,mixed> $claims Decoded claim set.
	 *
	 * @throws OIDC_Exception When iat is outside the tolerance.
	 */
	private function assert_iat_within_skew( array $claims ): void {
		$iat = (int) $claims['iat'];
		$now = time();
		if ( abs( $now - $iat ) > self::SKEW_TOLERANCE_SECONDS ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: 1: iat value from the token. 2: server time. 3: tolerance in seconds. */
					esc_html__( 'Token iat (%1$d) is outside server-time skew tolerance ±%3$d seconds (server: %2$d).', 'telegram-auth' ),
					$iat,
					$now,
					(int) self::SKEW_TOLERANCE_SECONDS
				),
				OIDC_Exception::CLOCK_SKEW
			);
		}
	}

	/**
	 * Assert the token's nonce matches what the caller stored at /authorize time.
	 *
	 * @param array<string,mixed> $claims         Decoded claim set.
	 * @param string              $expected_nonce Stored nonce.
	 *
	 * @throws OIDC_Exception When nonce doesn't match.
	 */
	private function assert_nonce( array $claims, string $expected_nonce ): void {
		if ( $claims['nonce'] !== $expected_nonce ) {
			throw new OIDC_Exception(
				esc_html__( 'Token nonce does not match stored nonce.', 'telegram-auth' ),
				OIDC_Exception::TOKEN_INVALID
			);
		}
	}
}
