<?php
/**
 * Thin OIDC client tailored to Telegram's profile.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

defined( 'ABSPATH' ) || exit;

// Exception messages thrown below are escaped at the call sites
// (esc_html__() format strings, esc_html() on dynamic args). The
// failure-code constants passed as the second constructor arg are
// programmatic identifiers like 'provider_unreachable', never user
// data, so the WPCS exception-output sniff is suppressed for the file.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Builds authorize URLs, exchanges codes for tokens, and fetches the JWKS.
 *
 * Telegram's OIDC profile is a stripped-down variant: no userinfo endpoint,
 * no email claim, RS256 only, Authorization Code + PKCE. We deliberately do
 * not pull in a generic OIDC library — most of them assume the standard
 * profile and fight us when those features are missing. The cryptographic
 * half (id_token verification) is delegated to firebase/php-jwt via the
 * companion Token_Validator class.
 */
class Client {

	/**
	 * Transient key for the cached OIDC discovery document.
	 */
	public const DISCOVERY_TRANSIENT = 'telegram_signin_oidc_discovery';

	/**
	 * Transient key for the cached JWKS document.
	 */
	public const JWKS_TRANSIENT = 'telegram_signin_jwks';

	/**
	 * Cache TTL (in seconds) for both the discovery document and the JWKS.
	 */
	public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * HTTP timeout for every outbound request, in seconds.
	 */
	public const HTTP_TIMEOUT = 5;

	/**
	 * Required scopes injected into every authorize request, regardless of
	 * what the caller passes. Telegram supports `openid`, `profile`, `phone`,
	 * and `telegram:bot_access`; the first two cover the minimum claim set
	 * we need to map a `sub` to a WP user.
	 *
	 * @var string[]
	 */
	public const REQUIRED_SCOPES = array( 'openid', 'profile' );

	/**
	 * In-process cache for the discovery document. Avoids re-hitting the
	 * transient on every call within a single request.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $discovery_cache = null;

	/**
	 * Build the OIDC client.
	 *
	 * @param Config $config Bot credentials + endpoint config.
	 */
	public function __construct( private readonly Config $config ) {}

	/**
	 * Fetch and validate the OIDC discovery document, with caching.
	 *
	 * @return array<string,mixed> The decoded discovery document.
	 *
	 * @throws OIDC_Exception When the endpoint is unreachable or the document is invalid.
	 */
	public function get_discovery(): array {
		if ( null !== $this->discovery_cache ) {
			return $this->discovery_cache;
		}

		$cached = get_transient( self::DISCOVERY_TRANSIENT );
		if ( is_array( $cached ) ) {
			$this->discovery_cache = $cached;
			return $cached;
		}

		$discovery = $this->fetch_json(
			$this->config->discovery_url,
			OIDC_Exception::PROVIDER_UNREACHABLE
		);

		$required_fields = array( 'issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri' );
		foreach ( $required_fields as $field ) {
			if ( empty( $discovery[ $field ] ) || ! is_string( $discovery[ $field ] ) ) {
				throw new OIDC_Exception(
					sprintf(
						/* translators: %s: name of the missing OIDC discovery field, e.g. "jwks_uri". */
						esc_html__( 'OIDC discovery document missing or invalid "%s".', 'sign-in-with-telegram' ),
						esc_html( $field )
					),
					OIDC_Exception::DISCOVERY_INVALID
				);
			}
		}

		set_transient( self::DISCOVERY_TRANSIENT, $discovery, self::CACHE_TTL );
		$this->discovery_cache = $discovery;
		return $discovery;
	}

	/**
	 * Build an OIDC authorize URL with PKCE.
	 *
	 * @param string   $state          Opaque CSRF token; caller is responsible for binding it to the browser session.
	 * @param string   $nonce          Replay-prevention nonce; caller stores the same value for id_token validation.
	 * @param string   $code_challenge Base64url-encoded SHA-256 of the PKCE code verifier.
	 * @param string[] $scopes         Optional extra scopes (`phone`, `telegram:bot_access`). `openid` and `profile` are always included.
	 *
	 * @return string Full authorize URL, ready for a 302 redirect.
	 *
	 * @throws OIDC_Exception When the discovery document can't be fetched.
	 */
	public function build_authorize_url( string $state, string $nonce, string $code_challenge, array $scopes = array() ): string {
		$discovery = $this->get_discovery();

		$merged_scopes = array_values( array_unique( array_merge( self::REQUIRED_SCOPES, $scopes ) ) );

		$params = array(
			'response_type'         => 'code',
			'client_id'             => $this->config->client_id,
			'redirect_uri'          => $this->config->redirect_uri,
			'scope'                 => implode( ' ', $merged_scopes ),
			'state'                 => $state,
			'nonce'                 => $nonce,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
		);

		return $discovery['authorization_endpoint'] . '?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for a token response.
	 *
	 * Uses `client_secret_basic` auth: the credentials are sent in the
	 * Authorization header rather than the body. Telegram's discovery doc
	 * advertises both methods; basic is preferred and the documented example.
	 *
	 * @param string $code          Authorization code Telegram returned to our callback.
	 * @param string $code_verifier PKCE code verifier corresponding to the challenge sent in build_authorize_url().
	 *
	 * @return array<string,mixed> The decoded token response (id_token, access_token, token_type, expires_in, ...).
	 *
	 * @throws OIDC_Exception When the network call fails or the response isn't a valid 2xx JSON token response.
	 */
	public function exchange_code( string $code, string $code_verifier ): array {
		$discovery = $this->get_discovery();

		$response = wp_remote_post(
			$discovery['token_endpoint'],
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					// HTTP Basic auth (RFC 7617) for OIDC client_secret_basic — base64 is part of the protocol, not obfuscation.
					'Authorization' => 'Basic ' . base64_encode( $this->config->client_id . ':' . $this->config->client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Accept'        => 'application/json',
				),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'code_verifier' => $code_verifier,
					'redirect_uri'  => $this->config->redirect_uri,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: %s: WP_Error message returned by the HTTP transport. */
					esc_html__( 'Failed to exchange authorization code: %s', 'sign-in-with-telegram' ),
					esc_html( $response->get_error_message() )
				),
				OIDC_Exception::PROVIDER_UNREACHABLE
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: %d: HTTP status code returned by the OIDC token endpoint. */
					esc_html__( 'Token endpoint returned HTTP %d.', 'sign-in-with-telegram' ),
					(int) $status
				),
				OIDC_Exception::TOKEN_INVALID
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new OIDC_Exception(
				esc_html__( 'Token response was not valid JSON.', 'sign-in-with-telegram' ),
				OIDC_Exception::TOKEN_INVALID
			);
		}

