<?php
/**
 * Public surface of a transaction that has just been consumed.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

defined( 'ABSPATH' ) || exit;

/**
 * Returned by Transaction::consume() after the cookie+transient pair has
 * been validated against the callback's state. Holds the values the
 * Login_Handler needs to complete the flow:
 *   - nonce (for Token_Validator)
 *   - code_verifier (for Client::exchange_code)
 *   - intent (login vs link)
 *   - user_id (the WP user who initiated the link flow, or null for login)
 *   - redirect_to (post-login destination requested by the start surface;
 *     empty string when the surface didn't supply one. Always passed through
 *     wp_safe_redirect at use time, so cross-host targets are silently
 *     downgraded to the home URL — we don't trust this value at write time).
 *   - requested_optional_scopes (optional Telegram scopes requested when
 *     the flow started).
 */
final class Consumed_Transaction {

	/**
	 * Build the value object.
	 *
	 * @param string   $nonce                     Original nonce; will be matched against the id_token's nonce claim.
	 * @param string   $code_verifier             PKCE code_verifier corresponding to the start()-time code_challenge.
	 * @param string   $intent                    'login' or 'link'.
	 * @param int|null $user_id                   The WP user id when intent === 'link', null otherwise.
	 * @param string   $redirect_to               Post-login destination, sanitized via esc_url_raw at start time. Empty when unset.
	 * @param string[] $requested_optional_scopes Optional scopes requested at authorize time.
	 */
	public function __construct(
		public readonly string $nonce,
		public readonly string $code_verifier,
		public readonly string $intent,
		public readonly ?int $user_id,
		public readonly string $redirect_to = '',
		public readonly array $requested_optional_scopes = array(),
	) {}
}
