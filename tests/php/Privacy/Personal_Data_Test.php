<?php
/**
 * Unit tests for Automattic\Telegram\SignIn\Personal_Data.
 *
 * @package Automattic\Telegram\SignIn\Tests\Privacy
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn\Tests\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Automattic\Telegram\SignIn\Login_Handler;
use Automattic\Telegram\SignIn\Scopes;
use Automattic\Telegram\SignIn\Personal_Data;
use WP_User;

/**
 * Brain Monkey-stubbed coverage of Core privacy API integrations.
 */
final class Personal_Data_Test extends TestCase {

	private const USER_EMAIL = 'person@example.test';

	/**
	 * User fixture returned by get_user_by().
	 */
	private WP_User $user;

	/**
	 * Usermeta fixture keyed by meta key.
	 *
	 * @var array<string,mixed>
	 */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->user     = new WP_User();
		$this->user->ID = 123;
		$this->meta     = array();

		Functions\when( 'get_user_by' )->alias(
			function ( string $field, string $value ) {
				if ( 'email' === $field && self::USER_EMAIL === $value ) {
					return $this->user;
				}

				return false;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key, bool $single = false ) {
				$this->assertSame( $this->user->ID, $user_id );
				$this->assertTrue( $single );

				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $name, $value, ...$args ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_core_privacy_integrations(): void {
		$personal_data = new Personal_Data();

		Functions\expect( 'add_filter' )
			->once()
			->with( 'wp_privacy_personal_data_exporters', array( $personal_data, 'register_exporter' ) );
		Functions\expect( 'add_filter' )
			->once()
			->with( 'wp_privacy_personal_data_erasers', array( $personal_data, 'register_eraser' ) );
		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_init', array( $personal_data, 'register_policy_content' ) );

		$personal_data->register();

		$this->addToAssertionCount( 1 );
	}

	public function test_exporter_returns_no_data_for_unknown_email(): void {
		$response = ( new Personal_Data() )->export_personal_data( 'unknown@example.test' );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$response
		);
	}

	public function test_exporter_returns_configured_values_for_matching_user(): void {
		$this->meta = array(
			Login_Handler::USERMETA_SUB         => 'telegram-sub-123',
			Login_Handler::USERMETA_PICTURE_URL => 'https://t.me/i/userpic/photo.jpg',
			Login_Handler::USERMETA_PHONE       => '+15551234567',
		);

		$response = ( new Personal_Data() )->export_personal_data( self::USER_EMAIL );

		$this->assertTrue( $response['done'] );
		$this->assertCount( 1, $response['data'] );

		$group = $response['data'][0];
		$this->assertSame( 'sign-in-with-telegram', $group['group_id'] );
		$this->assertSame( 'Sign in with Telegram', $group['group_label'] );
		$this->assertSame( 'sign-in-with-telegram-123', $group['item_id'] );

		$items = array_column( $group['data'], 'value', 'name' );
		$this->assertSame( 'telegram-sub-123', $items['Telegram account identifier'] );
		$this->assertSame( '+15551234567', $items['Phone number'] );
		$this->assertSame( 'https://t.me/i/userpic/photo.jpg', $items['Telegram profile photo URL'] );
		$this->assertArrayNotHasKey( 'Granted Telegram scopes', $items );
	}

	public function test_exporter_omits_empty_values_cleanly(): void {
		$this->meta = array(
			Login_Handler::USERMETA_SUB   => 'telegram-sub-123',
			Login_Handler::USERMETA_PHONE => '',
		);

		$response = ( new Personal_Data() )->export_personal_data( self::USER_EMAIL );

		$this->assertCount( 1, $response['data'] );
		$items = array_column( $response['data'][0]['data'], 'value', 'name' );

		$this->assertSame(
			array( 'Telegram account identifier' => 'telegram-sub-123' ),
			$items
		);
	}

	public function test_eraser_deletes_scoped_keys_for_matching_user(): void {
		$deleted = array();
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $user_id, string $key ) use ( &$deleted ): bool {
				$deleted[] = array( $user_id, $key );

				return true;
			}
		);

		$response = ( new Personal_Data() )->erase_personal_data( self::USER_EMAIL );

		$this->assertSame(
			array(
				array( 123, Login_Handler::USERMETA_SUB ),
				array( 123, Login_Handler::USERMETA_PICTURE_URL ),
				array( 123, Login_Handler::USERMETA_PHONE ),
				array( 123, Scopes::USERMETA_GRANTED_SCOPES ),
			),
			$deleted
		);
		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$response
		);
	}

	public function test_eraser_is_noop_for_unknown_email(): void {
		Functions\expect( 'delete_user_meta' )->never();

		$response = ( new Personal_Data() )->erase_personal_data( 'unknown@example.test' );

		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$response
		);
	}

	public function test_policy_content_is_registered(): void {
		Functions\expect( 'wp_add_privacy_policy_content' )
			->once()
			->with(
				'Sign in with Telegram',
				\Mockery::on(
					static fn( string $content ): bool => str_contains( $content, 'Telegram OpenID Connect' )
						&& str_contains( $content, 'Telegram account identifier' )
						&& str_contains( $content, 'Telegram profile photo URL' )
						&& str_contains( $content, 'placeholder email mode' )
						&& str_contains( $content, 'Phone number access and bot DM access' )
				)
			);

		( new Personal_Data() )->register_policy_content();

		$this->addToAssertionCount( 1 );
	}
}
