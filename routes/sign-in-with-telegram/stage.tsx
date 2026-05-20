import { __ } from '@wordpress/i18n';

import { SettingsApp } from './settings-app';
import './style.scss';

export const stage = () => (
	<div className="wrap sign-in-with-telegram-settings">
		<div className="sign-in-with-telegram-settings-container">
			<h1 className="sign-in-with-telegram-settings-title">
				{__('Sign in with Telegram', 'sign-in-with-telegram')}
			</h1>
			<p className="sign-in-with-telegram-settings-subtitle">
				{__(
					'Let visitors sign in to your site with their Telegram account.',
					'sign-in-with-telegram'
				)}
			</p>
			<SettingsApp />
		</div>
	</div>
);
