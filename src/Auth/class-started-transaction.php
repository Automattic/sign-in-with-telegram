<?php
/**
 * Public surface of a transaction that has just been issued.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

defined( 'ABSPATH' ) || exit;

/**
 * Returned by Transaction::start(). Holds the values the caller needs to
 * build the Telegram authorize URL: state, nonce, code_challenge.
 *
 * The matching code_verifier and the original intent / user_id are NOT
 * exposed here — they're stored server-side in the transient and only
 * surface again at consume() time, after the state cookie matches.
 */
final class Started_Transaction {

	/**
	 * Build the value object.
	 *
	 * @param string $state          Opaque CSRF token bound to this transaction.
	 * @param string $nonce          Replay-prevention nonce; matched against the id_token's nonce claim later.
	 * @param string $code_challenge Base64url-encoded SHA-256 of the PKCE code_verifier (passed to Telegram).
	 */
	public function __construct(
		public readonly string $state,
		public readonly string $nonce,
		public readonly string $code_challenge,
	) {}
}
