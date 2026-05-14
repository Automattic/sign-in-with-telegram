import { __ } from '@wordpress/i18n';

import { SettingsApp } from './settings-app';
import './style.scss';

export const stage = () => (
	<div className="wrap telegram-auth-settings">
		<div className="telegram-auth-settings-container">
			<h1 className="telegram-auth-settings-title">
				{__('Telegram Auth', 'telegram-auth')}
			</h1>
			<p className="telegram-auth-settings-subtitle">
				{__(
					'Let visitors sign in to your site with their Telegram account.',
					'telegram-auth'
				)}
			</p>
			<SettingsApp />
		</div>
	</div>
);
