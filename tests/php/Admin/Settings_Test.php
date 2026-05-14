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
		// Mock WP's schema-based sanitizer for the shapes Settings::schema()
		// actually uses (object → properties; string with optional enum /
		// format=text-field / format=uri; boolean). Self-contained so the
		// sanitize tests don't need to stub sanitize_text_field /
		// esc_url_raw individually. On enum
		// violation, fall back to the property's default rather than
		// returning a WP_Error, which is the behavior the settings UI
		// relies on for graceful recovery from a stale payload.
		Functions\when( 'rest_sanitize_value_from_schema' )->alias( self::sanitize_value_from_schema( ... ) );
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
	}

	/**
	 * Recursive helper used to stub WP's rest_sanitize_value_from_schema.
	 *
	 * @param mixed               $value  Input value.
	 * @param array<string,mixed> $schema Schema fragment.
	 *
	 * @return mixed Sanitized value.
	 */
	private static function sanitize_value_from_schema( mixed $value, array $schema ): mixed {
		$type = $schema['type'] ?? null;

		if ( 'object' === $type && is_array( $value ) && isset( $schema['properties'] ) ) {
			$sanitized = array();
			foreach ( $schema['properties'] as $key => $property ) {
				if ( array_key_exists( $key, $value ) ) {
					$sanitized[ $key ] = self::sanitize_value_from_schema( $value[ $key ], $property );
				} elseif ( array_key_exists( 'default', $property ) ) {
					$sanitized[ $key ] = $property['default'];
				}
			}
			return $sanitized;
		}

		if ( 'boolean' === $type ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( is_string( $value ) ) {
				return ! in_array( strtolower( $value ), array( '', '0', 'false' ), true );
			}
			return (bool) $value;
		}

		if ( 'string' === $type ) {
			$string_value = is_scalar( $value ) ? (string) $value : '';

			if ( isset( $schema['format'] ) ) {
				$string_value = match ( $schema['format'] ) {
					// Stand-in for sanitize_text_field: trim + strip tags.
					'text-field' => trim( preg_replace( '/<[^>]*>/', '', $string_value ) ?? '' ),
					// Stand-in for esc_url_raw: pass through unchanged (it
					// just filters the scheme list; we don't assert that).
					'uri'        => $string_value,
					default      => $string_value,
				};
			}

			if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $string_value, $schema['enum'], true ) ) {
				return $schema['default'] ?? ( $schema['enum'][0] ?? '' );
			}

			return $string_value;
		}

		return $value;
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
		// `format: uri` runs the value through esc_url_raw only; same-host
		// enforcement happens at use time via wp_safe_redirect in Login_Handler,
		// not at save time.
		$this->assertSame( 'https://evil.test/path', $sanitized['post_login_redirect'] );
	}

	public function test_sanitize_preserves_empty_redirect_and_accepts_valid_mode(): void {
		$sanitized = Settings::sanitize(
			array(
				'email_mode'          => 'placeholder',
				'post_login_redirect' => '',
			)
		);

		$this->assertSame( 'placeholder', $sanitized['email_mode'] );
		$this->assertSame( '', $sanitized['post_login_redirect'] );
	}

	public function test_scope_accessors_read_sanitized_settings(): void {
		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array(
			'request_phone' => '1',
			'request_dm'    => '0',
		);

		$this->assertTrue( $settings->request_phone() );
		$this->assertFalse( $settings->request_dm() );
	}

	/**
	 * @dataProvider requested_optional_scopes_provider
	 *
	 * @param bool     $request_phone Whether phone should be requested.
	 * @param bool     $request_dm    Whether DM access should be requested.
	 * @param string[] $expected      Expected optional scopes.
	 */
	public function test_requested_optional_scopes_reflects_scope_toggles( bool $request_phone, bool $request_dm, array $expected ): void {
		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array(
			'request_phone' => $request_phone,
			'request_dm'    => $request_dm,
		);

		$this->assertSame( $expected, $settings->requested_optional_scopes() );
	}

	/**
	 * @return array<string,array{0:bool,1:bool,2:string[]}>
	 */
	public static function requested_optional_scopes_provider(): array {
		return array(
			'none'       => array( false, false, array() ),
			'phone only' => array( true, false, array( 'phone' ) ),
			'dm only'    => array( false, true, array( 'telegram:bot_access' ) ),
			'both'       => array( true, true, array( 'phone', 'telegram:bot_access' ) ),
		);
	}

	public function test_get_all_returns_defaults_when_option_is_empty(): void {
		$all = ( new Settings() )->get_all();

		$this->assertSame( '', $all['client_id'] );
		$this->assertSame( '', $all['client_secret'] );
		$this->assertTrue( $all['allow_signups'] );
		$this->assertSame( 'none', $all['email_mode'] );
	}

	public function test_get_all_layers_stored_option_over_defaults(): void {
		$this->settings_option_exists = true;
		$this->settings_option        = array(
			'client_id'    => 'stored-id',
			'button_label' => 'Custom',
		);

		$all = ( new Settings() )->get_all();

		$this->assertSame( 'stored-id', $all['client_id'] );
		$this->assertSame( 'Custom', $all['button_label'] );
		// Untouched keys still take their default.
		$this->assertSame( '', $all['client_secret'] );
		$this->assertSame( 'none', $all['email_mode'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_all_lets_wp_config_constants_override_stored_credentials(): void {
		define( Settings::CLIENT_ID_CONSTANT, '111' );
		define( Settings::CLIENT_SECRET_CONSTANT, 'constant-secret' );

		$this->settings_option_exists = true;
		$this->settings_option        = array(
			'client_id'     => 'stored-id',
			'client_secret' => 'stored-secret',
		);

		$all = ( new Settings() )->get_all();

		$this->assertSame( '111', $all['client_id'] );
		$this->assertSame( 'constant-secret', $all['client_secret'] );
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

	public function test_get_client_id_source_reports_db_and_unset(): void {
		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_id' => '123456789' );

		$this->assertSame( 'db', $settings->get_client_id_source() );

		$this->settings_option = array( 'client_id' => '' );
		$this->assertSame( 'unset', $settings->get_client_id_source() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_client_id_source_reports_constant(): void {
		define( Settings::CLIENT_ID_CONSTANT, '123456789' );

		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_id' => '987654321' );

		$this->assertSame( 'constant', $settings->get_client_id_source() );
	}

	public function test_get_client_secret_source_reports_db_and_unset(): void {
		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_secret' => 'stored-secret' );

		$this->assertSame( 'db', $settings->get_client_secret_source() );

		$this->settings_option = array( 'client_secret' => '' );
		$this->assertSame( 'unset', $settings->get_client_secret_source() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_client_secret_source_reports_constant(): void {
		define( Settings::CLIENT_SECRET_CONSTANT, 'constant-secret' );

		$settings                     = new Settings();
		$this->settings_option_exists = true;
		$this->settings_option        = array( 'client_secret' => 'stored-secret' );

		$this->assertSame( 'constant', $settings->get_client_secret_source() );
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
							&& is_array( $args['show_in_rest'] )
							&& 'string' === $args['show_in_rest']['schema']['properties']['client_id']['type']
							&& 'boolean' === $args['show_in_rest']['schema']['properties']['allow_signups']['type']
							&& array( Settings::class, 'sanitize' ) === $args['sanitize_callback']
							&& is_array( $args['default'] )
				)
			);

		Settings::register_setting();

		$this->addToAssertionCount( 2 );
	}
}
