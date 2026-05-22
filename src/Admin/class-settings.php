<?php
/**
 * Plugin settings schema and reader.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

use Automattic\Telegram\SignIn\Config;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the persisted plugin option schema and runtime accessors.
 */
class Settings {

	/**
	 * Single option row backing the plugin settings.
	 */
	public const OPTION_KEY = 'telegram_signin_settings';

	/**
	 * Constant name for the OIDC client id (Telegram bot's numeric id).
	 */
	public const CLIENT_ID_CONSTANT = 'TELEGRAM_SIGNIN_CLIENT_ID';

	/**
	 * Constant name for the OIDC client secret (BotFather Web Login).
	 */
	public const CLIENT_SECRET_CONSTANT = 'TELEGRAM_SIGNIN_CLIENT_SECRET';

	/**
	 * Valid modes for handling Telegram's missing email claim.
	 *
	 * @var string[]
	 */
	private const EMAIL_MODES = array( 'none', 'placeholder' );

	/**
	 * Capabilities that mark a role as too privileged to apply to a
	 * WordPress account created for a first-time Telegram sign-in. A role
	 * carrying any of these is excluded from the default-role picker, so a
	 * newly created account can never be granted administrative control of
	 * the site. The list targets site-administration powers —
	 * `administrator` (and any custom admin-equivalent role) is filtered
	 * out; editor / author / contributor / subscriber keep none of these
	 * and stay selectable.
	 *
	 * @var string[]
	 */
	private const PRIVILEGED_CAPABILITIES = array(
		'manage_options',
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'activate_plugins',
		'install_plugins',
		'edit_plugins',
		'install_themes',
		'edit_themes',
		'switch_themes',
		'update_core',
		'edit_files',
		'import',
		'export',
	);

