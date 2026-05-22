/**
 * `sync-version` — set readme.txt's `Stable tag:` from the plugin
 * header's `Version:`.
 *
 * Part of the bin/cli/ maintenance CLI.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { extractPhpVersion, fail, PHP_FILE, README } from './common.mts';

/** Run the `sync-version` command. */
export function syncVersion(): void {
	const version = extractPhpVersion(readFileSync(PHP_FILE, 'utf8'));
	if (!version) {
		fail(`Could not extract Version: from ${PHP_FILE}`);
	}

	const original = readFileSync(README, 'utf8');
	const stableTagLines = original.match(/^Stable tag:.*$/gm) ?? [];
	if (stableTagLines.length !== 1) {
		fail(
			`Expected exactly one 'Stable tag:' line in readme.txt, found ${stableTagLines.length}.`
		);
	}

	const updated = original.replace(
		/^Stable tag:.*$/m,
		`Stable tag: ${version}`
	);
	if (updated !== original) {
		writeFileSync(README, updated);
		console.log(`readme.txt Stable tag set to ${version}.`);
	} else {
		console.log('readme.txt Stable tag already in sync.');
	}
}
