import { BOT_TOKEN_SHAPE, buildFields, form } from '../fields';
import type { SettingsMeta } from '../types';

const ALL_UNSET: SettingsMeta = {
	client_id_source: 'unset',
	client_secret_source: 'unset',
};

const CLIENT_ID_CONSTANT: SettingsMeta = {
	client_id_source: 'constant',
	client_secret_source: 'db',
};

const CLIENT_SECRET_CONSTANT: SettingsMeta = {
	client_id_source: 'db',
	client_secret_source: 'constant',
};

describe('buildFields', () => {
	it('returns the complete field set in declaration order', () => {
		const fields = buildFields(ALL_UNSET);
		expect(fields.map((f) => f.id)).toEqual([
			'client_id',
			'client_secret',
			'allow_signups',
			'default_role',
			'email_mode',
			'request_phone',
			'request_dm',
			'button_label',
			'post_login_redirect',
		]);
	});

	it('locks the client_id field when the source is a constant', () => {
		const fields = buildFields(CLIENT_ID_CONSTANT);
		const clientId = fields.find((f) => f.id === 'client_id');
		const clientSecret = fields.find((f) => f.id === 'client_secret');

		expect(clientId?.isDisabled).toBe(true);
		expect(clientSecret?.isDisabled).toBe(false);
	});

	it('locks the client_secret field when the source is a constant', () => {
		const fields = buildFields(CLIENT_SECRET_CONSTANT);
		const clientId = fields.find((f) => f.id === 'client_id');
		const clientSecret = fields.find((f) => f.id === 'client_secret');

		expect(clientId?.isDisabled).toBe(false);
		expect(clientSecret?.isDisabled).toBe(true);
	});

	it('leaves both fields editable when nothing is constant-pinned', () => {
		const fields = buildFields(ALL_UNSET);
		expect(fields.find((f) => f.id === 'client_id')?.isDisabled).toBe(
			false
		);
		expect(fields.find((f) => f.id === 'client_secret')?.isDisabled).toBe(
			false
		);
	});
});

describe('form layout', () => {
	it('matches the buildFields ids', () => {
		const fieldIds = new Set(buildFields(ALL_UNSET).map((f) => f.id));
		for (const id of form.fields ?? []) {
			expect(fieldIds.has(id as string)).toBe(true);
		}
	});
});

describe('BOT_TOKEN_SHAPE', () => {
	it('matches a bot-token-shaped value (numeric id : alphanumeric token)', () => {
		expect(BOT_TOKEN_SHAPE.test('123456789:ABCdef-_123')).toBe(true);
	});

	it('does not match a typical OIDC client secret', () => {
		// Real OIDC secrets are URL-safe random strings without a leading numeric id.
		expect(BOT_TOKEN_SHAPE.test('abc123-_xyz')).toBe(false);
		expect(BOT_TOKEN_SHAPE.test('')).toBe(false);
	});
});
