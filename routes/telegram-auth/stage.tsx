import { __ } from '@wordpress/i18n';

import { SettingsApp } from './settings-app';

export const stage = () => (
	<div className="wrap telegram-auth-settings">
		<h1>{__('Telegram Auth', 'telegram-auth')}</h1>
		<SettingsApp />
	</div>
);
