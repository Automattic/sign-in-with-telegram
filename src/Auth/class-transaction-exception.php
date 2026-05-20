<?php
/**
 * Transaction failure exception.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Transaction::consume() when the cookie+transient pair can't be
 * resolved into a valid login transaction.
 *
 * The native exception code is an int; we carry a string failure_code
 * separately so callers can map it onto the public-facing
 * `?telegram_signin_error=<code>` query param without exposing exception
 * messages verbatim.
 */
class Transaction_Exception extends \RuntimeException {

	/**
	 * The cookie is missing, the transient is gone, or the stored state
	 * doesn't match the callback's state. Treated as "definitely not us".
	 */
	public const STATE_INVALID = 'state_invalid';

	/**
	 * The transient is gone — equivalent in effect to STATE_INVALID, but
	 * distinct so we can surface a "your link timed out" hint instead of a
	 * more alarming generic invalid-state message.
	 *
	 * Today expiry is enforced by the transient's own TTL: when the cookie
	 * still points at a transient that's been swept by `wp_cron`, the lookup
	 * returns false and we land here. The `created_at` field on the payload
	 * is recorded but isn't yet checked explicitly — kept for future
	 * tightening if we want to detect "transient survived past TTL" cases.
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
	 * Stable failure code suitable for the public ?telegram_signin_error= param.
	 *
	 * @return string One of state_invalid, state_expired.
	 */
	public function get_failure_code(): string {
		return $this->failure_code;
	}
}
