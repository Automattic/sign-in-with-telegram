<?php
/**
 * Unit tests for Automattic\Telegram\SignIn\Login_Button.
 *
 * @package Automattic\Telegram\SignIn\Tests\UI
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn\Tests\UI;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Automattic\Telegram\SignIn\Settings;
use Automattic\Telegram\SignIn\Login_Button;

/**
 * Coverage for the shortcode markup, login_form auto-injection toggle, and
 * the get_start_url helper that both surfaces share.
 */
final class Login_Button_Test extends TestCase {

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
		Functions\when( 'shortcode_atts' )->alias(
			static function ( array $defaults, $atts ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $name, $value ) => $value
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => match ( $key ) {
				'telegram_signin_settings' => array(
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

	private function button(): Login_Button {
		return new Login_Button( new Settings() );
	}

	public function test_get_start_url_carries_action_and_nonce(): void {
		$url = $this->button()->get_start_url();

		$this->assertStringContainsString( 'action=telegram_signin_start', $url );
		$this->assertStringContainsString( '_wpnonce=nonce-fixture', $url );
		$this->assertStringNotContainsString( 'telegram_signin_redirect_to', $url );
	}

	public function test_get_start_url_appends_redirect_to_when_provided(): void {
		$url = $this->button()->get_start_url( 'https://example.test/welcome/' );

		$this->assertStringContainsString( 'telegram_signin_redirect_to=', $url );
		$this->assertStringContainsString( rawurlencode( 'https://example.test/welcome/' ), $url );
	}

	public function test_render_shortcode_emits_anchor_with_label_and_icon(): void {
		$html = $this->button()->render_shortcode( array() );

		$this->assertStringContainsString( '<a href="https://example.test/wp-login.php?', $html );
		$this->assertStringContainsString( 'class="button button-secondary sign-in-with-telegram-login-button"', $html );
		$this->assertStringContainsString( '<svg', $html );
		$this->assertStringContainsString( 'Sign in with Telegram', $html );
		$this->assertStringContainsString( 'aria-label="Sign in with Telegram"', $html );
	}

	public function test_render_shortcode_honours_custom_label(): void {
		$html = $this->button()->render_shortcode( array( 'label' => 'Login via TG' ) );

		$this->assertStringContainsString( 'Login via TG', $html );
		$this->assertStringNotContainsString( 'Sign in with Telegram', $html );
	}

	public function test_render_shortcode_appends_redirect_to_when_attr_set(): void {
		$html = $this->button()->render_shortcode( array( 'redirect_to' => 'https://example.test/me' ) );

		$this->assertStringContainsString( 'telegram_signin_redirect_to', $html );
	}

	public function test_render_on_login_form_prints_when_filter_returns_true(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $name, $value ) => 'telegram_signin_show_on_login_form' === $name ? true : $value
		);

		ob_start();
		$this->button()->render_on_login_form();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<a href=', $output );
		$this->assertStringContainsString( 'Sign in with Telegram', $output );
	}

	public function test_render_on_login_form_suppresses_when_filter_returns_false(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $name, $value ) => 'telegram_signin_show_on_login_form' === $name ? false : $value
		);

		ob_start();
		$this->button()->render_on_login_form();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}
}
