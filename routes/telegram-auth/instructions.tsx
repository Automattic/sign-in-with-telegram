/**
 * Walkthrough for getting the OIDC Client ID/Secret out of BotFather's
 * Login widget mini app. Renders above the settings form so the
 * bot-token-shape warning ("Read the instructions above") has something
 * to point at.
 */

import { Button, ExternalLink } from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

interface InstructionsProps {
	siteOrigin: string;
	redirectUri: string;
}

export function Instructions({
	siteOrigin,
	redirectUri,
}: InstructionsProps): JSX.Element {
	return (
		<section className="telegram-auth-instructions">
			<h2>{__('Connect your Telegram bot', 'telegram-auth')}</h2>
			<p>
				{__(
					'Telegram Auth signs visitors in through your bot using Telegram’s OpenID Connect login. To wire it up, register two URLs with @BotFather and copy back the credentials it gives you.',
					'telegram-auth'
				)}
			</p>
			<ol>
				<li>
					{createInterpolateElement(
						__(
							'Open <BotFather /> in Telegram and launch its mini app from the attachment menu, beside the text input.',
							'telegram-auth'
						),
						{
							BotFather: (
								<ExternalLink href="https://t.me/BotFather">
									@BotFather
								</ExternalLink>
							),
						}
					)}
				</li>
				<li>
					{createInterpolateElement(
						__(
							'Pick your bot under <strong>My bots</strong>, then open <strong>Login widget</strong>. If your bot is still on the legacy widget, choose <strong>Switch to OpenID Connect Login</strong> and confirm.',
							'telegram-auth'
						),
						{ strong: <strong /> }
					)}
				</li>
				<li>
					{createInterpolateElement(
						__(
							'Add this URL under <strong>Redirect URIs</strong>:',
							'telegram-auth'
						),
						{ strong: <strong /> }
					)}
					<CopyableValue value={redirectUri} />
				</li>
				<li>
					{createInterpolateElement(
						__(
							'Add this URL under <strong>Trusted Origins</strong>:',
							'telegram-auth'
						),
						{ strong: <strong /> }
					)}
					<CopyableValue value={siteOrigin} />
				</li>
				<li>
					{createInterpolateElement(
						__(
							'Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> BotFather shows you, and paste them into the fields below. The Client Secret is not the bot token — they are different values.',
							'telegram-auth'
						),
						{ strong: <strong /> }
					)}
				</li>
			</ol>
		</section>
	);
}

function CopyableValue({ value }: { value: string }): JSX.Element {
	const [copied, setCopied] = useState(false);

	const onCopy = async () => {
		try {
			await navigator.clipboard.writeText(value);
			setCopied(true);
			window.setTimeout(() => setCopied(false), 2000);
		} catch {
			// Clipboard API unavailable (insecure context, etc.) — the
			// value is still selectable in the <code> block.
		}
	};

	return (
		<div>
			<code>{value}</code>
			&nbsp;
			<Button variant="secondary" size="small" onClick={onCopy}>
				{copied
					? __('Copied', 'telegram-auth')
					: __('Copy', 'telegram-auth')}
			</Button>
		</div>
	);
}
