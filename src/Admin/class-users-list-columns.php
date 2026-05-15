<?php
/**
 * Users list table columns for Telegram Auth usermeta.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Telegram-collected phone number to wp-admin/users.php.
 */
class Users_List_Columns {

	/**
	 * Column key and usermeta key for the stored phone number.
	 */
	private const PHONE_KEY = 'billing_phone';

	/**
	 * Named meta query clause used for sorting.
	 */
	private const PHONE_META_CLAUSE = 'telegram_auth_billing_phone';

	/**
	 * Runtime settings reader.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Runtime settings reader.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the users list table when phone collection is enabled.
	 */
	public function register(): void {
		if ( ! $this->settings->request_phone() ) {
			return;
		}

		add_filter( 'manage_users_columns', array( $this, 'add_phone_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_phone_column' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( $this, 'add_sortable_phone_column' ) );
		add_action( 'pre_get_users', array( $this, 'maybe_sort_by_phone' ) );
	}

	/**
	 * Insert the phone column after the email column.
	 *
	 * @param array<string,string> $columns Existing users table columns.
	 *
	 * @return array<string,string>
	 */
	public function add_phone_column( array $columns ): array {
		$with_phone = array();
		$inserted   = false;

		foreach ( $columns as $key => $label ) {
			if ( self::PHONE_KEY === $key ) {
				continue;
			}

			$with_phone[ $key ] = $label;

			if ( 'email' === $key ) {
				$with_phone[ self::PHONE_KEY ] = __( 'Phone', 'telegram-auth' );
				$inserted                      = true;
			}
		}

		if ( ! $inserted ) {
			$with_phone[ self::PHONE_KEY ] = __( 'Phone', 'telegram-auth' );
		}

		return $with_phone;
	}

	/**
	 * Render the phone column value for a user.
	 *
	 * @param string $output      Existing column output.
	 * @param string $column_name Column key being rendered.
	 * @param int    $user_id     User id for the row.
	 *
	 * @return string
	 */
	public function render_phone_column( string $output, string $column_name, int $user_id ): string {
		if ( self::PHONE_KEY !== $column_name ) {
			return $output;
		}

		$phone = (string) get_user_meta( $user_id, self::PHONE_KEY, true );
		if ( '' === $phone ) {
			return '';
		}

		return esc_html( $phone );
	}

	/**
	 * Make the phone column sortable.
	 *
	 * @param array<string,string> $columns Existing sortable columns.
	 *
	 * @return array<string,string>
	 */
	public function add_sortable_phone_column( array $columns ): array {
		$columns[ self::PHONE_KEY ] = self::PHONE_KEY;
		return $columns;
	}

	/**
	 * Translate phone-column sorting into a usermeta sort.
	 *
	 * @param object $query WP_User_Query instance.
	 */
	public function maybe_sort_by_phone( object $query ): void {
		if ( self::PHONE_KEY !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set(
			'meta_query',
			array(
				'relation'               => 'OR',
				self::PHONE_META_CLAUSE  => array(
					'key'     => self::PHONE_KEY,
					'compare' => 'EXISTS',
				),
				'telegram_auth_no_phone' => array(
					'key'     => self::PHONE_KEY,
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->set( 'orderby', self::PHONE_META_CLAUSE );
	}
}
