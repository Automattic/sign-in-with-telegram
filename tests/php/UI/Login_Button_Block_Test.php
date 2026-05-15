<?php
/**
 * Unit tests for Telegram_Auth\UI\Login_Button_Block.
 *
 * @package Telegram_Auth\Tests\UI
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests\UI;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\Admin\Settings;
use Telegram_Auth\UI\Login_Button;
use Telegram_Auth\UI\Login_Button_Block;

/**
 * Coverage for the block render_callback. Editor-side registration runs
 * inside WordPress and isn't unit-tested here; we exercise the PHP path
 * that produces the front-end markup.
 */
final class Login_Button_Block_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-fixture' );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$query = http_build_query( (array) $args );
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_block_wrapper_attributes' )->justReturn( 'class="wp-block-telegram-auth-login-button"' );
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => match ( $key ) {
				'telegram_auth_settings' => array(
					'client_id'     => '12345',
					'client_secret' => 'secret',
				),
				'default_role'           => 'subscriber',
				default                  => $default,
			}
		);
		Functions\when( 'wp_roles' )->justReturn(
			(object) array(
				'roles' => array( 'subscriber' => array( 'name' => 'Subscriber' ) ),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function block(): Login_Button_Block {
		$settings = new Settings();
		return new Login_Button_Block( new Login_Button( $settings ), $settings );
	}

	public function test_render_uses_default_label_when_attribute_is_blank(): void {
		$html = $this->block()->render( array() );

		$this->assertStringContainsString( 'Sign in with Telegram', $html );
	}

	public function test_render_uses_custom_label_when_provided(): void {
		$html = $this->block()->render( array( 'label' => 'Continue with Telegram' ) );

		$this->assertStringContainsString( 'Continue with Telegram', $html );
		$this->assertStringNotContainsString( 'Sign in with Telegram', $html );
	}

	public function test_render_passes_redirect_to_into_the_start_url(): void {
		$html = $this->block()->render( array( 'redirectTo' => 'https://example.test/welcome/' ) );

		$this->assertStringContainsString( 'telegram_auth_redirect_to', $html );
	}

	public function test_render_wraps_the_button_in_block_wrapper_attributes(): void {
		$html = $this->block()->render( array() );

		$this->assertStringContainsString( '<span class="wp-block-telegram-auth-login-button">', $html );
		$this->assertStringContainsString( '</span>', $html );
		$this->assertStringContainsString( '<a href=', $html );
	}
}
