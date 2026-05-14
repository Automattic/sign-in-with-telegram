<?php
/**
 * Helpers for Telegram optional scopes granted by a user.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Reads optional Telegram scopes recorded on successful callbacks.
 */
final class Scopes {

	/**
	 * Usermeta key storing the optional scopes granted during the latest callback.
	 */
	public const USERMETA_GRANTED_SCOPES = 'telegram_auth_granted_scopes';

	/**
	 * Telegram phone scope.
	 */
	public const SCOPE_PHONE = 'phone';

	/**
	 * Telegram bot access scope.
	 */
	public const SCOPE_BOT_ACCESS = 'telegram:bot_access';

	/**
	 * Granted optional scopes for a user.
	 *
	 * @param int $user_id User id.
	 *
	 * @return string[]
	 */
	public static function granted_for( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::USERMETA_GRANTED_SCOPES, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed = array( self::SCOPE_PHONE, self::SCOPE_BOT_ACCESS );
		$granted = array();
		foreach ( $raw as $scope ) {
			if ( is_string( $scope ) && in_array( $scope, $allowed, true ) ) {
				$granted[] = $scope;
			}
		}

		return array_values( array_unique( $granted ) );
	}

	/**
	 * Whether a user is reachable by Telegram DM through this bot.
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool
	 */
	public static function can_dm( int $user_id ): bool {
		$granted = self::granted_for( $user_id );
		$can_dm  = in_array( self::SCOPE_BOT_ACCESS, $granted, true );

		/**
		 * Filter whether a user is reachable by Telegram DM.
		 *
		 * @param bool     $can_dm  Default decision from stored granted scopes.
		 * @param int      $user_id User id.
		 * @param string[] $granted Stored optional scopes.
		 */
		return (bool) apply_filters( 'telegram_auth_can_dm', $can_dm, $user_id, $granted );
	}
}
