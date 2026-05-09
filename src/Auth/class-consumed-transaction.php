<?php
/**
 * Public surface of a transaction that has just been consumed.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Returned by Transaction::consume() after the cookie+transient pair has
 * been validated against the callback's state. Holds the values the
 * Login_Handler needs to complete the flow:
 *   - nonce (for Token_Validator)
 *   - code_verifier (for Client::exchange_code)
 *   - intent (login vs link)
 *   - user_id (the WP user who initiated the link flow, or null for login)
 */
final class Consumed_Transaction {

	/**
	 * Build the value object.
	 *
	 * @param string   $nonce         Original nonce; will be matched against the id_token's nonce claim.
	 * @param string   $code_verifier PKCE code_verifier corresponding to the start()-time code_challenge.
	 * @param string   $intent        'login' or 'link'.
	 * @param int|null $user_id       The WP user id when intent === 'link', null otherwise.
	 */
	public function __construct(
		public readonly string $nonce,
		public readonly string $code_verifier,
		public readonly string $intent,
		public readonly ?int $user_id,
	) {}
}
