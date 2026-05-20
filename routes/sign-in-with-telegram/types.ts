/**
 * Shared types for the Sign in with Telegram settings UI.
 */

export type EmailMode = 'none' | 'placeholder';
export type Source = 'constant' | 'db' | 'unset';

export interface TelegramSigninSettings {
	client_id: string;
	client_secret: string;
	default_role: string;
	allow_signups: boolean;
	email_mode: EmailMode;
	request_phone: boolean;
	request_dm: boolean;
	button_label: string;
	post_login_redirect: string;
	clean_uninstall: boolean;
}

export interface SettingsMeta {
	client_id_source: Source;
	client_secret_source: Source;
}

/**
 * Shape of GET /wp/v2/settings. The endpoint returns every registered
 * setting as a top-level key; we only care about ours.
 */
export interface WpSettingsResponse {
	telegram_signin_settings: TelegramSigninSettings;
}

/**
 * Bootstrap-time data the plugin emits into a window global so the
 * settings UI can render synchronously at mount, without round-tripping
 * the REST API for the initial state.
 */
export interface TelegramSigninData {
	settings: TelegramSigninSettings;
	settingsMeta: SettingsMeta;
	siteOrigin: string;
	redirectUri: string;
}

declare global {
	interface Window {
		telegramSigninData?: TelegramSigninData;
	}
}
