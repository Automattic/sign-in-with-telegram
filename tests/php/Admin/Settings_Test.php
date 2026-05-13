<?php
/**
 * Unit tests for Telegram_Auth\Admin\Settings.
 *
 * @package Telegram_Auth\Tests\Admin
 */

declare(strict_types=1);

namespace Telegram_Auth\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Telegram_Auth\Admin\Settings;

/**
 * Brain Monkey-stubbed coverage of the option schema and accessors.
 */
final class Settings_Test extends TestCase {

	/**
	 * Registered role fixtures keyed by role slug.
	 *
	 * @var array<string,mixed>
	 */
	private array $roles = array();

	/**
	 * Site default_role option fixture.
	 */
	private string $default_role = 'subscriber';

	/**
	 * Whether the plugin option exists.
	 */
	private bool $settings_option_exists = false;

	/**
	 * Raw plugin option fixture.
	 *
	 * @var mixed
	 */
	private mixed $settings_option = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->roles                  = array(
			'subscriber' => array( 'name' => 'Subscriber' ),
			'editor'     => array( 'name' => 'Editor' ),
		);
		$this->default_role           = 'subscriber';
		$this->settings_option_exists = false;
		$this->settings_option        = array();

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return match ( $key ) {
					Settings::OPTION_KEY => $this->settings_option_exists ? $this->settings_option : $default,
					'default_role'       => $this->default_role,
					default              => $default,
				};
			}
		);
		Functions\when( 'wp_roles' )->alias(
			function () {
				return (object) array( 'roles' => $this->roles );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $value ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( string $value ): string => trim( preg_replace( '/<[^>]*>/', '', $value ) ?? '' )
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
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'wp_validate_redirect' )->alias(
			static function ( string $location, string $fallback ): string {
				return str_starts_with( $location, 'https://example.test/' ) || str_starts_with( $location, '/' )
					? $location
					: $fallback;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_return_the_full_safe_schema(): void {
		$this->default_role = 'editor';

		$defaults = Settings::defaults();

		$this->assertSame(
			array(
				'client_id',
				'client_secret',
				'default_role',
				'allow_signups',
				'email_mode',
				'request_phone',
				'request_dm',
				'button_label',
				'post_login_redirect',
			),
			array_keys( $defaults )
		);
		$this->assertSame( '', $defaults['client_id'] );
		$this->assertSame( '', $defaults['client_secret'] );
		$this->assertSame( 'editor', $defaults['default_role'] );
		$this->assertTrue( $defaults['allow_signups'] );
		$this->assertSame( 'none', $defaults['email_mode'] );
		$this->assertFalse( $defaults['request_phone'] );
		$this->assertFalse( $defaults['request_dm'] );
		$this->assertSame( 'Sign in with Telegram', $defaults['button_label'] );
		$this->assertSame( '', $defaults['post_login_redirect'] );
	}

	public function test_sanitize_coerces_and_bounds_values(): void {
		$this->default_role = 'editor';

		$sanitized = Settings::sanitize(
			array(
				'client_id'           => ' 123456789 ',
				'client_secret'       => '  sec%2Fret  ',
				'default_role'        => 'administrator',
				'allow_signups'       => '0',
				'email_mode'          => 'bogus',
				'request_phone'       => 'yes',
				'request_dm'          => 'false',
				'button_label'        => ' <b>Continue with Telegram</b> ',
				'post_login_redirect' => 'https://evil.test/path',
			)
		);

		$this->assertSame( '123456789', $sanitized['client_id'] );
		$this->assertSame( 'sec%2Fret', $sanitized['client_secret'] );
		$this->assertSame( 'editor', $sanitized['default_role'] );
		$this->assertFalse( $sanitized['allow_signups'] );
		$this->assertSame( 'none', $sanitized['email_mode'] );
		$this->assertTrue( $sanitized['request_phone'] );
		$this->assertFalse( $sanitized['request_dm'] );
		$this->assertSame( 'Continue with Telegram', $sanitized['button_label'] );
		$this->assertSame( 'https://example.test/', $sanitized['post_login_redirect'] );
	}

	public function test_sanitize_preserves_empty_redirect_and_accepts_valid_mode(): void {
		$sanitized = Settings::sanitize(
			array(
				'email_mode'          => 'require',
				'post_login_redirect' => '',
			)
		);

		$this->assertSame( 'require', $sanitized['email_mode'] );
		$this->assertSame( '', $sanitized['post_login_redirect'] );
	}

	public function test_get_client_id_reads_option_then_null(): void {
		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_id' => '987654321' );

		$this->assertSame( '987654321', $settings->get_client_id() );

		$this->settings_option = array();
		$this->assertNull( $settings->get_client_id() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_client_id_prefers_constant_over_option(): void {
		define( Settings::CLIENT_ID_CONSTANT, '123456789' );

		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_id' => '987654321' );

		$this->assertSame( '123456789', $settings->get_client_id() );
	}

	public function test_get_client_secret_reads_option_then_null(): void {
		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_secret' => 'stored-secret' );

		$this->assertSame( 'stored-secret', $settings->get_client_secret() );

		$this->settings_option = array( 'client_secret' => '' );
		$this->assertNull( $settings->get_client_secret() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_client_secret_prefers_constant_over_option(): void {
		define( Settings::CLIENT_SECRET_CONSTANT, 'constant-secret' );

		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_secret' => 'stored-secret' );

		$this->assertSame( 'constant-secret', $settings->get_client_secret() );
	}

	public function test_get_secret_source_reports_db_and_unset(): void {
		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_secret' => 'stored-secret' );

		$this->assertSame( 'db', $settings->get_secret_source() );

		$this->settings_option = array( 'client_secret' => '' );
		$this->assertSame( 'unset', $settings->get_secret_source() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_secret_source_reports_constant(): void {
		define( Settings::CLIENT_SECRET_CONSTANT, 'constant-secret' );

		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_secret' => 'stored-secret' );

		$this->assertSame( 'constant', $settings->get_secret_source() );
	}

	public function test_is_bot_token_shape_accepts_and_rejects_expected_shapes(): void {
		$this->assertTrue( Settings::is_bot_token_shape( '12345:abc_DEF-99' ) );
		$this->assertFalse( Settings::is_bot_token_shape( 'oidc-secret-value' ) );
		$this->assertFalse( Settings::is_bot_token_shape( '12345' ) );
		$this->assertFalse( Settings::is_bot_token_shape( 'abc:def' ) );
	}

	public function test_register_hooks_the_settings_api_registration(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'init', array( Settings::class, 'register_setting' ) );

		( new Settings() )->register();

		$this->addToAssertionCount( 1 );
	}

	public function test_register_setting_registers_schema_and_creates_non_autoloaded_option(): void {
		Functions\expect( 'add_option' )
			->once()
			->with(
				Settings::OPTION_KEY,
				\Mockery::on(
					static fn( $value ): bool => is_array( $value )
						&& array_key_exists( 'client_secret', $value )
						&& array_key_exists( 'post_login_redirect', $value )
				),
				'',
				false
			);

		Functions\expect( 'register_setting' )
			->once()
			->with(
				'telegram_auth',
				Settings::OPTION_KEY,
				\Mockery::on(
						static fn( $args ): bool => is_array( $args )
							&& 'object' === $args['type']
							&& false === $args['show_in_rest']
							&& 'string' === $args['schema']['properties']['client_id']['type']
							&& 'boolean' === $args['schema']['properties']['allow_signups']['type']
							&& array( Settings::class, 'sanitize' ) === $args['sanitize_callback']
							&& is_array( $args['default'] )
				)
			);

		Settings::register_setting();

		$this->addToAssertionCount( 2 );
	}
}
