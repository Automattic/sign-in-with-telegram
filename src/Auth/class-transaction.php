<?php
/**
 * Single-use OIDC login transaction (state/nonce/PKCE/cookie).
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Auth;

defined( 'ABSPATH' ) || exit;

// Exception messages thrown below are escaped at the call sites
// (esc_html__() format strings, esc_html() on dynamic args). The
// failure-code constants passed as the second constructor arg are
// programmatic identifiers, not user data, so the WPCS exception-output
// sniff is suppressed for the file.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Manages the short-lived state/nonce/PKCE bundle that ties a /start
 * request to its matching /callback.
 *
 * Storage shape: a single-use transient keyed by a random id, paired with
 * an httpOnly cookie carrying that id. On callback, we look up the
 * transient by cookie, delete it (single-use), and verify the stored
 * state matches the callback's state.
 *
 * Cookie strategy:
 *   - Production (HTTPS, non-dev host): `__Host-telegram_auth_tx`,
 *     `Secure; HttpOnly; SameSite=Lax; Path=/; Domain=` (no domain — the
 *     `__Host-` prefix mandates it).
 *   - Dev (HTTP, or *.local / *.test / localhost / 127.0.0.1):
 *     `telegram_auth_tx` (unprefixed), Secure dropped. Print an admin
 *     notice elsewhere reminding contributors that production must be
 *     served over HTTPS.
 */
class Transaction {

	/**
	 * Default transient TTL in seconds. Filterable via
	 * `telegram_auth_transaction_ttl` so deployments with unusually slow
	 * round-trips (think: enterprise proxies) can bump it.
	 */
	public const TTL_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Cookie name used in production (HTTPS, non-dev host).
	 */
	public const COOKIE_NAME_PROD = '__Host-telegram_auth_tx';

	/**
	 * Cookie name used on dev hosts (HTTP allowed).
	 */
	public const COOKIE_NAME_DEV = 'telegram_auth_tx';

	/**
	 * Transient key prefix; the random id from the cookie is appended.
	 */
	public const TRANSIENT_PREFIX = 'telegram_auth_tx_';

	/**
	 * Hostnames (lowercased) that always count as dev hosts even with HTTPS.
	 *
	 * @var string[]
	 */
	private const DEV_HOSTNAMES = array( 'localhost', '127.0.0.1' );

	/**
	 * Hostname suffixes that count as dev hosts.
	 *
	 * @var string[]
	 */
	private const DEV_SUFFIXES = array( '.test', '.local' );

	/**
	 * Issue a fresh transaction.
	 *
	 * Generates state / nonce / PKCE values, persists the corresponding
	 * code_verifier + intent + user_id in a single-use transient, and sets
	 * a cookie carrying the transient id.
	 *
	 * @param string   $intent                    'login' for the sign-in flow, 'link' for the connect-existing-account flow.
	 * @param int|null $user_id                   The current user id when intent === 'link'; null otherwise.
	 * @param string   $redirect_to               Post-login destination (already-sanitized URL). Empty string when the surface didn't supply one.
	 * @param string[] $requested_optional_scopes Optional scopes requested at authorize time.
	 *
	 * @return Started_Transaction Public-facing values for the authorize URL.
	 */
	public function start( string $intent, ?int $user_id = null, string $redirect_to = '', array $requested_optional_scopes = array() ): Started_Transaction {
		$state          = self::random_token();
		$nonce          = self::random_token();
		$code_verifier  = self::random_token();
		$code_challenge = self::pkce_challenge( $code_verifier );
		$random_id      = self::random_token();
		$ttl            = $this->ttl();

		$payload = array(
			'state'                     => $state,
			'nonce'                     => $nonce,
			'code_verifier'             => $code_verifier,
			'intent'                    => $intent,
			'user_id'                   => $user_id,
			'redirect_to'               => $redirect_to,
			'requested_optional_scopes' => self::sanitize_requested_optional_scopes( $requested_optional_scopes ),
			'created_at'                => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . $random_id, $payload, $ttl );
		$this->set_cookie( $random_id, $ttl );

		return new Started_Transaction( $state, $nonce, $code_challenge );
	}

	/**
	 * Consume the transaction matching the supplied state.
	 *
	 * Reads the cookie, looks up the transient, deletes it (single-use),
	 * and verifies that the stored state matches the supplied state.
	 *
	 * @param string $callback_state The `state` query param from the callback URL.
	 *
	 * @return Consumed_Transaction
	 *
	 * @throws Transaction_Exception When the cookie is missing, the transient is gone or expired,
	 *                               or the stored state doesn't match.
	 */
	public function consume( string $callback_state ): Consumed_Transaction {
		$random_id = $this->read_cookie();
		if ( null === $random_id ) {
			throw new Transaction_Exception(
				esc_html__( 'Transaction cookie missing or empty.', 'telegram-auth' ),
				Transaction_Exception::STATE_INVALID
			);
		}

		$transient_key = self::TRANSIENT_PREFIX . $random_id;
		$payload       = get_transient( $transient_key );

		// Single-use: clear the transient + cookie even if validation fails below.
		delete_transient( $transient_key );
		$this->clear_cookie();

		if ( ! is_array( $payload ) ) {
			throw new Transaction_Exception(
				esc_html__( 'Transaction expired or already consumed.', 'telegram-auth' ),
				Transaction_Exception::STATE_EXPIRED
			);
		}

		if ( ! isset( $payload['state'] ) || ! hash_equals( (string) $payload['state'], $callback_state ) ) {
			throw new Transaction_Exception(
				esc_html__( 'Transaction state does not match callback state.', 'telegram-auth' ),
				Transaction_Exception::STATE_INVALID
			);
		}

		return new Consumed_Transaction(
			nonce:                     (string) $payload['nonce'],
			code_verifier:             (string) $payload['code_verifier'],
			intent:                    (string) $payload['intent'],
			user_id:                   isset( $payload['user_id'] ) ? (int) $payload['user_id'] : null,
			redirect_to:               isset( $payload['redirect_to'] ) ? (string) $payload['redirect_to'] : '',
			requested_optional_scopes: isset( $payload['requested_optional_scopes'] ) && is_array( $payload['requested_optional_scopes'] ) ? $payload['requested_optional_scopes'] : array(),
		);
	}

