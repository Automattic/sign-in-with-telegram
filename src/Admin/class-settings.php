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
				'show_in_rest'      => false,
				'schema'            => self::schema(),
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
				),
				'client_secret'       => array(
					'type'    => 'string',
					'default' => '',
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
				),
				'post_login_redirect' => array(
					'type'    => 'string',
					'default' => '',
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
		$defaults = self::defaults();
		$input    = array_merge( $defaults, $input );

		return array(
			'client_id'           => self::sanitize_optional_text( $input['client_id'] ),
			'client_secret'       => self::sanitize_optional_text( $input['client_secret'] ),
			'default_role'        => self::sanitize_role( $input['default_role'], $defaults['default_role'] ),
			'allow_signups'       => rest_sanitize_boolean( $input['allow_signups'] ),
			'email_mode'          => self::sanitize_email_mode( $input['email_mode'] ),
			'request_phone'       => rest_sanitize_boolean( $input['request_phone'] ),
			'request_dm'          => rest_sanitize_boolean( $input['request_dm'] ),
			'button_label'        => self::sanitize_button_label( $input['button_label'], $defaults['button_label'] ),
			'post_login_redirect' => self::sanitize_redirect( $input['post_login_redirect'] ),
		);
	}

	/**
	 * Read the configured OIDC client id, or null when none is set.
	 *
	 * @return string|null
	 */
	public function get_client_id(): ?string {
		$constant = self::sanitize_optional_text(
			defined( self::CLIENT_ID_CONSTANT ) ? constant( self::CLIENT_ID_CONSTANT ) : ''
		);
		if ( '' !== $constant ) {
			return $constant;
		}

		$value = self::sanitize_optional_text( $this->get_setting_value( 'client_id' ) );
		return '' === $value ? null : $value;
	}

	/**
	 * Read the configured OIDC client secret, or null when none is set.
	 *
	 * @return string|null
	 */
	public function get_client_secret(): ?string {
		$constant = self::sanitize_optional_text(
			defined( self::CLIENT_SECRET_CONSTANT ) ? constant( self::CLIENT_SECRET_CONSTANT ) : ''
		);
		if ( '' !== $constant ) {
			return $constant;
		}

		$value = self::sanitize_optional_text( $this->get_setting_value( 'client_secret' ) );
		return '' === $value ? null : $value;
	}

	/**
	 * Identify where the effective client secret comes from.
	 *
	 * @return 'constant'|'db'|'unset'
	 */
	public function get_secret_source(): string {
		$constant = self::sanitize_optional_text(
			defined( self::CLIENT_SECRET_CONSTANT ) ? constant( self::CLIENT_SECRET_CONSTANT ) : ''
		);
		if ( '' !== $constant ) {
			return 'constant';
		}

		$value = self::sanitize_optional_text( $this->get_setting_value( 'client_secret' ) );
		return '' === $value ? 'unset' : 'db';
	}

	/**
	 * Build the redirect URI we hand to Telegram's authorize endpoint.
	 *
	 * Always points at `wp-login.php?action=telegram_auth_callback`. The
	 * caller is expected to register this exact URL in BotFather -> Web Login.
	 *
	 * @return string
	 */
	public function get_redirect_uri(): string {
		return (string) add_query_arg( 'action', 'telegram_auth_callback', wp_login_url() );
	}

	/**
	 * Default role assigned to users created via OIDC sign-up.
	 *
	 * @return string
	 */
	public function get_default_role(): string {
		$defaults = self::defaults();
		return self::sanitize_role( $this->get_setting_value( 'default_role' ), $defaults['default_role'] );
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
			redirect_uri:  $this->get_redirect_uri(),
		);
	}

	/**
	 * Check whether a string looks like a Telegram bot token.
	 *
	 * @param string $value Value to inspect.
	 *
	 * @return bool
	 */
	public static function is_bot_token_shape( string $value ): bool {
		return 1 === preg_match( '/^\d+:[A-Za-z0-9_-]+$/', $value );
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
	 * Sanitize optional text. Non-scalar input is treated as unset.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	private static function sanitize_optional_text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize the default role setting.
	 *
	 * @param mixed  $value    Raw role key.
	 * @param string $fallback Role to use when the raw value is invalid.
	 *
	 * @return string
	 */
	private static function sanitize_role( mixed $value, string $fallback ): string {
		$role = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return array_key_exists( $role, self::roles() ) ? $role : $fallback;
	}

	/**
	 * Sanitize the missing-email behavior mode.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	private static function sanitize_email_mode( mixed $value ): string {
		$mode = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return in_array( $mode, self::EMAIL_MODES, true ) ? $mode : 'none';
	}

	/**
	 * Sanitize the front-end login button label.
	 *
	 * @param mixed  $value    Raw label.
	 * @param string $fallback Label to use when the raw value is empty.
	 *
	 * @return string
	 */
	private static function sanitize_button_label( mixed $value, string $fallback ): string {
		$value = self::sanitize_optional_text( $value );
		return '' === $value ? $fallback : $value;
	}

	/**
	 * Sanitize the post-login redirect target.
	 *
	 * @param mixed $value Raw redirect value.
	 *
	 * @return string
	 */
	private static function sanitize_redirect( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		return wp_validate_redirect( esc_url_raw( $value ), home_url() );
	}

	/**
	 * Return the best registered default role for new users.
	 *
	 * @return string
	 */
	private static function default_role(): string {
		$roles = self::roles();
		$role  = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );

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
