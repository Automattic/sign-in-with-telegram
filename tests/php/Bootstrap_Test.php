<?php
/**
 * Unit tests for Telegram_Auth\Bootstrap.
 *
 * @package Telegram_Auth\Tests
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\Bootstrap;

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
				'settings'   => '<a href="https://example.test/wp-admin/options-general.php?page=telegram-auth-wp-admin">Settings</a>',
				'deactivate' => '<a href="https://example.test/wp-admin/plugins.php?action=deactivate">Deactivate</a>',
			),
			$links
		);
	}
}
