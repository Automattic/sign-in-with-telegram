<?php
/**
 * WordPress Core privacy exporter, eraser, and policy copy.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

use Automattic\Telegram\SignIn\Login_Handler;
use Automattic\Telegram\SignIn\Phone;
use Automattic\Telegram\SignIn\Scopes;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Sign in with Telegram data with WordPress' built-in privacy tools.
 */
class Personal_Data {

	/**
	 * Export/erase group slug.
	 */
	private const GROUP_ID = 'sign-in-with-telegram';

	/**
	 * Hook the service into Core privacy APIs.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'register_policy_content' ) );
	}

	/**
	 * Register the Sign in with Telegram personal data exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::GROUP_ID ] = array(
			'exporter_friendly_name' => __( 'Sign in with Telegram', 'sign-in-with-telegram' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register the Sign in with Telegram personal data eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::GROUP_ID ] = array(
			'eraser_friendly_name' => __( 'Sign in with Telegram', 'sign-in-with-telegram' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Export Sign in with Telegram data for the user Core resolved by email address.
	 *
	 * @param string $email_address User email address from Core's export request.
	 * @param int    $page          Export page number. This exporter is not paginated.
	 *
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		if ( 1 < $page ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user = self::resolve_user( $email_address );
		if ( ! $user instanceof WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data = array();

		$sub = self::string_meta( $user->ID, Login_Handler::USERMETA_SUB );
		if ( '' !== $sub ) {
			$data[] = array(
				'name'  => __( 'Telegram account identifier', 'sign-in-with-telegram' ),
				'value' => $sub,
			);
		}

		$phone = Phone::for_user( $user->ID );
		if ( '' !== $phone ) {
			$data[] = array(
				'name'  => __( 'Phone number', 'sign-in-with-telegram' ),
				'value' => $phone,
			);
		}

		$picture_url = self::string_meta( $user->ID, Login_Handler::USERMETA_PICTURE_URL );
		if ( '' !== $picture_url ) {
			$data[] = array(
				'name'  => __( 'Telegram profile photo URL', 'sign-in-with-telegram' ),
				'value' => $picture_url,
			);
		}

		if ( empty( $data ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		return array(
			'data' => array(
				array(
					'group_id'    => self::GROUP_ID,
					'group_label' => __( 'Sign in with Telegram', 'sign-in-with-telegram' ),
					'item_id'     => self::GROUP_ID . '-' . $user->ID,
					'data'        => $data,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Erase Sign in with Telegram data for the user Core resolved by email address.
	 *
	 * @param string $email_address User email address from Core's erase request.
	 * @param int    $page          Erase page number. This eraser is not paginated.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		if ( 1 < $page ) {
			return self::eraser_response( false );
		}

		$user = self::resolve_user( $email_address );
		if ( ! $user instanceof WP_User ) {
			return self::eraser_response( false );
		}

		$items_removed = false;
		foreach ( self::erasable_meta_keys() as $meta_key ) {
			$items_removed = (bool) delete_user_meta( $user->ID, $meta_key ) || $items_removed;
		}

		return self::eraser_response( $items_removed );
	}

	/**
	 * Register suggested privacy policy text.
	 */
	public function register_policy_content(): void {
		$content  = '<p>' . esc_html__( 'Sign in with Telegram lets visitors sign in to this site with their Telegram account using Telegram OpenID Connect.', 'sign-in-with-telegram' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When a user connects Telegram, the plugin stores a stable Telegram account identifier and may store a Telegram profile photo URL when Telegram supplies one. If optional phone access is granted, the plugin may also store the user\'s Telegram phone number.', 'sign-in-with-telegram' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If placeholder email mode is enabled, the plugin may generate a non-routable WordPress account email address from the Telegram account identifier.', 'sign-in-with-telegram' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Phone number access and bot DM access are requested only when those options are enabled in the Sign in with Telegram settings.', 'sign-in-with-telegram' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Sign in with Telegram', 'sign-in-with-telegram' ), $content );
	}

	/**
	 * Resolve the Core-provided email address to a WordPress user.
	 *
	 * @param string $email_address User email address.
	 *
	 * @return WP_User|null
	 */
	private static function resolve_user( string $email_address ): ?WP_User {
		$user = get_user_by( 'email', $email_address );

		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Read a scalar usermeta value as a non-empty string.
	 *
	 * @param int    $user_id  User id.
	 * @param string $meta_key Usermeta key.
	 *
	 * @return string
	 */
	private static function string_meta( int $user_id, string $meta_key ): string {
		$value = get_user_meta( $user_id, $meta_key, true );
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * Usermeta keys erased by the Core privacy eraser.
	 *
	 * @return string[]
	 */
	private static function erasable_meta_keys(): array {
		return array(
			Login_Handler::USERMETA_SUB,
			Login_Handler::USERMETA_PICTURE_URL,
			Login_Handler::USERMETA_PHONE,
			Scopes::USERMETA_GRANTED_SCOPES,
		);
	}

	/**
	 * Build Core's personal data eraser response shape.
	 *
	 * @param bool $items_removed Whether any usermeta rows were removed.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	private static function eraser_response( bool $items_removed ): array {
		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
