<?php
/**
 * Telegram-supplied avatar provider.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

use Automattic\Telegram\SignIn\Login_Handler;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Substitutes the Telegram-supplied `picture` URL for a user's avatar
 * when one is stored.
 *
 * Hooks `pre_get_avatar_data` so the change applies everywhere `get_avatar`
 * (and friends) is called — admin user lists, comments, REST responses,
 * etc. We never download or re-host the image; the browser fetches it
 * directly from Telegram's CDN.
 */
class Avatar_Provider {

	/**
	 * Hook the avatar filter.
	 */
	public function register(): void {
		add_filter( 'pre_get_avatar_data', array( $this, 'maybe_use_telegram_picture' ), 10, 2 );
	}

	/**
	 * If the resolved user has a Telegram picture URL, swap it in.
	 *
	 * @param array<string,mixed> $args         Avatar args being built by core.
	 * @param mixed               $id_or_email  User identifier passed to get_avatar(): a user id, email, WP_User, WP_Comment, etc.
	 *
	 * @return array<string,mixed>
	 */
	public function maybe_use_telegram_picture( array $args, $id_or_email ): array {
		$user = self::resolve_user( $id_or_email );
		if ( ! $user instanceof WP_User ) {
			return $args;
		}

		$url = (string) get_user_meta( $user->ID, Login_Handler::USERMETA_PICTURE_URL, true );
		if ( '' === $url ) {
			return $args;
		}

		$args['url']          = $url;
		$args['found_avatar'] = true;
		return $args;
	}

	/**
	 * Translate the various shapes get_avatar passes around into a WP_User.
	 *
	 * @param mixed $id_or_email int | string (email) | WP_User | WP_Comment | WP_Post | other.
	 *
	 * @return WP_User|null
	 */
	private static function resolve_user( $id_or_email ): ?WP_User {
		if ( is_numeric( $id_or_email ) ) {
			$user = get_user_by( 'id', (int) $id_or_email );
			return $user instanceof WP_User ? $user : null;
		}
		if ( $id_or_email instanceof WP_User ) {
			return $id_or_email;
		}
		if ( is_object( $id_or_email ) && isset( $id_or_email->user_id ) && (int) $id_or_email->user_id > 0 ) {
			$user = get_user_by( 'id', (int) $id_or_email->user_id );
			return $user instanceof WP_User ? $user : null;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user instanceof WP_User ? $user : null;
		}
		return null;
	}
}
