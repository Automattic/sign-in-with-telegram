/**
 * `verify-version` — check that the plugin `Version:`, readme
 * `Stable tag:`, and a release tag all agree.
 *
 * Part of the bin/cli/ maintenance CLI.
 */

import { readFileSync } from 'node:fs';
import {
	extractPhpVersion,
	extractStableTag,
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
	const phpVersion = extractPhpVersion(readFileSync(PHP_FILE, 'utf8'));
	const readmeVersion = extractStableTag(readFileSync(README, 'utf8'));

	console.log(`release tag: ${tagVersion}`);
	console.log(`PHP header:  ${phpVersion ?? '(not found)'}`);
	console.log(`readme.txt:  ${readmeVersion ?? '(not found)'}`);

	if (!phpVersion || !readmeVersion) {
		fail(
			'::error::Could not extract a version from one of the source files.'
		);
	}
	if (tagVersion !== phpVersion || tagVersion !== readmeVersion) {
		fail('::error::Version mismatch — refusing to publish.');
	}

	console.log(`All three agree on ${tagVersion}.`);
}
