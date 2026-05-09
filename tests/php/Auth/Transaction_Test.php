<?php
/**
 * Unit tests for Telegram_Auth\Auth\Transaction.
 *
 * @package Telegram_Auth\Tests\Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests\Auth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\Auth\Consumed_Transaction;
use Telegram_Auth\Auth\Started_Transaction;
use Telegram_Auth\Auth\Transaction;
use Telegram_Auth\Auth\Transaction_Exception;

/**
 * Brain Monkey-stubbed coverage of the OIDC login transaction store.
 *
 * setcookie() side effects are caught by stubbing the function; the real
 * cookie-setting machinery is exercised end-to-end in integration tests
 * (not in this unit suite).
 */
final class Transaction_Test extends TestCase {

	/**
	 * In-memory transient store, scoped per-test.
	 *
	 * @var array<string,mixed>
	 */
	private array $transients;

	/**
	 * Captured setcookie calls, scoped per-test.
	 *
	 * @var array<int,array{name:string,value:string,options:array<string,mixed>}>
	 */
	private array $cookies_set;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients  = array();
		$this->cookies_set = array();

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl ) {
				$this->transients[ $key ] = array(
					'value'      => $value,
					'expires_at' => time() + $ttl,
				);
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				if ( ! isset( $this->transients[ $key ] ) ) {
					return false;
				}
				if ( $this->transients[ $key ]['expires_at'] < time() ) {
					unset( $this->transients[ $key ] );
					return false;
				}
				return $this->transients[ $key ]['value'];
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);

		Functions\when( 'apply_filters' )->alias(
			static fn( string $name, $value ) => $value
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );

		// Capture setcookie calls. PHP's built-in is interceptable via
		// Patchwork (Brain Monkey's foundation), so this works.
		Functions\when( 'setcookie' )->alias(
			function ( string $name, string $value = '', array $options = array() ): bool {
				$this->cookies_set[] = array(
					'name'    => $name,
					'value'   => $value,
					'options' => $options,
				);
				if ( '' === $value ) {
					unset( $_COOKIE[ $name ] );
				} else {
					$_COOKIE[ $name ] = $value;
				}
				return true;
			}
		);

		// Default to a "production" host so cookie_name() picks the
		// __Host- prefix unless a test overrides.
		$_SERVER['HTTP_HOST'] = 'plugin.example.com';
		$_COOKIE              = array();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$_COOKIE = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_start_returns_state_nonce_and_pkce_challenge(): void {
		$started = ( new Transaction() )->start( 'login' );

		$this->assertInstanceOf( Started_Transaction::class, $started );
		$this->assertSame( 64, strlen( $started->state ) );
		$this->assertSame( 64, strlen( $started->nonce ) );
		// Base64url-encoded SHA-256 (32 bytes) is 43 chars without padding.
		$this->assertSame( 43, strlen( $started->code_challenge ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $started->code_challenge );
	}

	public function test_start_persists_a_transient_and_sets_a_cookie(): void {
		( new Transaction() )->start( 'login' );

		$this->assertCount( 1, $this->transients, 'Exactly one transient should be set.' );
		$this->assertCount( 1, $this->cookies_set, 'Exactly one cookie should be set.' );

		$transient_key = array_keys( $this->transients )[0];
		$this->assertStringStartsWith( Transaction::TRANSIENT_PREFIX, $transient_key );

		$cookie = $this->cookies_set[0];
		$this->assertSame( Transaction::COOKIE_NAME_PROD, $cookie['name'] );
		$this->assertTrue( $cookie['options']['secure'] );
		$this->assertTrue( $cookie['options']['httponly'] );
		$this->assertSame( 'Lax', $cookie['options']['samesite'] );
		$this->assertSame( '/', $cookie['options']['path'] );
	}

	public function test_consume_round_trips_state_nonce_verifier_intent_user_id(): void {
		$tx      = new Transaction();
		$started = $tx->start( 'link', 42 );

		// Pull the stored payload to feed the consume side.
		$transient_key = array_keys( $this->transients )[0];
		$stored        = $this->transients[ $transient_key ]['value'];

		$consumed = $tx->consume( $started->state );

		$this->assertInstanceOf( Consumed_Transaction::class, $consumed );
		$this->assertSame( $started->nonce, $consumed->nonce );
		$this->assertSame( $stored['code_verifier'], $consumed->code_verifier );
		$this->assertSame( 'link', $consumed->intent );
		$this->assertSame( 42, $consumed->user_id );
	}

	public function test_consume_deletes_the_transient_so_replays_fail(): void {
		$tx      = new Transaction();
		$started = $tx->start( 'login' );

		$tx->consume( $started->state );

		$this->assertCount( 0, $this->transients, 'Transient should be deleted on first consume.' );

		// A second consume of the same state must fail (single-use).
		$this->expectException( Transaction_Exception::class );
		$tx->consume( $started->state );
	}

	public function test_consume_throws_state_invalid_when_cookie_is_absent(): void {
		$_COOKIE = array(); // No cookie at all.

		try {
			( new Transaction() )->consume( 'whatever' );
			$this->fail( 'Expected Transaction_Exception.' );
		} catch ( Transaction_Exception $e ) {
			$this->assertSame( Transaction_Exception::STATE_INVALID, $e->get_failure_code() );
		}
	}

	public function test_consume_throws_state_invalid_when_callback_state_does_not_match(): void {
		$tx = new Transaction();
		$tx->start( 'login' );

		try {
			$tx->consume( 'attacker-supplied-state' );
			$this->fail( 'Expected Transaction_Exception.' );
		} catch ( Transaction_Exception $e ) {
			$this->assertSame( Transaction_Exception::STATE_INVALID, $e->get_failure_code() );
		}
	}

	public function test_consume_throws_state_expired_when_transient_is_gone(): void {
		$tx      = new Transaction();
		$started = $tx->start( 'login' );

		// Simulate transient expiry by clearing the in-memory store; the
		// cookie still carries a transaction id that points at nothing.
		$this->transients = array();

		try {
			$tx->consume( $started->state );
			$this->fail( 'Expected Transaction_Exception.' );
		} catch ( Transaction_Exception $e ) {
			$this->assertSame( Transaction_Exception::STATE_EXPIRED, $e->get_failure_code() );
		}
	}

	public function test_cookie_name_uses_host_prefix_on_prod_host(): void {
		$_SERVER['HTTP_HOST'] = 'plugin.example.com';
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->assertSame( Transaction::COOKIE_NAME_PROD, ( new Transaction() )->cookie_name() );
	}

	/**
	 * @dataProvider dev_hosts_provider
	 */
	public function test_cookie_name_drops_host_prefix_on_dev_hosts( string $host ): void {
		$_SERVER['HTTP_HOST'] = $host;
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->assertSame( Transaction::COOKIE_NAME_DEV, ( new Transaction() )->cookie_name() );
	}

	public function test_cookie_name_drops_host_prefix_on_plain_http(): void {
		$_SERVER['HTTP_HOST'] = 'plugin.example.com';
		Functions\when( 'is_ssl' )->justReturn( false );

		$this->assertSame( Transaction::COOKIE_NAME_DEV, ( new Transaction() )->cookie_name() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function dev_hosts_provider(): array {
		return array(
			'localhost'        => array( 'localhost' ),
			'localhost+port'   => array( 'localhost:8888' ),
			'127.0.0.1'        => array( '127.0.0.1' ),
			'.test domain'     => array( 'plugin.test' ),
			'.local domain'    => array( 'plugin.local' ),
			'.test with port'  => array( 'plugin.test:8080' ),
		);
	}
}