	/**
	 * Hook settings registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_setting' ) );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'redact_in_rest_response' ), 10, 3 );
	}

	/**
	 * Strip `client_secret` out of the `/wp/v2/settings` REST response.
	 *
	 * @param mixed               $response Response value returned by the REST handler.
	 * @param array<string,mixed> $handler  Handler that produced the response.
	 * @param \WP_REST_Request    $request  Originating request.
	 *
	 * @return mixed
	 */
	public static function redact_in_rest_response( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( '/wp/v2/settings' !== $request->get_route() ) {
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data[ self::OPTION_KEY ] ) && is_array( $data[ self::OPTION_KEY ] ) ) {
				$data[ self::OPTION_KEY ] = self::redact_for_display( $data[ self::OPTION_KEY ] );
				$response->set_data( $data );
			}
			return $response;
		}

		if ( is_array( $response ) && isset( $response[ self::OPTION_KEY ] ) && is_array( $response[ self::OPTION_KEY ] ) ) {
			$response[ self::OPTION_KEY ] = self::redact_for_display( $response[ self::OPTION_KEY ] );
		}
		return $response;
	}

	/**
	 * Register the plugin option with WordPress' Settings API.
	 */
	public static function register_setting(): void {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults(), '', false );
		}

		register_setting(
			'telegram_signin',
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
					'enum'    => array_keys( self::assignable_roles() ),
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
					// Not translated at schema time: schema() runs from
					// `init` callbacks, where WP 6.7+ warns about
					// just-in-time textdomain loading. Settings::get_button_label()
					// wraps this value in __() at read time so translation
					// still happens when the option is unset.
					'default' => 'Sign in with Telegram',
					'format'  => 'text-field',
				),
				'post_login_redirect' => array(
					'type'    => 'string',
					'default' => '',
					'format'  => 'uri',
				),
				'clean_uninstall'     => array(
					'type'    => 'boolean',
					'default' => false,
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

		/*
		 * "Leave blank to keep" for the secret. The UI redacts it
		 * to an empty string on every render, so submitting the form
		 * without typing always carries client_secret = ''. Treat that
		 * as "no change" rather than "clear the stored secret" —
		 * clearing has to be done via wp-cli.
		 */
		if ( isset( $input['client_secret'] ) && '' === $input['client_secret'] ) {
			unset( $input['client_secret'] );
		}

		/*
		 * Credentials managed by wp-config constants are
		 * managed-elsewhere — they must never be persisted to the
		 * options row, regardless of what the form submitted.
		 */
		if ( defined( self::CLIENT_ID_CONSTANT ) && constant( self::CLIENT_ID_CONSTANT ) ) {
			unset( $input['client_id'] );
		}
		if ( defined( self::CLIENT_SECRET_CONSTANT ) && constant( self::CLIENT_SECRET_CONSTANT ) ) {
			unset( $input['client_secret'] );
		}

		$merged = array_merge( $base, $input );

		return rest_sanitize_value_from_schema( $merged, self::schema(), self::OPTION_KEY );
	}

	/**
	 * Return the effective settings for surfacing to the admin UI.
	 *
	 * @return array<string,mixed>
	 */
	public function get_all(): array {
		$stored   = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $stored ) && ! empty( $stored )
			? array_merge( self::defaults(), $stored )
			: self::defaults();

		$client_id = $this->get_client_id();
		if ( null !== $client_id ) {
			$settings['client_id'] = $client_id;
		}

		return self::redact_for_display( $settings );
	}

	/**
	 * Apply the redaction policy used everywhere the settings are surfaced.
	 *
	 * @param array<string,mixed> $settings Raw settings array.
	 *
	 * @return array<string,mixed>
	 */
	private static function redact_for_display( array $settings ): array {
		$settings['client_secret'] = '';
		return $settings;
	}

	/**
	 * Whether the plugin has both credentials available.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return $this->get_client_id() && $this->get_client_secret();
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
	 * Identify where the effective client id comes from.
	 *
	 * @return 'constant'|'db'|'unset'
	 */
	public function get_client_id_source(): string {
		return $this->source_for( self::CLIENT_ID_CONSTANT, 'client_id' );
	}

	/**
	 * Identify where the effective client secret comes from.
	 *
	 * @return 'constant'|'db'|'unset'
	 */
	public function get_client_secret_source(): string {
		return $this->source_for( self::CLIENT_SECRET_CONSTANT, 'client_secret' );
	}

	/**
	 * Shared resolver for credential-source flags.
	 *
	 * @param string $constant_name Name of the wp-config constant that overrides the option.
	 * @param string $option_key    Property name on the settings option.
	 *
	 * @return 'constant'|'db'|'unset'
	 */
	private function source_for( string $constant_name, string $option_key ): string {
		$constant = defined( $constant_name ) ? constant( $constant_name ) : '';
		if ( $constant ) {
			return 'constant';
		}

		$value = $this->get_setting_value( $option_key );
		return '' === $value ? 'unset' : 'db';
	}

	/**
	 * Default role assigned to users created via OIDC sign-up.
	 *
	 * @return string
	 */
	public function get_default_role(): string {
		$role = (string) $this->get_setting_value( 'default_role' );

		// Never apply a privileged role to an account created for a
		// Telegram sign-in, even if the stored option somehow holds one —
		// set before the picker was restricted, or written straight to the
		// database. Fall back to the safe default when the stored value
		// isn't an assignable role.
		return array_key_exists( $role, self::assignable_roles() ) ? $role : self::default_role();
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
	 * Strategy for handling Telegram's missing email claim when creating new users.
	 *
	 * @return string Either 'none' or 'placeholder'.
	 */
	public function get_email_mode(): string {
		$value = (string) $this->get_setting_value( 'email_mode' );
		return in_array( $value, array( 'none', 'placeholder' ), true ) ? $value : 'none';
	}

	/**
	 * Site-wide default label for the Sign-in-with-Telegram button.
	 *
	 * Falls back to the translatable "Sign in with Telegram" string when
	 * the option is empty or whitespace-only so callers can always render
	 * a usable label without their own fallback.
	 *
	 * @return string
	 */
	public function get_button_label(): string {
		$value = trim( (string) $this->get_setting_value( 'button_label' ) );
		return '' === $value
			? __( 'Sign in with Telegram', 'sign-in-with-telegram' )
			: $value;
	}

	/**
	 * Configured post-login redirect, or an empty string when unset.
	 *
	 * Same-host enforcement happens at use time via `wp_safe_redirect`
	 * (the caller in Login_Handler).
	 *
	 * @return string
	 */
	public function get_post_login_redirect(): string {
		return (string) $this->get_setting_value( 'post_login_redirect' );
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
	 * Whether uninstalling the plugin should remove the saved
	 * settings and user-data.
	 *
	 * @return bool
	 */
	public function clean_uninstall(): bool {
		return rest_sanitize_boolean( $this->get_setting_value( 'clean_uninstall' ) );
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
			redirect_uri:  add_query_arg( 'action', 'telegram_signin_callback', wp_login_url() ),
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
		$roles = self::assignable_roles();
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

	/**
	 * Registered roles that are safe to apply to a WordPress account
	 * created for a Telegram sign-in — every role except those carrying a
	 * site-administration capability (see {@see self::PRIVILEGED_CAPABILITIES}).
	 *
	 * @return array<string,mixed>
	 */
	private static function assignable_roles(): array {
		$assignable = array();

		foreach ( self::roles() as $slug => $role ) {
			$capabilities = ( is_array( $role ) && isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) )
				? $role['capabilities']
				: array();

			$privileged = false;
			foreach ( self::PRIVILEGED_CAPABILITIES as $capability ) {
				if ( ! empty( $capabilities[ $capability ] ) ) {
					$privileged = true;
					break;
				}
			}

			if ( ! $privileged ) {
				$assignable[ $slug ] = $role;
			}
		}

		return $assignable;
	}
}
