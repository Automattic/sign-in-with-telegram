<?php
/**
 * OIDC client exception.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\OIDC;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Client when discovery, token exchange, or JWKS retrieval fails.
 *
 * The native exception code is an int; we carry a string failure reason
 * separately so callers can map it onto the failure-code query param surfaced
 * to the user via wp-login.php.
 */
class OIDC_Exception extends \RuntimeException {

	/**
	 * Network or HTTP failure reaching Telegram's OIDC endpoints.
	 */
	public const PROVIDER_UNREACHABLE = 'provider_unreachable';

	/**
	 * Token exchange returned a non-2xx response or malformed JSON.
	 */
	public const TOKEN_INVALID = 'token_invalid';

	/**
	 * Discovery document is malformed or missing required fields.
	 */
	public const DISCOVERY_INVALID = 'discovery_invalid';

	/**
	 * Token's iat / exp / nbf are outside the configured skew tolerance.
	 *
	 * Distinct from TOKEN_INVALID so callers can surface a "your clock is
	 * off" hint to the admin instead of the generic invalid-token error.
	 */
	public const CLOCK_SKEW = 'clock_skew';

	/**
	 * Stable failure code, separate from the native int code.
	 *
	 * @var string
	 */
	private string $failure_code;

	/**
	 * Build the exception with a stable failure code alongside the human-readable message.
	 *
	 * @param string          $message      Human-readable error message (admin-side only; never surfaced to end users verbatim).
	 * @param string          $failure_code One of the class constants above.
	 * @param \Throwable|null $previous     Previous throwable, if any.
	 */
	public function __construct( string $message, string $failure_code, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->failure_code = $failure_code;
	}

	/**
	 * Stable, programmatic failure code.
	 *
	 * @return string One of the class constants (provider_unreachable, token_invalid, discovery_invalid, clock_skew).
	 */
	public function get_failure_code(): string {
		return $this->failure_code;
	}
}
