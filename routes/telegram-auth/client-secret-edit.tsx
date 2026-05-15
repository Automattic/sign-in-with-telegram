/**
 * DataForm Edit override for the Client Secret field.
 */

/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	Button,
	__experimentalInputControl as InputControl,
	__experimentalInputControlSuffixWrapper as InputControlSuffixWrapper,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import type { DataFormControlProps } from '@wordpress/dataviews';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { seen, unseen } from '@wordpress/icons';

import type { TelegramAuthSettings } from './types';

export function ClientSecretEdit({
	data,
	field,
	onChange,
}: DataFormControlProps<TelegramAuthSettings>): JSX.Element {
	const [editable, setEditable] = useState(false);
	const [visible, setVisible] = useState(false);
	const inputRef = useRef<HTMLInputElement | null>(null);

	const enable = () => {
		setEditable(true);
		// Defer so React commits the disabled→enabled re-render
		// before we try to focus a now-no-longer-disabled input.
		setTimeout(() => inputRef.current?.focus(), 0);
	};

	return (
		<InputControl
			ref={inputRef}
			__next40pxDefaultSize
			label={field.label}
			help={field.description}
			placeholder={editable ? '' : '*'.repeat(32)}
			type={visible ? 'text' : 'password'}
			value={data.client_secret}
			disabled={!editable}
			autoComplete="off"
			spellCheck={false}
			data-1p-ignore="true"
			data-lpignore="true"
			data-bwignore="true"
			data-form-type="other"
			onChange={(value: string | undefined) =>
				onChange({ client_secret: value ?? '' })
			}
			suffix={
				editable ? (
					<InputControlSuffixWrapper variant="control">
						<Button
							size="small"
							icon={visible ? unseen : seen}
							onClick={() => setVisible((prev) => !prev)}
							label={
								visible
									? __('Hide secret', 'telegram-auth')
									: __('Show secret', 'telegram-auth')
							}
							showTooltip
						/>
					</InputControlSuffixWrapper>
				) : (
					<InputControlSuffixWrapper variant="control">
						<Button
							size="small"
							variant="secondary"
							onClick={enable}
						>
							{__('Edit', 'telegram-auth')}
						</Button>
					</InputControlSuffixWrapper>
				)
			}
		/>
	);
}
