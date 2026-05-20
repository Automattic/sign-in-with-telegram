import { __, sprintf } from '@wordpress/i18n';
import type { Field, Form } from '@wordpress/dataviews';

import { ClientSecretEdit } from './client-secret-edit';
import type { EmailMode, SettingsMeta, TelegramSigninSettings } from './types';
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
	{ value: 'subscriber', label: __('Subscriber', 'sign-in-with-telegram') },
	{ value: 'contributor', label: __('Contributor', 'sign-in-with-telegram') },
	{ value: 'author', label: __('Author', 'sign-in-with-telegram') },
	{ value: 'editor', label: __('Editor', 'sign-in-with-telegram') },
	{
		value: 'administrator',
		label: __('Administrator', 'sign-in-with-telegram'),
	},
];

const EMAIL_MODE_OPTIONS: ReadonlyArray<{
	value: EmailMode;
	label: string;
	description: string;
}> = [
	{
		value: 'none',
		label: __('No email', 'sign-in-with-telegram'),
		description: __(
			'New users are created without an email. Password recovery is unavailable until they set one themselves.',
			'sign-in-with-telegram'
		),
	},
	{
		value: 'placeholder',
		label: __('Placeholder email', 'sign-in-with-telegram'),
		description: __(
			'Synthesize a non-routable address like tg_<id>@users.noreply.<host>. Lets password-recovery flows technically run, but the addresses bounce.',
			'sign-in-with-telegram'
		),
	},
];

/**
 * Build the field set for the DataForm. The `meta` argument carries the
 * constant-source flags so credential fields can be locked when an
 * override constant is defined in wp-config.
 * @param meta
 */
export function buildFields(
	meta: SettingsMeta
): Field<TelegramSigninSettings>[] {
	return [
		{
			id: 'client_id',
			label: __('Client ID', 'sign-in-with-telegram'),
			type: 'text',
			description:
				meta.client_id_source === 'constant'
					? createInterpolateElement(
							sprintf(
								/* translators: %s is the name of a PHP constant, e.g. TELEGRAM_SIGNIN_CLIENT_ID */
								__(
									'Managed via the %s constant in PHP.',
									'sign-in-with-telegram'
								),
								'<code/>'
							),
							{
								code: <code>TELEGRAM_SIGNIN_CLIENT_ID</code>,
							}
						)
					: __(
							'Get it from @BotFather. Read the instructions above.',
							'sign-in-with-telegram'
						),
			isDisabled: meta.client_id_source === 'constant',
		},
		{
			id: 'client_secret',
			label: __('Client Secret', 'sign-in-with-telegram'),
			type: 'password',
			placeholder: '*'.repeat(32),
			description:
				meta.client_secret_source === 'constant'
					? createInterpolateElement(
							sprintf(
								/* translators: %s is the name of a PHP constant, e.g. TELEGRAM_SIGNIN_CLIENT_SECRET */
								__(
									'Managed via the %s constant in PHP.',
									'sign-in-with-telegram'
								),
								'<code/>'
							),
							{
								code: (
									<code>TELEGRAM_SIGNIN_CLIENT_SECRET</code>
								),
							}
						)
					: __(
							'Redacted for security. Leave blank to keep the stored value, or click Edit to set a new one.',
							'sign-in-with-telegram'
						),
			isDisabled: meta.client_secret_source === 'constant',
			Edit:
				meta.client_secret_source === 'constant'
					? undefined
					: ClientSecretEdit,
		},
		{
			id: 'allow_signups',
			label: __('Allow new account creation', 'sign-in-with-telegram'),
			type: 'boolean',
			description: __(
				'When enabled, signing in with an unrecognized Telegram account creates a new WordPress user. When disabled, only existing users who have linked their Telegram account can sign in.',
				'sign-in-with-telegram'
			),
		},
		{
			id: 'default_role',
			label: __('Default role for new users', 'sign-in-with-telegram'),
			type: 'text',
			elements: ROLE_OPTIONS.map(({ value, label }) => ({
				value,
				label,
			})),
			description: __(
				'Role assigned to users created via Telegram sign-in.',
				'sign-in-with-telegram'
			),
		},
		{
			id: 'email_mode',
			label: __('Email handling for new users', 'sign-in-with-telegram'),
			type: 'text',
			elements: EMAIL_MODE_OPTIONS.map(({ value, label }) => ({
				value,
				label,
			})),
			description: __(
				'Telegram does not share an email address. "No email" leaves the field blank — the user has to add one themselves before they can use password recovery. "Placeholder email" fills in an unreachable address like tg_user@users.noreply.example.com so the account looks complete to WordPress, but any recovery emails sent there will bounce.',
				'sign-in-with-telegram'
			),
		},
		{
			id: 'request_phone',
			label: __('Request phone number', 'sign-in-with-telegram'),
			type: 'boolean',
			description: __(
				'Ask users to share their Telegram phone number when signing in. If they agree, it is saved to their WordPress profile.',
				'sign-in-with-telegram'
			),
		},
		{
			id: 'request_dm',
			label: __(
				'Request permission to send DMs',
				'sign-in-with-telegram'
			),
			type: 'boolean',
			description: __(
				'Ask users to let your bot send them direct messages on Telegram. The plugin only records the consent, it does not send any messages.',
				'sign-in-with-telegram'
			),
		},
		{
			id: 'button_label',
			label: __('Sign-in button label', 'sign-in-with-telegram'),
			type: 'text',
			description: __(
				'Text shown on the "Sign in with Telegram" button across the login screen, shortcode, and Block Editor block.',
				'sign-in-with-telegram'
			),
		},
		{
			id: 'post_login_redirect',
			label: __('Default post-login redirect', 'sign-in-with-telegram'),
			type: 'text',
			description: __(
				'Optional URL to send users to after a successful login. Must be on the same host as your site. Leave blank to use the WordPress default (admin home).',
				'sign-in-with-telegram'
			),
		},
		{
			id: 'clean_uninstall',
			label: __(
				'Delete plugin data when uninstalled',
				'sign-in-with-telegram'
			),
			type: 'boolean',
			description: __(
				'When this plugin is uninstalled, remove all the settings and user data stored by it.',
				'sign-in-with-telegram'
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
			label: __('Bot credentials', 'sign-in-with-telegram'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['client_id', 'client_secret'],
		},
		{
			id: 'section-accounts',
			label: __('New user accounts', 'sign-in-with-telegram'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['allow_signups', 'default_role', 'email_mode'],
		},
		{
			id: 'section-permissions',
			label: __('Optional permissions', 'sign-in-with-telegram'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['request_phone', 'request_dm'],
		},
		{
			id: 'section-presentation',
			label: __('Sign-in button', 'sign-in-with-telegram'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['button_label', 'post_login_redirect'],
		},
		{
			id: 'section-uninstall',
			label: __('Data clean up', 'sign-in-with-telegram'),
			layout: { type: 'card', withHeader: true, isCollapsible: false },
			children: ['clean_uninstall'],
		},
	],
};
