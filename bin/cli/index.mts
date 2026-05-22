#!/usr/bin/env node
/**
 * Repository maintenance CLI — the release/readme plumbing that used to
 * live in bin/*.sh. This file is just the entry point: it parses argv and
 * dispatches. Each command lives in its own module under bin/cli/, with
 * shared primitives in bin/cli/common.mts.
 *
 * Usage:
 *   node bin/cli/index.mts sync-changelog
 *   node bin/cli/index.mts sync-version
 *   node bin/cli/index.mts verify-version <tag>
 *
 * Executed directly by Node 24+ via native type-stripping — no build step,
 * no dependencies. Type-stripping constraints: no enum, no namespace, no
 * parameter-property shorthand, no experimental decorators, use `import type`
 * for type-only imports.
 */

import { parseArgs } from 'node:util';
import { fail } from './common.mts';
import { syncChangelog } from './sync-changelog.mts';
import { syncVersion } from './sync-version.mts';
import { verifyVersion } from './verify-version.mts';

/** Print the command list and exit non-zero. */
function usage(): never {
	console.error('Usage: node bin/cli/index.mts <command>');
	console.error('');
	console.error('Commands:');
	console.error(
		"  sync-changelog          Regenerate readme.txt's == Changelog == from CHANGELOG.md"
	);
	console.error(
		"  sync-version            Set readme.txt's Stable tag from the plugin header Version"
	);
	console.error(
		'  verify-version <tag>    Check Version / Stable tag / release tag agreement'
	);
	process.exit(1);
}

function main(): void {
	const { positionals } = parseArgs({ allowPositionals: true });
	const [command, tag] = positionals;

	switch (command) {
		case 'sync-changelog':
			syncChangelog();
			break;
		case 'sync-version':
			syncVersion();
			break;
		case 'verify-version':
			if (!tag) {
				fail(
					'Usage: node bin/cli/index.mts verify-version <tag>   (e.g. v0.1.2)'
				);
			}
			verifyVersion(tag);
			break;
		default:
			usage();
	}
}

main();
