<?php
/**
 * Block-editor block: Telegram Login Button.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

namespace Telegram_Auth\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `telegram-auth/login-button` and renders it server-side via the
 * existing Login_Button helper, so the block, the [telegram_auth_button]
 * shortcode, and the auto-printed login_form button all share identical
 * markup.
 *
 * The block.json + TSX editor source live under
 * `packages/login-button-block/`. Editor preview is a static placeholder;
 * the front-end markup comes from the render_callback. Server-side
 * rendering keeps client-side logic minimal — just attribute editing in
 * InspectorControls.
 */
class Login_Button_Block {

	/**
	 * Block name registered with WordPress.
	 */
	private const BLOCK_NAME = 'telegram-auth/login-button';

	/**
	 * Script module id for the editor-side registration script. Matches the
	 * id wp-build emits in build/modules/registry.php for our package
	 * (`@{packageNamespace}/{package-name}` from package.json).
	 */
	private const EDITOR_MODULE_ID = '@telegram-auth/login-button-block';

	/**
	 * Build the block registrar.
	 *
	 * @param Login_Button $login_button Reused for the render_callback.
	 */
	public function __construct( private readonly Login_Button $login_button ) {}

	/**
	 * Hook block registration into init + enqueue the editor module on the
	 * block editor screen.
	 *
	 * Core's block.json recognizes `viewScriptModule` but not an
	 * `editorScriptModule` equivalent, so we enqueue the editor module
	 * imperatively via `enqueue_block_editor_assets`. wp-build already
	 * registered it for us via build/modules.php.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_module' ) );
	}

	/**
	 * Enqueue the editor-side script module that calls registerBlockType.
	 */
	public function enqueue_editor_module(): void {
		wp_enqueue_script_module( self::EDITOR_MODULE_ID );
	}

	/**
	 * Register the block from its block.json.
	 *
	 * Supplies a render_callback so the block.json doesn't need a separate
	 * render.php file.
	 */
	public function register_block(): void {
		$block_dir = dirname( __DIR__, 2 ) . '/packages/login-button-block';
		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$block_dir,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Server-rendered output.
	 *
	 * @param array<string,mixed> $attributes Block attributes (label, redirectTo).
	 *
	 * @return string Rendered HTML, wrapped in the block-supplied wrapper attributes.
	 */
	public function render( array $attributes ): string {
		// Sanitize at the boundary — same posture as the shortcode handler.
		$label = isset( $attributes['label'] )
			? trim( sanitize_text_field( (string) $attributes['label'] ) )
			: '';
		if ( '' === $label ) {
			$label = __( 'Sign in with Telegram', 'telegram-auth' );
		}

		$redirect_to = isset( $attributes['redirectTo'] )
			? esc_url_raw( (string) $attributes['redirectTo'] )
			: '';

		$button = $this->login_button->render( $label, $redirect_to );

		// get_block_wrapper_attributes() returns the alignment / spacing
		// styles the editor configured. Wrapping in a span keeps the button
		// inline by default; align values still apply via the wrapper class.
		$wrapper_attrs = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes()
			: '';

		return sprintf( '<span %s>%s</span>', $wrapper_attrs, $button );
	}
}
