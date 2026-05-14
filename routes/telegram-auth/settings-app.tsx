/**
 * Telegram Auth settings app.
 *
 * Reads the persisted option + the constant-source flags off the
 * `window.telegramAuthData` global that Bootstrap injects at page
 * render — no fetch on mount, the form renders synchronously. Save
 * batches only the dirty fields back to `/wp/v2/settings` (relies on
 * `Settings::sanitize` merging the patch over the stored option so
 * untouched fields keep their values).
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { DataForm } from '@wordpress/dataviews';

import { buildFields, form, BOT_TOKEN_SHAPE } from './fields';
import { Instructions } from './instructions';
import type {
	TelegramAuthData,
	TelegramAuthSettings,
	WpSettingsResponse,
} from './types';

type SaveState =
	| { status: 'idle' }
	| { status: 'saving' }
	| { status: 'saved' }
	| { status: 'error'; message: string };

export function SettingsApp(): JSX.Element {
	const data = window.telegramAuthData;
	if (!data) {
		return (
			<Notice status="error" isDismissible={false}>
				{__(
					'Telegram Auth settings could not be loaded. Try reloading the page.',
					'telegram-auth'
				)}
			</Notice>
		);
	}

	return <SettingsAppInner data={data} />;
}

function SettingsAppInner({ data }: { data: TelegramAuthData }): JSX.Element {
	const [original, setOriginal] = useState<TelegramAuthSettings>(
		data.settings
	);
	const [draft, setDraft] = useState<TelegramAuthSettings>(data.settings);
	const [saveState, setSaveState] = useState<SaveState>({ status: 'idle' });

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
			...(patch as Partial<TelegramAuthSettings>),
		}));
		if (saveState.status === 'saved' || saveState.status === 'error') {
			setSaveState({ status: 'idle' });
		}
	};

	const onSave = async () => {
		if (!hasChanges) {
			return;
		}
		setSaveState({ status: 'saving' });
		try {
			const response = await apiFetch<WpSettingsResponse>({
				path: '/wp/v2/settings',
				method: 'POST',
				data: { telegram_auth_settings: dirty },
			});
			const saved = response.telegram_auth_settings;
			setOriginal(saved);
			setDraft(saved);
			setSaveState({ status: 'saved' });
		} catch (err: unknown) {
			setSaveState({ status: 'error', message: errorMessage(err) });
		}
	};

	return (
		<>
			<Instructions
				siteOrigin={data.siteOrigin}
				redirectUri={data.redirectUri}
			/>

			<DataForm<TelegramAuthSettings>
				data={draft}
				fields={fields}
				form={form}
				onChange={onChange}
			/>

			{botTokenWarning && (
				<Notice status="warning" isDismissible={false}>
					{__(
						'That looks like a Telegram Bot token, not the Client Secret.',
						'telegram-auth'
					) +
						' ' +
						__('Read the instructions above.', 'telegram-auth')}
				</Notice>
			)}
			{saveState.status === 'saved' && (
				<Notice
					status="success"
					onRemove={() => setSaveState({ status: 'idle' })}
				>
					{__('Settings saved.', 'telegram-auth')}
				</Notice>
			)}
			{saveState.status === 'error' && (
				<Notice
					status="error"
					onRemove={() => setSaveState({ status: 'idle' })}
				>
					{__('Could not save settings:', 'telegram-auth')}{' '}
					{saveState.message}
				</Notice>
			)}

			<div className="telegram-auth-settings-actions">
				<Button
					variant="primary"
					onClick={onSave}
					disabled={!hasChanges || saveState.status === 'saving'}
					isBusy={saveState.status === 'saving'}
				>
					{saveState.status === 'saving'
						? __('Saving…', 'telegram-auth')
						: __('Save settings', 'telegram-auth')}
				</Button>
			</div>
		</>
	);
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
function diff(
	prev: TelegramAuthSettings,
	next: TelegramAuthSettings
): Partial<TelegramAuthSettings> {
	const out: Partial<TelegramAuthSettings> = {};
	for (const key of Object.keys(next) as Array<keyof TelegramAuthSettings>) {
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
function errorMessage(err: unknown): string {
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
	return __('Unknown error', 'telegram-auth');
}
