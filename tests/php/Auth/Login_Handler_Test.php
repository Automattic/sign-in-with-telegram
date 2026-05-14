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
use Telegram_Auth\Auth\Scopes;
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
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $value ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''
		);
		Functions\when( 'rest_sanitize_boolean' )->alias(
			static function ( $value ): bool {
				if ( is_bool( $value ) ) {
					return $value;
				}
				if ( is_string( $value ) ) {
					return ! in_array( strtolower( $value ), array( '', '0', 'false' ), true );
				}
				return (bool) $value;
			}
		);
		Functions\when( 'wp_roles' )->justReturn(
			(object) array(
				'roles' => array(
					'subscriber' => array( 'name' => 'Subscriber' ),
					'editor'     => array( 'name' => 'Editor' ),
				),
			)
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => match ( $key ) {
				'telegram_auth_settings' => $default,
				'default_role'           => 'subscriber',
				default                  => $default,
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_set_auth_cookie' )->justReturn( null );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_update_user' )->justReturn( 1 );
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

	private static function consumed( string $intent = 'login', ?int $user_id = null, array $requested_optional_scopes = array() ): Consumed_Transaction {
		return new Consumed_Transaction(
			nonce:                     'nonce-fixture',
			code_verifier:             'verifier-fixture',
			intent:                    $intent,
			user_id:                   $user_id,
			requested_optional_scopes: $requested_optional_scopes,
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

	public function test_resolve_user_yields_signup_disabled_when_plugin_signups_are_disabled(): void {
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => match ( $key ) {
				'telegram_auth_settings' => array( 'allow_signups' => false ),
				'default_role'           => 'subscriber',
				default                  => $default,
			}
		);

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signup_disabled', $result->get_error_code() );
	}

	public function test_resolve_user_link_attaches_sub_to_originating_user(): void {
		$linker     = new \WP_User();
		$linker->ID = 9; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_user_by' )->justReturn( $linker );

		$captured_user = null;
		$captured_sub  = null;
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) use ( &$captured_user, &$captured_sub ) {
				if ( 'telegram_auth_sub' === $key ) {
					$captured_user = $user_id;
					$captured_sub  = $value;
				}
				return true;
			}
		);

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed( 'link', 9 ) );

		$this->assertSame( $linker, $result );
		$this->assertSame( 9, $captured_user );
		$this->assertSame( 'tg-user-1', $captured_sub );
	}

	public function test_resolve_user_link_is_idempotent_when_sub_is_already_mapped_to_same_user(): void {
		$linker     = new \WP_User();
		$linker->ID = 9; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		Functions\when( 'get_users' )->justReturn( array( $linker ) );
		Functions\when( 'get_user_by' )->justReturn( $linker );

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed( 'link', 9 ) );

		$this->assertSame( $linker, $result );
	}

	public function test_resolve_user_link_refuses_when_sub_is_already_mapped_to_different_user(): void {
		$other     = new \WP_User();
		$other->ID = 42; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		Functions\when( 'get_users' )->justReturn( array( $other ) );

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed( 'link', 9 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'already_linked', $result->get_error_code() );
	}

	public function test_resolve_user_link_yields_wrong_intent_when_user_id_is_missing(): void {
		Functions\when( 'get_users' )->justReturn( array() );

		$result = $this->make_handler()->resolve_user( self::valid_claims(), self::consumed( 'link', null ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wrong_intent', $result->get_error_code() );
	}

	public function test_unlink_deletes_telegram_owned_usermeta_keys(): void {
		$deleted = array();
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $user_id, string $key ) use ( &$deleted ) {
				$deleted[] = array(
					'user' => $user_id,
					'key'  => $key,
				);
				return true;
			}
		);

		$this->make_handler()->unlink( 7 );

			$this->assertSame(
				array(
					array(
						'user' => 7,
						'key'  => 'telegram_auth_sub',
					),
					array(
						'user' => 7,
						'key'  => 'telegram_auth_picture_url',
					),
					array(
						'user' => 7,
						'key'  => Scopes::USERMETA_GRANTED_SCOPES,
					),
				),
				$deleted
			);
			$this->assertNotContains( 'billing_phone', array_column( $deleted, 'key' ) );
		}

	public function test_resolve_user_rejects_missing_sub(): void {
		$claims = self::valid_claims();
		unset( $claims['sub'] );

		$result = $this->make_handler()->resolve_user( $claims, self::consumed() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'token_invalid', $result->get_error_code() );
	}

	public function test_resolve_user_attaches_new_sub_to_currently_logged_in_user_instead_of_creating_one(): void {
		$current     = new \WP_User();
		$current->ID = 7; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_by' )->justReturn( $current );

		$captured_sub  = null;
		$captured_user = null;
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) use ( &$captured_sub, &$captured_user ) {
				if ( 'telegram_auth_sub' === $key ) {
					$captured_user = $user_id;
					$captured_sub  = $value;
				}
				return true;
			}
		);

		$result = $this->make_handler()->resolve_user( self::valid_claims( 'tg-new' ), self::consumed( 'login' ) );

		$this->assertSame( $current, $result, 'Should return the already-logged-in user, not create a new one.' );
		$this->assertSame( 7, $captured_user );
		$this->assertSame( 'tg-new', $captured_sub );
	}

	public function test_resolve_user_creating_new_user_passes_display_name_and_picture_from_claims(): void {
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'random-password' );

		$inserted = null;
		Functions\when( 'wp_insert_user' )->alias(
			function ( array $args ) use ( &$inserted ) {
				$inserted = $args;
				return 99;
			}
		);

		$created     = new \WP_User();
		$created->ID = 99; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		Functions\when( 'get_user_by' )->justReturn( $created );

		$captured_picture = null;
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) use ( &$captured_picture ) {
				if ( 'telegram_auth_picture_url' === $key ) {
					$captured_picture = $value;
				}
				return true;
			}
		);

		$claims            = self::valid_claims();
		$claims['name']    = 'Pat Q. Person';
		$claims['picture'] = 'https://t.me/i/userpic/x.jpg';

		$this->make_handler()->resolve_user( $claims, self::consumed() );

		$this->assertNotNull( $inserted );
		$this->assertSame( 'Pat Q. Person', $inserted['display_name'] );
		$this->assertSame( 'Pat Q. Person', $inserted['nickname'] );
		$this->assertArrayNotHasKey( 'first_name', $inserted, 'We do not split the name into first/last; Telegram supplies a single display string.' );
		$this->assertArrayNotHasKey( 'last_name', $inserted );
		$this->assertSame( 'https://t.me/i/userpic/x.jpg', $captured_picture );
	}

	public function test_resolve_user_creating_new_user_writes_billing_phone_when_phone_number_claim_present(): void {
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'random-password' );
		Functions\when( 'wp_insert_user' )->justReturn( 101 );

		$created     = new \WP_User();
		$created->ID = 101; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		Functions\when( 'get_user_by' )->justReturn( $created );

		$written = array();
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) use ( &$written ) {
				$written[] = array(
					'user'  => $user_id,
					'key'   => $key,
					'value' => $value,
				);
				return true;
			}
		);

		$claims                 = self::valid_claims();
		$claims['phone_number'] = '+15551234567';

		$this->make_handler()->resolve_user( $claims, self::consumed() );

		$this->assertContains(
			array(
				'user'  => 101,
				'key'   => 'billing_phone',
				'value' => '+15551234567',
			),
			$written
		);
	}

	public function test_resolve_user_creating_new_user_does_not_write_billing_phone_when_phone_number_claim_absent(): void {
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'random-password' );
		Functions\when( 'wp_insert_user' )->justReturn( 102 );

		$created     = new \WP_User();
		$created->ID = 102; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		Functions\when( 'get_user_by' )->justReturn( $created );

		$written_keys = array();
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) use ( &$written_keys ) {
				$written_keys[] = $key;
				return true;
			}
		);

		$this->make_handler()->resolve_user( self::valid_claims(), self::consumed() );

		$this->assertNotContains( 'billing_phone', $written_keys );
	}

	public function test_resolve_user_existing_user_does_not_backfill_billing_phone(): void {
		$existing     = new \WP_User();
		$existing->ID = 7; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		Functions\when( 'get_users' )->justReturn( array( $existing ) );

		$written_keys = array();
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) use ( &$written_keys ) {
				$written_keys[] = $key;
				return true;
			}
		);

		$claims                 = self::valid_claims();
		$claims['phone_number'] = '+15551234567';

		$this->make_handler()->resolve_user( $claims, self::consumed() );

		$this->assertNotContains( 'billing_phone', $written_keys );
	}

	public function test_resolve_user_writes_granted_scopes_usermeta(): void {
		$existing     = new \WP_User();
		$existing->ID = 7; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		Functions\when( 'get_users' )->justReturn( array( $existing ) );

		$stored_scopes = null;
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) use ( &$stored_scopes ) {
				if ( Scopes::USERMETA_GRANTED_SCOPES === $key ) {
					$stored_scopes = $value;
				}
				return true;
			}
		);

		$this->make_handler()->resolve_user(
			self::valid_claims(),
			self::consumed(),
			array( Scopes::SCOPE_PHONE, 'bogus', Scopes::SCOPE_BOT_ACCESS )
		);

		$this->assertSame( array( Scopes::SCOPE_PHONE, Scopes::SCOPE_BOT_ACCESS ), $stored_scopes );
	}

	public function test_granted_scopes_fall_back_to_requested_scopes_when_token_scope_is_absent(): void {
		$claims                 = self::valid_claims();
		$claims['phone_number'] = '+15551234567';

		$this->assertSame(
			array( Scopes::SCOPE_PHONE, Scopes::SCOPE_BOT_ACCESS ),
			$this->granted_scopes_from_callback(
				array( 'id_token' => 'token-fixture' ),
				$claims,
				self::consumed( 'login', null, array( Scopes::SCOPE_PHONE, Scopes::SCOPE_BOT_ACCESS ) )
			)
		);
	}

	public function test_granted_scopes_honour_token_scope_when_supplied(): void {
		$claims                 = self::valid_claims();
		$claims['phone_number'] = '+15551234567';

		$this->assertSame(
			array( Scopes::SCOPE_PHONE ),
			$this->granted_scopes_from_callback(
				array( 'scope' => 'openid profile phone' ),
				$claims,
				self::consumed( 'login', null, array( Scopes::SCOPE_PHONE, Scopes::SCOPE_BOT_ACCESS ) )
			)
		);
	}

	/**
	 * Invoke Login_Handler's callback-scope derivation helper.
	 *
	 * @param array<string,mixed> $tokens Token response.
	 * @param array<string,mixed> $claims Validated claims.
	 *
	 * @return string[]
	 */
	private function granted_scopes_from_callback( array $tokens, array $claims, Consumed_Transaction $tx ): array {
		$method = new \ReflectionMethod( Login_Handler::class, 'granted_scopes_from_callback' );
		$method->setAccessible( true );
		return $method->invoke( $this->make_handler(), $tokens, $claims, $tx );
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
