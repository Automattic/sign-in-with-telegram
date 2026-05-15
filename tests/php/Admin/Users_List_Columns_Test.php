<?php
/**
 * Unit tests for Telegram_Auth\Admin\Users_List_Columns.
 *
 * @package Telegram_Auth\Tests\Admin
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\Admin\Settings;
use Telegram_Auth\Admin\Users_List_Columns;

/**
 * Brain Monkey-stubbed coverage for the wp-admin users list phone column.
 */
final class Users_List_Columns_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_columns_when_phone_collection_is_enabled(): void {
		$columns = $this->columns( true );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'manage_users_columns', array( $columns, 'add_phone_column' ) );
		Functions\expect( 'add_filter' )
			->once()
			->with( 'manage_users_custom_column', array( $columns, 'render_phone_column' ), 10, 3 );
		Functions\expect( 'add_filter' )
			->once()
			->with( 'manage_users_sortable_columns', array( $columns, 'add_sortable_phone_column' ) );
		Functions\expect( 'add_filter' )
			->once()
			->with( 'users_list_table_query_args', array( $columns, 'maybe_sort_by_phone' ) );

		$columns->register();

		$this->addToAssertionCount( 4 );
	}

	public function test_register_does_not_hook_columns_when_phone_collection_is_disabled(): void {
		$columns = $this->columns( false );

		Functions\expect( 'add_filter' )->never();
		Functions\expect( 'add_action' )->never();

		$columns->register();

		$this->addToAssertionCount( 2 );
	}

	public function test_add_phone_column_inserts_after_email(): void {
		$columns = $this->columns( true )->add_phone_column(
			array(
				'cb'       => '<input type="checkbox" />',
				'username' => 'Username',
				'name'     => 'Name',
				'email'    => 'Email',
				'role'     => 'Role',
			)
		);

		$this->assertSame(
			array(
				'cb',
				'username',
				'name',
				'email',
				'billing_phone',
				'role',
			),
			array_keys( $columns )
		);
		$this->assertSame( 'Phone', $columns['billing_phone'] );
	}

	public function test_render_phone_column_returns_escaped_usermeta(): void {
		Functions\expect( 'get_user_meta' )
			->once()
			->with( 123, 'billing_phone', true )
			->andReturn( '+1 <555> & 0123' );

		$output = $this->columns( true )->render_phone_column( '', 'billing_phone', 123 );

		$this->assertSame( '+1 &lt;555&gt; &amp; 0123', $output );
	}

	public function test_render_phone_column_returns_empty_string_when_phone_is_missing(): void {
		Functions\expect( 'get_user_meta' )
			->once()
			->with( 123, 'billing_phone', true )
			->andReturn( '' );

		$output = $this->columns( true )->render_phone_column( 'ignored', 'billing_phone', 123 );

		$this->assertSame( '', $output );
	}

	public function test_render_phone_column_preserves_output_for_other_columns(): void {
		Functions\expect( 'get_user_meta' )->never();

		$output = $this->columns( true )->render_phone_column( 'Existing', 'email', 123 );

		$this->assertSame( 'Existing', $output );
	}

	public function test_add_sortable_phone_column_marks_column_sortable(): void {
		$columns = $this->columns( true )->add_sortable_phone_column(
			array(
				'email' => 'email',
			)
		);

		$this->assertSame( 'billing_phone', $columns['billing_phone'] );
	}

	public function test_maybe_sort_by_phone_translates_orderby_to_usermeta_sort(): void {
		$args = $this->columns( true )->maybe_sort_by_phone(
			array(
				'orderby' => 'billing_phone',
				'order'   => 'ASC',
			)
		);

		$this->assertSame(
			array(
				'orderby'    => 'telegram_auth_billing_phone',
				'order'      => 'ASC',
				'meta_query' => array(
					'relation'                    => 'OR',
					'telegram_auth_billing_phone' => array(
						'key'     => 'billing_phone',
						'compare' => 'EXISTS',
					),
					'telegram_auth_no_phone'      => array(
						'key'     => 'billing_phone',
						'compare' => 'NOT EXISTS',
					),
				),
			),
			$args
		);
	}

	public function test_maybe_sort_by_phone_leaves_other_orderby_values_unchanged(): void {
		$args = $this->columns( true )->maybe_sort_by_phone(
			array(
				'orderby' => 'email',
			)
		);

		$this->assertSame(
			array(
				'orderby' => 'email',
			),
			$args
		);
	}

	/**
	 * Build the system under test with a controlled setting state.
	 *
	 * @param bool $request_phone Whether phone collection is enabled.
	 *
	 * @return Users_List_Columns
	 */
	private function columns( bool $request_phone ): Users_List_Columns {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'request_phone' )->willReturn( $request_phone );

		return new Users_List_Columns( $settings );
	}
}
