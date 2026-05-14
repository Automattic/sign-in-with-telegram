<?php
/**
 * Plugin settings schema and reader.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\Admin;

use Telegram_Auth\OIDC\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the persisted plugin option schema and runtime accessors.
 */
class Settings {

	/**
	 * Single option row backing the plugin settings.
	 */
	public const OPTION_KEY = 'telegram_auth_settings';

	/**
	 * Constant name for the OIDC client id (Telegram bot's numeric id).
	 */
	public const CLIENT_ID_CONSTANT = 'TELEGRAM_AUTH_CLIENT_ID';

	/**
	 * Constant name for the OIDC client secret (BotFather Web Login).
	 */
	public const CLIENT_SECRET_CONSTANT = 'TELEGRAM_AUTH_CLIENT_SECRET';

	/**
	 * Valid modes for handling Telegram's missing email claim.
	 *
	 * @var string[]
	 */
	private const EMAIL_MODES = array( 'none', 'placeholder', 'require' );

	/**
	 * Hook settings registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_setting' ) );
	}

	/**
	 * Register the plugin option with WordPress' Settings API.
	 */
	public static function register_setting(): void {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults(), '', false );
		}

		register_setting(
			'telegram_auth',
			self::OPTION_KEY,
			array(
				'type'              => 'object',
				'show_in_rest'      => array(
					'schema' => self::schema(),
				),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Settings object schema.
	 *
	 * Kept alongside the sanitize callback so later REST/UI work can share the
	 * same field contract without exposing the option through core settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(): array {
		$default_role = self::default_role();

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'client_id'           => array(
					'type'    => 'string',
					'default' => '',
					'format'  => 'text-field',
				),
				'client_secret'       => array(
					'type'    => 'string',
					'default' => '',
					'format'  => 'text-field',
				),
				'default_role'        => array(
					'type'    => 'string',
					'enum'    => array_keys( self::roles() ),
					'default' => $default_role,
				),
				'allow_signups'       => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'email_mode'          => array(
					'type'    => 'string',
					'enum'    => self::EMAIL_MODES,
					'default' => 'none',
				),
				'request_phone'       => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'request_dm'          => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'button_label'        => array(
					'type'    => 'string',
					'default' => __( 'Sign in with Telegram', 'telegram-auth' ),
					'format'  => 'text-field',
				),
				'post_login_redirect' => array(
					'type'    => 'string',
					'default' => '',
					'format'  => 'uri',
				),
			),
		);
	}

	/**
	 * Default settings object.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::schema()['properties'] as $key => $property ) {
			$defaults[ $key ] = $property['default'];
		}

		return $defaults;
	}

	/**
	 * Sanitize a settings payload into the complete schema.
	 *
	 * @param array<string,mixed> $input Raw option payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$base   = is_array( $stored ) && ! empty( $stored )
			? array_merge( self::defaults(), $stored )
			: self::defaults();

		$merged = array_merge( $base, $input );

		return rest_sanitize_value_from_schema( $merged, self::schema(), self::OPTION_KEY );
	}

	/**
	 * Read the configured OIDC client id, or null when none is set.
	 *
	 * @return string|null
	 */
	public function get_client_id(): ?string {
		$constant = defined( self::CLIENT_ID_CONSTANT ) ? constant( self::CLIENT_ID_CONSTANT ) : '';
		if ( $constant ) {
			return $constant;
		}

		$value = $this->get_setting_value( 'client_id' );
		return '' === $value ? null : $value;
	}

	/**
	 * Read the configured OIDC client secret, or null when none is set.
	 *
	 * @return string|null
	 */
	public function get_client_secret(): ?string {
		$constant = defined( self::CLIENT_SECRET_CONSTANT ) ? constant( self::CLIENT_SECRET_CONSTANT ) : '';
		if ( $constant ) {
			return $constant;
		}

		$value = $this->get_setting_value( 'client_secret' );

		return '' === $value ? null : $value;
	}

	/**
	 * Identify where the effective client secret comes from.
	 *
	 * @return 'constant'|'db'|'unset'
	 */
	public function get_secret_source(): string {
		$constant = defined( self::CLIENT_SECRET_CONSTANT ) ? constant( self::CLIENT_SECRET_CONSTANT ) : '';
		if ( $constant ) {
			return 'constant';
		}

		$value = $this->get_setting_value( 'client_secret' );
		return '' === $value ? 'unset' : 'db';
	}

	/**
	 * Default role assigned to users created via OIDC sign-up.
	 *
	 * @return string
	 */
	public function get_default_role(): string {
		return $this->get_setting_value( 'default_role' );
	}

	/**
	 * Whether new users may be created via Telegram sign-up.
	 *
	 * @return bool
	 */
	public function allow_signups(): bool {
		return rest_sanitize_boolean( $this->get_setting_value( 'allow_signups' ) );
	}

	/**
	 * Whether to request Telegram's phone scope.
	 *
	 * @return bool
	 */
	public function request_phone(): bool {
		return rest_sanitize_boolean( $this->get_setting_value( 'request_phone' ) );
	}

	/**
	 * Whether to request Telegram bot DM access.
	 *
	 * @return bool
	 */
	public function request_dm(): bool {
		return rest_sanitize_boolean( $this->get_setting_value( 'request_dm' ) );
	}

	/**
	 * Optional OIDC scopes requested by the current settings.
	 *
	 * @return string[]
	 */
	public function requested_optional_scopes(): array {
		$scopes = array();
		if ( $this->request_phone() ) {
			$scopes[] = 'phone';
		}
		if ( $this->request_dm() ) {
			$scopes[] = 'telegram:bot_access';
		}
		return $scopes;
	}

	/**
	 * Build a fully-populated OIDC Config from settings, or null if either
	 * of the two required credentials is missing.
	 *
	 * @return Config|null
	 */
	public function build_oidc_config(): ?Config {
		$client_id     = $this->get_client_id();
		$client_secret = $this->get_client_secret();
		if ( null === $client_id || null === $client_secret ) {
			return null;
		}
		return new Config(
			client_id:     $client_id,
			client_secret: $client_secret,
			redirect_uri:  add_query_arg( 'action', 'telegram_auth_callback', wp_login_url() ),
		);
	}

	/**
	 * Read one option value, falling back to the schema default.
	 *
	 * @param string $key Setting key.
	 *
	 * @return mixed
	 */
	private function get_setting_value( string $key ): mixed {
		$options  = get_option( self::OPTION_KEY, array() );
		$defaults = self::defaults();

		if ( ! is_array( $options ) || ! array_key_exists( $key, $options ) ) {
			return $defaults[ $key ] ?? null;
		}

		return $options[ $key ];
	}

	/**
	 * Return the best registered default role for new users.
	 *
	 * @return string
	 */
	private static function default_role(): string {
		$roles = self::roles();
		$role  = get_option( 'default_role', 'subscriber' );

		if ( array_key_exists( $role, $roles ) ) {
			return $role;
		}

		return array_key_exists( 'subscriber', $roles ) ? 'subscriber' : (string) array_key_first( $roles );
	}

	/**
	 * Registered WordPress roles keyed by role slug.
	 *
	 * @return array<string,mixed>
	 */
	private static function roles(): array {
		return wp_roles()->roles;
	}
}
