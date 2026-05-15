import { diff, errorMessage, preserveConstants } from '../settings-app';
import type { SettingsMeta, TelegramAuthSettings } from '../types';

const baseline: TelegramAuthSettings = {
	client_id: '8675309',
	client_secret: 'shh',
	default_role: 'subscriber',
	allow_signups: true,
	email_mode: 'none',
	request_phone: false,
	request_dm: false,
	button_label: 'Sign in with Telegram',
	post_login_redirect: '',
	clean_uninstall: false,
};

describe('diff', () => {
	it('returns an empty patch when nothing changed', () => {
		expect(diff(baseline, baseline)).toEqual({});
	});

	it('includes only the changed primitive fields', () => {
		const next: TelegramAuthSettings = {
			...baseline,
			button_label: 'Sign in',
			request_phone: true,
		};
		expect(diff(baseline, next)).toEqual({
			button_label: 'Sign in',
			request_phone: true,
		});
	});
});

describe('preserveConstants', () => {
	const meta = (
		client: SettingsMeta['client_id_source'],
		secret: SettingsMeta['client_secret_source']
	): SettingsMeta => ({
		client_id_source: client,
		client_secret_source: secret,
	});

	it('passes saved values through when nothing is constant-pinned', () => {
		const saved: TelegramAuthSettings = {
			...baseline,
			client_id: 'db-id',
			client_secret: 'db-secret',
		};
		const previous: TelegramAuthSettings = {
			...baseline,
			client_id: 'db-id',
			client_secret: 'db-secret',
		};
		const out = preserveConstants(saved, previous, meta('db', 'db'));
		expect(out.client_id).toBe('db-id');
		expect(out.client_secret).toBe('db-secret');
	});

	it('carries the previous client_id forward when the source is constant', () => {
		const saved: TelegramAuthSettings = {
			...baseline,
			client_id: '',
			button_label: 'updated',
		};
		const previous: TelegramAuthSettings = {
			...baseline,
			client_id: 'constant-value',
		};
		const out = preserveConstants(saved, previous, meta('constant', 'db'));
		expect(out.client_id).toBe('constant-value');
		expect(out.button_label).toBe('updated');
	});

	it('carries the previous client_secret forward when the source is constant', () => {
		const saved: TelegramAuthSettings = {
			...baseline,
			client_secret: '',
		};
		const previous: TelegramAuthSettings = {
			...baseline,
			client_secret: 'constant-secret',
		};
		const out = preserveConstants(saved, previous, meta('db', 'constant'));
		expect(out.client_secret).toBe('constant-secret');
	});

	it('preserves both credentials when both sources are constant', () => {
		const saved: TelegramAuthSettings = {
			...baseline,
			client_id: '',
			client_secret: '',
		};
		const previous: TelegramAuthSettings = {
			...baseline,
			client_id: 'pinned-id',
			client_secret: 'pinned-secret',
		};
		const out = preserveConstants(
			saved,
			previous,
			meta('constant', 'constant')
		);
		expect(out.client_id).toBe('pinned-id');
		expect(out.client_secret).toBe('pinned-secret');
	});
});

describe('errorMessage', () => {
	it('extracts the message from an object with a string message property', () => {
		expect(errorMessage({ message: 'Forbidden' })).toBe('Forbidden');
	});

	it('returns a string error unchanged', () => {
		expect(errorMessage('oh no')).toBe('oh no');
	});

	it('falls back to a generic translated message for unknown shapes', () => {
		expect(errorMessage(undefined)).toBe('Unknown error');
		expect(errorMessage({})).toBe('Unknown error');
		expect(errorMessage(42)).toBe('Unknown error');
	});
});
