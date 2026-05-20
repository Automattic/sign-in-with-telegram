import { __, sprintf } from '@wordpress/i18n';
import type { Field, Form } from '@wordpress/dataviews';

import { ClientSecretEdit } from './client-secret-edit';
import type { EmailMode, SettingsMeta, TelegramAuthSettings } from './types';
import { createInterpolateElement } from '@wordpress/element';

/**
 * Bot-token shape that BotFather emits for the Bot API token. Common
 * mistake: admins paste it into the OIDC client_secret field. We don't
 * block save, but we warn inline so they catch the swap before testing.
 */
export const BOT_TOKEN_SHAPE = /^\d+:[A-Za-z0-9_-]+$/;

/**
 * Hardcoded WP role list. The plugin's option schema accepts any
 * registered role, but we don't have a clean REST endpoint to list them
 * dynamically — the WP-built-ins cover ~99% of the use case. Sites with
 * custom roles can edit the option directly until we add a role picker.
 */
const ROLE_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
	{ value: 'subscriber', label: __('Subscriber', 'telegram-auth') },
	{ value: 'contributor', label: __('Contributor', 'telegram-auth') },
	{ value: 'author', label: __('Author', 'telegram-auth') },
	{ value: 'editor', label: __('Editor', 'telegram-auth') },
	{ value: 'administrator', label: __('Administrator', 'telegram-auth') },
];

const EMAIL_MODE_OPTIONS: ReadonlyArray<{
	value: EmailMode;
	label: string;
	description: string;
}> = [
	{
		value: 'none',
		label: __('No email', 'telegram-auth'),
		description: __(
			'New users are created without an email. Password recovery is unavailable until they set one themselves.',
			'telegram-auth'
		),
	},
	{
		value: 'placeholder',
		label: __('Placeholder email', 'telegram-auth'),
		description: __(
			'Synthesize a non-routable address like tg_<id>@users.noreply.<host>. Lets password-recovery flows technically run, but the addresses bounce.',
			'telegram-auth'
		),
	},
];

/**
 * Build the field set for the DataForm. The `meta` argument carries the
 * constant-source flags so credential fields can be locked when an
 * override constant is defined in wp-config.
 * @param meta
 */
export function buildFields(meta: SettingsMeta): Field<TelegramAuthSettings>[] {
	return [
		{
			id: 'client_id',
			label: __('Client ID', 'telegram-auth'),
			type: 'text',
			description:
				meta.client_id_source === 'constant'
					? createInterpolateElement(
							sprintf(
								/* translators: %s is the name of a PHP constant, e.g. TELEGRAM_AUTH_CLIENT_ID */
								__(
									'Managed via the %s constant in PHP.',
									'telegram-auth'
								),
								'<code/>'
							),
							{
								code: <code>TELEGRAM_AUTH_CLIENT_ID</code>,
							}
						)
					: __(
							'Get it from @BotFather. Read the instructions above.',
							'telegram-auth'
						),
			isDisabled: meta.client_id_source === 'constant',
		},
		{
			id: 'client_secret',
			label: __('Client Secret', 'telegram-auth'),
			type: 'password',
			placeholder: '*'.repeat(32),
			description:
				meta.client_secret_source === 'constant'
					? createInterpolateElement(
							sprintf(
								/* translators: %s is the name of a PHP constant, e.g. TELEGRAM_AUTH_CLIENT_SECRET */
								__(
									'Managed via the %s constant in PHP.',
									'telegram-auth'
								),
								'<code/>'
							),
							{
								code: <code>TELEGRAM_AUTH_CLIENT_SECRET</code>,
							}
						)
					: __(
							'Redacted for security. Leave blank to keep the stored value, or click Edit to set a new one.',
							'telegram-auth'
						),
			isDisabled: meta.client_secret_source === 'constant',
			Edit:
				meta.client_secret_source === 'constant'
					? undefined
					: ClientSecretEdit,
		},
		{
			id: 'allow_signups',
			label: __('Allow new account creation', 'telegram-auth'),
			type: 'boolean',
			description: __(
				'When enabled, signing in with an unrecognized Telegram account creates a new WordPress user. When disabled, only existing users who have linked their Telegram account can sign in.',
				'telegram-auth'
			),
		},
		{
			id: 'default_role',
			label: __('Default role for new users', 'telegram-auth'),
			type: 'text',
			elements: ROLE_OPTIONS.map(({ value, label }) => ({
				value,
				label,
			})),
			description: __(
				'Role assigned to users created via Telegram sign-in.',
				'telegram-auth'
			),
		},
		{
			id: 'email_mode',
			label: __('Email handling for new users', 'telegram-auth'),
			type: 'text',
			elements: EMAIL_MODE_OPTIONS.map(({ value, label }) => ({
				value,
				label,
			})),
			description: __(
				'Telegram does not share an email address. "No email" leaves the field blank — the user has to add one themselves before they can use password recovery. "Placeholder email" fills in an unreachable address like tg_user@users.noreply.example.com so the account looks complete to WordPress, but any recovery emails sent there will bounce.',
				'telegram-auth'
			),
		},
		{
			id: 'request_phone',
			label: __('Request phone number', 'telegram-auth'),
			type: 'boolean',
			description: __(
				'Ask users to share their Telegram phone number when signing in. If they agree, it is saved to their WordPress profile.',
				'telegram-auth'
			),
		},
		{
			id: 'request_dm',
			label: __('Request permission to send DMs', 'telegram-auth'),
			type: 'boolean',
			description: __(
				'Ask users to let your bot send them direct messages on Telegram. The plugin only records the consent, it does not send any messages.',
				'telegram-auth'
			),
		},
		{
			id: 'button_label',
			label: __('Sign-in button label', 'telegram-auth'),
			type: 'text',
			description: __(
				'Text shown on the "Sign in with Telegram" button across the login screen, shortcode, and Block Editor block.',
				'telegram-auth'
			),
		},
		{
			id: 'post_login_redirect',
			label: __('Default post-login redirect', 'telegram-auth'),
			type: 'text',
			description: __(
				'Optional URL to send users to after a successful login. Must be on the same host as your site. Leave blank to use the WordPress default (admin home).',
				'telegram-auth'
			),
		},
	];
}

/**
 * Layout config for the DataForm. Groups related fields into card
 * sections so the settings page reads as logical clusters (credentials,
 * sign-up policy, optional scopes, presentation) instead of a flat
 * list. Separate from the field config so we can ship the same field
 * set with different layouts in future (compact view, etc.).
 */
export const form: Form = {
	layout: { type: 'card' },
	fields: [
		{
			id: 'section-credentials',
			label: __('Bot credentials', 'telegram-auth'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['client_id', 'client_secret'],
		},
		{
			id: 'section-accounts',
			label: __('New user accounts', 'telegram-auth'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['allow_signups', 'default_role', 'email_mode'],
		},
		{
			id: 'section-permissions',
			label: __('Optional permissions', 'telegram-auth'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['request_phone', 'request_dm'],
		},
		{
			id: 'section-presentation',
			label: __('Sign-in button', 'telegram-auth'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['button_label', 'post_login_redirect'],
		},
	],
};