	/**
	 * Keep only the optional scope names this plugin can request.
	 *
	 * @param string[] $scopes Requested scope list.
	 *
	 * @return string[]
	 */
	private static function sanitize_requested_optional_scopes( array $scopes ): array {
		$allowed = array( 'phone', 'telegram:bot_access' );
		$clean   = array();
		foreach ( $scopes as $scope ) {
			if ( is_string( $scope ) && in_array( $scope, $allowed, true ) ) {
				$clean[] = $scope;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Resolve the cookie name based on whether we're on a dev host or production.
	 *
	 * On production (HTTPS + non-dev host) we use `__Host-telegram_auth_tx`,
	 * whose prefix mandates Secure + Path=/ + no Domain. Dev hosts (HTTP, or
	 * the well-known dev TLDs) get the unprefixed name with Secure dropped.
	 *
	 * @return string Cookie name.
	 */
	public function cookie_name(): string {
		if ( is_ssl() && ! self::is_dev_host() ) {
			return self::COOKIE_NAME_PROD;
		}
		return self::COOKIE_NAME_DEV;
	}

	/**
	 * Generate a 32-byte hex-encoded random token (64 chars).
	 *
	 * Used for state, nonce, code_verifier, and the transient id. PKCE
	 * RFC 7636 § 4.1 limits code_verifier to [A-Za-z0-9-._~] of length
	 * 43-128; hex (64 chars, [0-9a-f]) fits inside that.
	 *
	 * @return string
	 */
	private static function random_token(): string {
		return bin2hex( \random_bytes( 32 ) );
	}

	/**
	 * Compute the PKCE code_challenge for the given code_verifier (S256).
	 *
	 * @param string $code_verifier PKCE verifier, as produced by random_token().
	 *
	 * @return string Base64url-encoded SHA-256 with no padding.
	 */
	private static function pkce_challenge( string $code_verifier ): string {
		// PKCE S256 (RFC 7636 § 4.2) is base64url(SHA-256). Not obfuscation.
		return rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Effective TTL, after the `telegram_auth_transaction_ttl` filter.
	 *
	 * @return int
	 */
	private function ttl(): int {
		$filtered = (int) apply_filters( 'telegram_auth_transaction_ttl', self::TTL_SECONDS );
		return $filtered > 0 ? $filtered : self::TTL_SECONDS;
	}

	/**
	 * Read the cookie value and return the random transaction id, or null
	 * if the cookie is missing / empty / malformed.
	 *
	 * @return string|null
	 */
	private function read_cookie(): ?string {
		$name = $this->cookie_name();
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}
		$raw = (string) wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restricted to hex via the regex below.
		// Only accept hex tokens we'd actually have set.
		if ( 1 !== preg_match( '/^[a-f0-9]{32,128}$/', $raw ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Set the transaction cookie.
	 *
	 * @param string $value   Random transaction id.
	 * @param int    $expires_in TTL seconds from now.
	 */
	private function set_cookie( string $value, int $expires_in ): void {
		$name   = $this->cookie_name();
		$secure = self::COOKIE_NAME_PROD === $name;

		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + $expires_in,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Clear the transaction cookie. Best effort — we always clear regardless
	 * of whether the validation downstream succeeded.
	 */
	private function clear_cookie(): void {
		$name   = $this->cookie_name();
		$secure = self::COOKIE_NAME_PROD === $name;

		setcookie(
			$name,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ $name ] );
	}

	/**
	 * Whether the current request is reaching us on a dev host.
	 *
	 * `localhost`, `127.0.0.1`, and any host ending in `.test` or `.local`
	 * count. Other hosts — even IPs in private ranges — don't, on the
	 * theory that a private-range deployment with HTTPS terminating
	 * upstream is still production and shouldn't drop the Secure cookie.
	 *
	 * @return bool
	 */
	private static function is_dev_host(): bool {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		// Strip port.
		$host_no_port = explode( ':', $host, 2 )[0];
		if ( in_array( $host_no_port, self::DEV_HOSTNAMES, true ) ) {
			return true;
		}
		foreach ( self::DEV_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host_no_port, $suffix ) ) {
				return true;
			}
		}
		return false;
	}
}
