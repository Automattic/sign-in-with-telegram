import { __ } from '@wordpress/i18n';

export const stage = () => (
	<div className="wrap">
		<h1>{__('Telegram Auth', 'telegram-auth')}</h1>
		<p>
			{__(
				'Settings UI placeholder — wired up to verify the wp-build pipeline end-to-end.',
				'telegram-auth'
			)}
		</p>
	</div>
);
