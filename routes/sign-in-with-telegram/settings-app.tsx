/**
 * Sign in with Telegram settings app.
 *
 * Reads the persisted option + the constant-source flags off the
 * `window.telegramSigninData` global that Bootstrap injects at page
 * render — no fetch on mount, the form renders synchronously. Save
 * batches only the dirty fields back to `/wp/v2/settings` (relies on
 * `Settings::sanitize` merging the patch over the stored option so
 * untouched fields keep their values).
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SnackbarList } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DataForm } from '@wordpress/dataviews';

import { buildFields, form, BOT_TOKEN_SHAPE } from './fields';
import { Instructions } from './instructions';
import type {
	SettingsMeta,
	TelegramSigninData,
	TelegramSigninSettings,
	WpSettingsResponse,
} from './types';

type Snack = {
	id: string;
	content: string;
};

export function SettingsApp(): JSX.Element {
	const data = window.telegramSigninData;
	if (!data) {
		return (
			<Notice status="error" isDismissible={false}>
				{__(
					'Sign in with Telegram settings could not be loaded. Try reloading the page.',
					'sign-in-with-telegram'
				)}
			</Notice>
		);
	}

	return <SettingsAppInner data={data} />;
}

function SettingsAppInner({ data }: { data: TelegramSigninData }): JSX.Element {
	const [original, setOriginal] = useState<TelegramSigninSettings>(
		data.settings
	);
	const [draft, setDraft] = useState<TelegramSigninSettings>(data.settings);
	const [saving, setSaving] = useState(false);
	const [snacks, setSnacks] = useState<Snack[]>([]);
	// Bumps on every successful save. Used as DataForm's `key` so the
	// form remounts fresh — resets per-field control state like
	// ClientSecretEdit's `editable` so the secret returns to its
	// disabled-with-Edit-suffix state once the value has been stored.
	const [savedTick, setSavedTick] = useState(0);

	const fields = buildFields(data.settingsMeta);

	const dirty = diff(original, draft);
	const hasChanges = Object.keys(dirty).length > 0;
	const botTokenWarning =
		typeof draft.client_secret === 'string' &&
		draft.client_secret !== '' &&
		BOT_TOKEN_SHAPE.test(draft.client_secret);

	const onChange = (patch: Record<string, unknown>) => {
		setDraft((previous) => ({
			...previous,
			...(patch as Partial<TelegramSigninSettings>),
		}));
	};

	const pushSnack = (content: string) => {
		setSnacks((prev) => [
			...prev,
			{
				id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
				content,
			},
		]);
	};

	const removeSnack = (id: string) => {
		setSnacks((prev) => prev.filter((s) => s.id !== id));
	};

	const onSave = async () => {
		if (!hasChanges) {
			return;
		}
		setSaving(true);
		try {
			const response = await apiFetch<WpSettingsResponse>({
				path: '/wp/v2/settings',
				method: 'POST',
				data: { telegram_signin_settings: dirty },
			});
			const saved = preserveConstants(
				response.telegram_signin_settings,
				original,
				data.settingsMeta
			);
			setOriginal(saved);
			setDraft(saved);
			setSavedTick((tick) => tick + 1);
			pushSnack(__('Settings saved.', 'sign-in-with-telegram'));
		} catch (err: unknown) {
			pushSnack(
				sprintf(
					/* translators: %s is the underlying API error message. */
					__('Could not save settings: %s', 'sign-in-with-telegram'),
					errorMessage(err)
				)
			);
		} finally {
			setSaving(false);
		}
	};

	return (
		<div className="sign-in-with-telegram-settings-app">
			<Instructions
				siteOrigin={data.siteOrigin}
				redirectUri={data.redirectUri}
				initialOpen={!data.settings.client_id}
			/>

			<DataForm<TelegramSigninSettings>
				key={savedTick}
				data={draft}
				fields={fields}
				form={form}
				onChange={onChange}
			/>

			{botTokenWarning && (
				<Notice status="warning" isDismissible={false}>
					{sprintf(
						'%1$s %2$s',
						__(
							'That looks like a Telegram Bot token, not the Client Secret.',
							'sign-in-with-telegram'
						),
						__(
							'Read the instructions above.',
							'sign-in-with-telegram'
						)
					)}
				</Notice>
			)}

			<div className="sign-in-with-telegram-settings-actions">
				<Button
					variant="primary"
					onClick={onSave}
					disabled={!hasChanges || saving}
					isBusy={saving}
				>
					{saving
						? __('Saving…', 'sign-in-with-telegram')
						: __('Save settings', 'sign-in-with-telegram')}
				</Button>
			</div>

			<SnackbarList
				className="sign-in-with-telegram-snackbars"
				notices={snacks.map((s) => ({
					id: s.id,
					content: s.content,
				}))}
				onRemove={removeSnack}
			/>
		</div>
	);
}

/**
 * Re-apply constant-pinned credential values to a REST response.
 *
 * The settings REST endpoint returns the *stored* option, which has no
 * knowledge of wp-config constants. The initial draft was seeded from
 * `Settings::get_all()` (constant-resolved), so a save would otherwise
 * flip the disabled credential field from the constant value to an
 * empty DB value until the page reloads. Carry the original value
 * forward whenever the source is `constant`.
 *
 * @param saved    Settings as returned by the REST API.
 * @param previous Settings as we last had them (constant-resolved).
 * @param meta     Source flags captured at mount.
 */
export function preserveConstants(
	saved: TelegramSigninSettings,
	previous: TelegramSigninSettings,
	meta: SettingsMeta
): TelegramSigninSettings {
	const out = { ...saved };
	if (meta.client_id_source === 'constant') {
		out.client_id = previous.client_id;
	}
	if (meta.client_secret_source === 'constant') {
		out.client_secret = previous.client_secret;
	}
	return out;
}

/**
 * Compute the dirty subset of `next` relative to `prev` so we only
 * send the fields the user actually changed. Settings::sanitize
 * merges the partial payload over the stored option, so untouched
 * fields keep their values.
 *
 * @param prev Last loaded / saved state.
 * @param next Current draft state.
 */
export function diff(
	prev: TelegramSigninSettings,
	next: TelegramSigninSettings
): Partial<TelegramSigninSettings> {
	const out: Partial<TelegramSigninSettings> = {};
	for (const key of Object.keys(next) as Array<
		keyof TelegramSigninSettings
	>) {
		if (prev[key] !== next[key]) {
			out[key] = next[key] as never;
		}
	}
	return out;
}

/**
 * Pull a presentable message out of whatever apiFetch threw.
 *
 * @param err Thrown value (anything Promise.reject can carry).
 */
export function errorMessage(err: unknown): string {
	if (
		err &&
		typeof err === 'object' &&
		'message' in err &&
		typeof err.message === 'string'
	) {
		return err.message;
	}
	if (typeof err === 'string') {
		return err;
	}
	return __('Unknown error', 'sign-in-with-telegram');
}