		return $decoded;
	}

	/**
	 * Fetch and cache the JWKS document.
	 *
	 * Token_Validator uses this to verify id_token signatures. Caching is
	 * conservative; callers that need rotation-aware fetches (refresh on
	 * unknown `kid`) should layer it on top.
	 *
	 * @return array<string,mixed> The decoded JWKS, with at least a `keys` array.
	 *
	 * @throws OIDC_Exception When the JWKS can't be fetched or parsed.
	 */
	public function get_jwks(): array {
		$cached = get_transient( self::JWKS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['keys'] ) && is_array( $cached['keys'] ) ) {
			return $cached;
		}

		$jwks = $this->fetch_and_validate_jwks();
		set_transient( self::JWKS_TRANSIENT, $jwks, self::CACHE_TTL );
		return $jwks;
	}

	/**
	 * Force a fresh JWKS fetch, bypassing the cache.
	 *
	 * Used by Token_Validator when an id_token references a `kid` that isn't
	 * in the cached JWKS — Telegram may have rotated keys since the last
	 * cache window. The cached keyset is only replaced after the new one is
	 * successfully fetched and parsed: a transient network failure during a
	 * rotation refresh leaves the old keyset intact, so tokens signed by the
	 * still-valid prior key continue to verify until the next refresh.
	 *
	 * Callers should rate-limit calls externally to avoid stampedes against
	 * the JWKS endpoint.
	 *
	 * @return array<string,mixed> The freshly-fetched JWKS document.
	 *
	 * @throws OIDC_Exception When the JWKS can't be fetched or parsed.
	 */
	public function refresh_jwks(): array {
		$jwks = $this->fetch_and_validate_jwks();
		set_transient( self::JWKS_TRANSIENT, $jwks, self::CACHE_TTL );
		return $jwks;
	}

	/**
	 * Fetch and validate the JWKS document. No caching side effects.
	 *
	 * @return array<string,mixed> Parsed JWKS document with a guaranteed `keys` array.
	 *
	 * @throws OIDC_Exception When the JWKS can't be fetched or parsed.
	 */
	private function fetch_and_validate_jwks(): array {
		$discovery = $this->get_discovery();
		$jwks      = $this->fetch_json( $discovery['jwks_uri'], OIDC_Exception::PROVIDER_UNREACHABLE );

		if ( ! isset( $jwks['keys'] ) || ! is_array( $jwks['keys'] ) ) {
			throw new OIDC_Exception(
				esc_html__( 'JWKS document missing or invalid "keys" array.', 'sign-in-with-telegram' ),
				OIDC_Exception::PROVIDER_UNREACHABLE
			);
		}

		return $jwks;
	}

	/**
	 * Helper: GET a URL and JSON-decode the body.
	 *
	 * @param string $url           Absolute URL to fetch.
	 * @param string $failure_code  Failure code to attach to thrown exceptions.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OIDC_Exception When the network call fails or the body isn't JSON.
	 */
	private function fetch_json( string $url, string $failure_code ): array {
		$response = wp_remote_get( $url, array( 'timeout' => self::HTTP_TIMEOUT ) );

		if ( is_wp_error( $response ) ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: 1: URL the GET request targeted. 2: WP_Error message. */
					esc_html__( 'GET %1$s failed: %2$s', 'sign-in-with-telegram' ),
					esc_html( $url ),
					esc_html( $response->get_error_message() )
				),
				$failure_code
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: 1: URL the GET request targeted. 2: HTTP status code returned. */
					esc_html__( 'GET %1$s returned HTTP %2$d.', 'sign-in-with-telegram' ),
					esc_html( $url ),
					(int) $status
				),
				$failure_code
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new OIDC_Exception(
				sprintf(
					/* translators: %s: URL whose response body could not be parsed as JSON. */
					esc_html__( 'Response from %s was not valid JSON.', 'sign-in-with-telegram' ),
					esc_html( $url )
				),
				$failure_code
			);
		}

		return $decoded;
	}
}
