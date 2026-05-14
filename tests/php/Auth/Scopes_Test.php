<?php
/**
 * Unit tests for Telegram_Auth\Auth\Scopes.
 *
 * @package Telegram_Auth\Tests\Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests\Auth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\Auth\Scopes;

/**
 * Brain Monkey-stubbed coverage of granted optional scope helpers.
 */
final class Scopes_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $name, $value, ...$args ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_can_dm_is_true_when_bot_access_is_stored(): void {
		Functions\when( 'get_user_meta' )->justReturn( array( Scopes::SCOPE_BOT_ACCESS ) );

		$this->assertTrue( Scopes::can_dm( 42 ) );
	}

	public function test_can_dm_is_false_when_bot_access_is_absent(): void {
		Functions\when( 'get_user_meta' )->justReturn( array( Scopes::SCOPE_PHONE ) );

		$this->assertFalse( Scopes::can_dm( 42 ) );
	}

	public function test_can_dm_is_false_when_meta_is_empty_or_corrupt(): void {
		Functions\when( 'get_user_meta' )->justReturn( 'not-an-array' );

		$this->assertSame( array(), Scopes::granted_for( 42 ) );
		$this->assertFalse( Scopes::can_dm( 42 ) );
	}

	public function test_can_dm_filter_can_override_result(): void {
		Functions\when( 'get_user_meta' )->justReturn( array() );

		$seen = null;
		Functions\when( 'apply_filters' )->alias(
			function ( string $name, $value, ...$args ) use ( &$seen ) {
				$seen = array_merge( array( $name, $value ), $args );
				return true;
			}
		);

		$this->assertTrue( Scopes::can_dm( 42 ) );
		$this->assertSame( array( 'telegram_auth_can_dm', false, 42, array() ), $seen );
	}
}
