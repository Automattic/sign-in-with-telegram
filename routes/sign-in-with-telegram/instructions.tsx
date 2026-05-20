/**
 * Walkthrough for getting the OIDC Client ID/Secret out of BotFather's
 * Login widget mini app. Renders above the settings form so the
 * bot-token-shape warning ("Read the instructions above") has something
 * to point at.
 */

import { Button, ExternalLink, Panel, PanelBody } from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

interface InstructionsProps {
	siteOrigin: string;
	redirectUri: string;
	initialOpen: boolean;
}

export function Instructions({
	siteOrigin,
	redirectUri,
	initialOpen,
}: InstructionsProps): JSX.Element {
	return (
		<Panel className="sign-in-with-telegram-instructions">
			<PanelBody
				title={__(
					'Instructions (Connect your Telegram bot)',
					'sign-in-with-telegram'
				)}
				initialOpen={initialOpen}
			>
				<p className="sign-in-with-telegram-instructions-intro">
					{__(
						'Sign in with Telegram signs visitors in through your bot using Telegram’s OpenID Connect login. To wire it up, register two URLs with @BotFather and copy back the credentials it gives you.',
						'sign-in-with-telegram'
					)}
				</p>
				<ol className="sign-in-with-telegram-instructions-steps">
					<li>
						{createInterpolateElement(
							__(
								'Open <BotFather /> in Telegram and launch its mini app from the attachment menu, beside the text input.',
								'sign-in-with-telegram'
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
								'sign-in-with-telegram'
							),
							{ strong: <strong /> }
						)}
					</li>
					<li>
						{createInterpolateElement(
							__(
								'Add this URL under <strong>Redirect URIs</strong>:',
								'sign-in-with-telegram'
							),
							{ strong: <strong /> }
						)}
						<CopyableValue value={redirectUri} />
					</li>
					<li>
						{createInterpolateElement(
							__(
								'Add this URL under <strong>Trusted Origins</strong>:',
								'sign-in-with-telegram'
							),
							{ strong: <strong /> }
						)}
						<CopyableValue value={siteOrigin} />
					</li>
					<li>
						{createInterpolateElement(
							__(
								'Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> BotFather shows you, and paste them into the fields below. The Client Secret is not the bot token — they are different values.',
								'sign-in-with-telegram'
							),
							{ strong: <strong /> }
						)}
					</li>
				</ol>
			</PanelBody>
		</Panel>
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
			/*
			 * Clipboard API unavailable (insecure context, etc.) —
			 * the value is still selectable in the <code> block.
			 */
		}
	};

	return (
		<div className="sign-in-with-telegram-copyable">
			<code className="sign-in-with-telegram-copyable-value">
				{value}
			</code>
			<Button
				className="sign-in-with-telegram-copyable-button"
				variant="secondary"
				size="compact"
				onClick={onCopy}
			>
				{copied
					? __('Copied', 'sign-in-with-telegram')
					: __('Copy', 'sign-in-with-telegram')}
			</Button>
		</div>
	);
}
