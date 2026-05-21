<?php
/**
 * Unit tests for Automattic\Telegram\SignIn\Bootstrap.
 *
 * @package Automattic\Telegram\SignIn\Tests
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Automattic\Telegram\SignIn\Bootstrap;
use Automattic\Telegram\SignIn\Settings;

/**
 * Brain Monkey-stubbed coverage of the top-level bootstrap helpers.
 */
final class Bootstrap_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_add_settings_action_link_prepends_settings_link(): void {
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . ltrim( $path, '/' )
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $key, string $value, string $url ): string => $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value )
		);
		Functions\when( 'esc_url' )->returnArg();

		$links = Bootstrap::add_settings_action_link(
			array(
				'deactivate' => '<a href="https://example.test/wp-admin/plugins.php?action=deactivate">Deactivate</a>',
			)
		);

		$this->assertSame(
			array(
					'settings'   => '<a href="https://example.test/wp-admin/options-general.php?page=sign-in-with-telegram-wp-admin">Settings</a>',
				'deactivate' => '<a href="https://example.test/wp-admin/plugins.php?action=deactivate">Deactivate</a>',
			),
			$links
		);
	}

	public function test_maybe_print_settings_page_data_skips_other_admin_screens(): void {
		Functions\expect( 'wp_add_inline_script' )->never();

		Bootstrap::maybe_print_settings_page_data( 'edit.php', $this->settings_stub() );

		// Mockery's ->never() expectation is verified in tearDown — we tell
		// PHPUnit about it explicitly so the test isn't reported risky.
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_print_settings_page_data_attaches_inline_script_on_the_settings_page(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $key, string $value, string $url ): string => $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value )
		);

		$captured_handle   = null;
		$captured_inline   = null;
		$captured_position = null;
		Functions\expect( 'wp_add_inline_script' )
			->once()
			->andReturnUsing(
				function ( string $handle, string $data, string $position = 'after' ) use ( &$captured_handle, &$captured_inline, &$captured_position ) {
					$captured_handle   = $handle;
					$captured_inline   = $data;
					$captured_position = $position;
					return true;
				}
			);

		Bootstrap::maybe_print_settings_page_data( 'settings_page_' . Bootstrap::PAGE_SLUG, $this->settings_stub() );

		$this->assertSame( 'sign-in-with-telegram-wp-admin-prerequisites', $captured_handle );
		$this->assertSame( 'before', $captured_position );
		$this->assertStringStartsWith( 'window.telegramSigninData = ', (string) $captured_inline );

		$payload = json_decode( substr( (string) $captured_inline, strlen( 'window.telegramSigninData = ' ), -1 ), true );
		$this->assertSame( 'https://example.test', $payload['siteOrigin'] );
		$this->assertSame( 'https://example.test/wp-login.php?action=telegram_signin_callback', $payload['redirectUri'] );
		$this->assertSame( array( 'client_id_source' => 'db', 'client_secret_source' => 'db' ), $payload['settingsMeta'] );
	}

	/**
	 * Build a Settings double whose accessors return predictable values
	 * for assertions.
	 */
	private function settings_stub(): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_all' )->willReturn( array( 'allow_signups' => true ) );
		$settings->method( 'get_client_id_source' )->willReturn( 'db' );
		$settings->method( 'get_client_secret_source' )->willReturn( 'db' );
		return $settings;
	}
}
