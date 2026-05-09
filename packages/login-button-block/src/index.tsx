/**
 * Telegram Login Button block — editor-side registration.
 *
 * Built by wp-build as the script module `@telegram-auth/login-button-block`
 * and pulled in via block.json's `editorScriptModule`. The front-end markup
 * is server-rendered (Login_Button_Block::render) so the block, the
 * [telegram_auth_button] shortcode, and the wp-login.php auto-print share
 * identical HTML. Editor-side we only render a static placeholder + the
 * InspectorControls for the two attributes.
 */

import { registerBlockType, type BlockEditProps } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import metadata from '../block.json';

interface BlockAttributes {
	label: string;
	redirectTo: string;
	[key: string]: unknown;
}

const PaperPlaneIcon = () => (
	<svg
		className="telegram-auth-login-button__icon"
		width={20}
		height={20}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden
		focusable={false}
	>
		<path d="M21.426 2.574 2.39 10.434c-.84.34-.85 1.518-.014 1.872l4.668 1.984 1.808 5.802c.232.745 1.16.97 1.7.408l2.61-2.713 4.78 3.512c.706.519 1.71.142 1.91-.722l3.43-15.04c.21-.928-.69-1.732-1.564-1.36-.144.06-.28.123-.292.12Z" />
	</svg>
);

const Edit = ({
	attributes,
	setAttributes,
}: BlockEditProps<BlockAttributes>) => {
	const blockProps = useBlockProps();
	const label =
		attributes.label || __('Sign in with Telegram', 'telegram-auth');

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={__('Telegram Auth', 'telegram-auth')}
					initialOpen
				>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={__('Button label', 'telegram-auth')}
						help={__(
							'Leave blank to use the default copy.',
							'telegram-auth'
						)}
						value={attributes.label}
						onChange={(next: string) =>
							setAttributes({ label: next })
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={__('Redirect after sign-in', 'telegram-auth')}
						help={__(
							'Optional URL to send the user to after a successful login.',
							'telegram-auth'
						)}
						value={attributes.redirectTo}
						onChange={(next: string) =>
							setAttributes({ redirectTo: next })
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div {...blockProps}>
				<span
					className="button button-secondary telegram-auth-login-button"
					aria-disabled
				>
					<PaperPlaneIcon />{' '}
					<span className="telegram-auth-login-button__label">
						{label}
					</span>
				</span>
			</div>
		</>
	);
};

// The block.json metadata is the source of truth; passing it as the first
// argument lets the @types/wordpress__blocks overload pick up
// attributes/category/title without us re-declaring them in JS.
registerBlockType<BlockAttributes>(metadata as never, {
	edit: Edit,
	save: () => null,
});
