<?php
/**
 * Read accessor for the Telegram-verified phone number stored on a user.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the Telegram-verified phone number stored in
 * {@see Login_Handler::USERMETA_PHONE}. Consumers should call this
 * helper instead of reading the meta key directly.
 */
final class Phone {

	/**
	 * The Telegram-verified phone number for a user, or an empty string
	 * if we don't have one stored (and no filter supplies one).
	 *
	 * @param int $user_id Target user id.
	 *
	 * @return string Phone number in whatever format Telegram supplied or '' when no value is available.
	 */
	public static function for_user( int $user_id ): string {
		$stored = (string) get_user_meta( $user_id, Login_Handler::USERMETA_PHONE, true );

		/**
		 * Filter the Telegram-verified phone number resolved for a user.
		 *
		 * @since 0.1.0
		 *
		 * @param string $phone   The stored Telegram-verified phone, or '' when none.
		 * @param int    $user_id Target user id.
		 */
		return (string) apply_filters( 'telegram_signin_phone', $stored, $user_id );
	}
}
