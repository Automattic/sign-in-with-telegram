<?php
/**
 * Transaction failure exception.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Transaction::consume() when the cookie+transient pair can't be
 * resolved into a valid login transaction.
 *
 * The native exception code is an int; we carry a string failure_code
 * separately so callers can map it onto the public-facing
 * `?telegram_auth_error=<code>` query param without exposing exception
 * messages verbatim.
 */
class Transaction_Exception extends \RuntimeException {

	/**
	 * The cookie is missing, the transient is gone, or the stored state
	 * doesn't match the callback's state. Treated as "definitely not us".
	 */
	public const STATE_INVALID = 'state_invalid';

	/**
	 * The transient existed but is older than TTL — equivalent in effect to
	 * STATE_INVALID, but distinct so we can surface a "your link timed out"
	 * hint instead of a more alarming generic invalid-state message.
	 *
	 * (Today the transient TTL takes care of expiry on the WP side; this
	 * code is reserved in case we ever store created_at and check it
	 * explicitly so the surfaced message can vary.)
	 */
	public const STATE_EXPIRED = 'state_expired';

	/**
	 * Stable, programmatic failure code.
	 *
	 * @var string
	 */
	private string $failure_code;

	/**
	 * Build the exception with a stable failure code alongside the message.
	 *
	 * @param string          $message      Admin-side error message; never surfaced to end users verbatim.
	 * @param string          $failure_code One of the class constants above.
	 * @param \Throwable|null $previous     Optional previous throwable.
	 */
	public function __construct( string $message, string $failure_code, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->failure_code = $failure_code;
	}

	/**
	 * Stable failure code suitable for the public ?telegram_auth_error= param.
	 *
	 * @return string One of state_invalid, state_expired.
	 */
	public function get_failure_code(): string {
		return $this->failure_code;
	}
}
