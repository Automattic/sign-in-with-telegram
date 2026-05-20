<?php
/**
 * Unit tests for Automattic\Telegram\SignIn\Phone.
 *
 * @package Automattic\Telegram\SignIn\Tests\Auth
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn\Tests\Auth;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Automattic\Telegram\SignIn\Login_Handler;
use Automattic\Telegram\SignIn\Phone;

/**
 * Brain Monkey-stubbed coverage of the Phone read accessor + filter.
 */
final class Phone_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_for_user_returns_stored_telegram_signin_phone(): void {
		$captured_key = null;
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key, bool $single = false ) use ( &$captured_key ) {
				$captured_key = $key;
				return '+15551234567';
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $name, $value, ...$args ) => $value
		);

		$this->assertSame( '+15551234567', Phone::for_user( 42 ) );
		$this->assertSame( Login_Handler::USERMETA_PHONE, $captured_key );
	}

	public function test_for_user_returns_empty_string_when_no_value_stored(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $name, $value, ...$args ) => $value
		);

		$this->assertSame( '', Phone::for_user( 42 ) );
	}

	public function test_for_user_applies_telegram_signin_phone_filter(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Filters\expectApplied( 'telegram_signin_phone' )
			->once()
			->with( '', 42 )
			->andReturn( '+15550000000' );

		$this->assertSame( '+15550000000', Phone::for_user( 42 ) );
	}

	public function test_for_user_passes_stored_value_through_filter(): void {
		Functions\when( 'get_user_meta' )->justReturn( '+15551234567' );

		Filters\expectApplied( 'telegram_signin_phone' )
			->once()
			->with( '+15551234567', 7 )
			->andReturn( '+15551234567' );

		$this->assertSame( '+15551234567', Phone::for_user( 7 ) );
	}
}
