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

import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType, type BlockEditProps } from '@wordpress/blocks';
import { PanelBody, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import metadata from '../block.json';

interface BlockAttributes {
	label: string;
	redirectTo: string;
	[key: string]: unknown;
}

interface PluginSettings {
	button_label?: string;
	post_login_redirect?: string;
}

interface SettingsResponse {
	telegram_auth_settings?: PluginSettings;
}

/*
 * Cache the inherited defaults across block instances within the same
 * editor session. The first Edit mount fetches; subsequent mounts hit
 * the cached promise.
 */
let cachedDefaults: Promise<PluginSettings> | null = null;
function loadInheritedDefaults(): Promise<PluginSettings> {
	if (!cachedDefaults) {
		cachedDefaults = apiFetch<SettingsResponse>({ path: '/wp/v2/settings' })
			.then((res) => res?.telegram_auth_settings ?? {})
			.catch(() => ({}));
	}
	return cachedDefaults;
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
	const [inherited, setInherited] = useState<PluginSettings>({});

	useEffect(() => {
		let active = true;
		loadInheritedDefaults().then((defaults) => {
			if (active) {
				setInherited(defaults);
			}
		});
		return () => {
			active = false;
		};
	}, []);

	const inheritedLabel =
		inherited.button_label?.trim() ||
		__('Sign in with Telegram', 'telegram-auth');
	const inheritedRedirect = inherited.post_login_redirect ?? '';
	const previewLabel = attributes.label || inheritedLabel;

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
							'Leave blank to inherit the site-wide Telegram Auth setting.',
							'telegram-auth'
						)}
						placeholder={inheritedLabel}
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
							'Optional URL to send the user to after a successful login. Leave blank to inherit the site-wide setting.',
							'telegram-auth'
						)}
						placeholder={inheritedRedirect}
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
						{previewLabel}
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
