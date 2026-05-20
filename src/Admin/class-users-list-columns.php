<?php
/**
 * Users list table columns for Sign in with Telegram usermeta.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

use Automattic\Telegram\SignIn\Login_Handler;
use Automattic\Telegram\SignIn\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Telegram-collected phone number to wp-admin/users.php.
 */
class Users_List_Columns {

	/**
	 * Column key and usermeta key for the stored phone number.
	 */
	private const PHONE_KEY = Login_Handler::USERMETA_PHONE;

	/**
	 * Named meta query clause used for sorting.
	 */
	private const PHONE_META_CLAUSE = 'telegram_signin_phone_sort';

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
		add_filter( 'users_list_table_query_args', array( $this, 'maybe_sort_by_phone' ) );
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
				$with_phone[ self::PHONE_KEY ] = __( 'Phone', 'sign-in-with-telegram' );
				$inserted                      = true;
			}
		}

		if ( ! $inserted ) {
			$with_phone[ self::PHONE_KEY ] = __( 'Phone', 'sign-in-with-telegram' );
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

		$phone = Phone::for_user( $user_id );
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
	 * Translate phone-column sorting into a usermeta sort for the Users list table.
	 *
	 * @param array<string,mixed> $args WP_User_Query args.
	 *
	 * @return array<string,mixed>
	 */
	public function maybe_sort_by_phone( array $args ): array {
		if ( self::PHONE_KEY !== ( $args['orderby'] ?? '' ) ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sorting this custom Users list column requires querying usermeta.
		$args['meta_query'] = array(
			'relation'                 => 'OR',
			self::PHONE_META_CLAUSE    => array(
				'key'     => self::PHONE_KEY,
				'compare' => 'EXISTS',
			),
			'telegram_signin_no_phone' => array(
				'key'     => self::PHONE_KEY,
				'compare' => 'NOT EXISTS',
			),
		);
		$args['orderby']    = self::PHONE_META_CLAUSE;

		return $args;
	}
}
