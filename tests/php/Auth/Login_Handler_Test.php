<?php
/**
 * Unit tests for Telegram_Auth\Auth\Login_Handler.
 *
 * @package Telegram_Auth\Tests\Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests\Auth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\Admin\Settings;
use Telegram_Auth\Auth\Consumed_Transaction;
use Telegram_Auth\Auth\Login_Handler;
use Telegram_Auth\Auth\Transaction;
use Telegram_Auth\Auth\Transaction_Exception;
use Telegram_Auth\Http\Failure_Renderer;
use Telegram_Auth\OIDC\OIDC_Exception;

/**
 * Brain Monkey-stubbed coverage of the callback handler's branches.
 *
 * `handle()` itself does the redirect+exit dance; we test `resolve_user()`
 * directly for the user-lookup / signup logic, plus a couple of integration
 * cases for `handle()` that catch a thrown Redirect_Captured to bypass the
 * actual exit().
 */
final class Login_Handler_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// __() / esc_html__() / esc_html() / wp_json_encode() are polyfilled
		// in tests/php/bootstrap.php — Patchwork can't redefine them after the
		// fact, so we don't try here.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_set_auth_cookie' )->justReturn( null );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/' );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . rawurlencode( (string) $value );
			}
		);
		// Both wp_safe_redirect and exit get short-circuited by throwing —
		// tests catch Redirect_Captured to verify the URL.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ) {
				throw new Redirect_Captured( $url );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_handler(
		?Settings $settings = null,
		?Transaction $transaction = null,
		?Failure_Renderer $renderer = null
	): Login_Handler {
		return new Login_Handler(
			$settings ?? new Settings(),
			$transaction ?? new Transaction(),
			$renderer ?? new Failure_Renderer(),
		);
	}

	private static function consumed( string $intent = 'login', ?int $user_id = null ): Consumed_Transaction {
		return new Consumed_Transaction(
			nonce:         'nonce-fixture',
			code_verifier: 'verifier-fixture',
			intent:        $intent,
			user_id:       $user_id,
		);
	}

	private static function valid_claims( string $sub = 'tg-user-1' ): array {
		return array(
			'iss'   => 'https://oauth.example.test',
			'aud'   => '123456789',
			'sub'   => $sub,
			'nonce' => 'nonce-fixture',
			'iat'   => time(),
			'exp'   => time() + 300,
		);
	}

	// --- resolve_user() ---

	public function test_resolve_user_returns_existing_user_when_sub_is_already_linked(): void {
		$existing     = new \WP_User();
		$existing->ID = 7; // phpcs:ignore Squiz.NamingConventions.ValidVariableName -- WP_User uses uppercase ID.

		Functions\when( 'get_users' )->justReturn( array( $existing ) );

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed() );
		$this->assertSame( $existing, $result );
	}

	public function test_resolve_user_creates_a_new_user_when_signup_is_allowed(): void {
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'random-password' );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'users_can_register' === $key ? 1 : $default
		);

		$inserted = null;
		Functions\when( 'wp_insert_user' )->alias(
			function ( array $args ) use ( &$inserted ) {
				$inserted = $args;
				return 42;
			}
		);
		$created     = new \WP_User();
		$created->ID = 42; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		Functions\when( 'get_user_by' )->justReturn( $created );

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed() );

		$this->assertSame( $created, $result );
		$this->assertNotNull( $inserted );
		$this->assertStringStartsWith( 'tg_', $inserted['user_login'] );
		$this->assertSame( '', $inserted['user_email'] );
		$this->assertSame( 'subscriber', $inserted['role'] );
	}

	public function test_resolve_user_yields_signup_disabled_when_users_cannot_register(): void {
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'users_can_register' === $key ? 0 : $default
		);

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signup_disabled', $result->get_error_code() );
	}

	public function test_resolve_user_yields_wrong_intent_for_link_flow(): void {
		Functions\when( 'get_users' )->justReturn( array() );

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed( 'link', 9 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wrong_intent', $result->get_error_code() );
	}

	public function test_resolve_user_rejects_missing_sub(): void {
		$claims = self::valid_claims();
		unset( $claims['sub'] );

		$result = $this->make_handler()->resolve_user( $claims, self::consumed() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'token_invalid', $result->get_error_code() );
	}

	// --- handle() ---

	public function test_handle_redirects_to_cancelled_on_telegram_error(): void {
		try {
			$this->make_handler()->handle( array( 'error' => 'access_denied' ) );
			$this->fail( 'Expected Redirect_Captured.' );
		} catch ( Redirect_Captured $r ) {
			$this->assertStringContainsString( 'telegram_auth_error=cancelled', $r->url );
		}
	}

	public function test_handle_redirects_to_token_invalid_on_missing_code(): void {
		try {
			$this->make_handler()->handle( array( 'state' => 'something' ) );
			$this->fail( 'Expected Redirect_Captured.' );
		} catch ( Redirect_Captured $r ) {
			$this->assertStringContainsString( 'telegram_auth_error=token_invalid', $r->url );
		}
	}

	public function test_handle_translates_transaction_exception_into_state_invalid_redirect(): void {
		$transaction = $this->getMockBuilder( Transaction::class )->disableOriginalConstructor()->getMock();
		$transaction->method( 'consume' )->willThrowException(
			new Transaction_Exception( 'broken', Transaction_Exception::STATE_INVALID )
		);

		try {
			$this->make_handler( null, $transaction, null )->handle(
				array(
					'code'  => 'auth-code',
					'state' => 'state-mismatch',
				)
			);
			$this->fail( 'Expected Redirect_Captured.' );
		} catch ( Redirect_Captured $r ) {
			$this->assertStringContainsString( 'telegram_auth_error=state_invalid', $r->url );
		}
	}
}

/**
 * Test-only exception used to short-circuit wp_safe_redirect → exit.
 */
final class Redirect_Captured extends \RuntimeException {
	public function __construct( public readonly string $url ) {
		parent::__construct( $url );
	}
}
