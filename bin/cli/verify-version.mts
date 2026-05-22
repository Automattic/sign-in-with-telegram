/**
 * `verify-version` — check that the plugin `Version:` header, the
 * `TELEGRAM_SIGNIN_VERSION` constant, the readme `Stable tag:`, and a
 * release tag all agree.
 *
 * Part of the bin/cli/ maintenance CLI.
 */

import { readFileSync } from 'node:fs';
import {
	extractPhpVersion,
	extractStableTag,
	extractVersionConstant,
	fail,
	PHP_FILE,
	README,
} from './common.mts';

/**
 * Run the `verify-version` command.
 *
 * @param tag The release tag to check, e.g. `v0.1.2`.
 */
export function verifyVersion(tag: string): void {
	const tagVersion = tag.replace(/^v/, '');
	const php = readFileSync(PHP_FILE, 'utf8');
	const phpVersion = extractPhpVersion(php);
	const constantVersion = extractVersionConstant(php);
	const readmeVersion = extractStableTag(readFileSync(README, 'utf8'));

	console.log(`release tag:  ${tagVersion}`);
	console.log(`PHP header:   ${phpVersion ?? '(not found)'}`);
	console.log(`PHP constant: ${constantVersion ?? '(not found)'}`);
	console.log(`readme.txt:   ${readmeVersion ?? '(not found)'}`);

	if (!phpVersion || !constantVersion || !readmeVersion) {
		fail(
			'::error::Could not extract a version from one of the source files.'
		);
	}
	if (
		tagVersion !== phpVersion ||
		tagVersion !== constantVersion ||
		tagVersion !== readmeVersion
	) {
		fail('::error::Version mismatch — refusing to publish.');
	}

	console.log(`All four agree on ${tagVersion}.`);
}
