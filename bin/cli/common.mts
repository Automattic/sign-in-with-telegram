/**
 * Shared primitives for the repo maintenance CLI — repository paths, the
 * changelog URL, the failure helper, and the version extractors.
 *
 * Part of the bin/cli/ maintenance CLI; see that file for the Node type-stripping
 * constraints these modules observe.
 */

import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// This file lives at bin/cli/common.mts — two levels below the repo root.
const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

export const PHP_FILE = join(REPO_ROOT, 'sign-in-with-telegram.php');
export const README = join(REPO_ROOT, 'readme.txt');
export const CHANGELOG = join(REPO_ROOT, 'CHANGELOG.md');

// Where readme.txt sends readers for releases older than the latest.
// CHANGELOG.md isn't shipped in the wp.org artifact, so this is a GitHub URL.
export const CHANGELOG_URL =
	'https://github.com/Automattic/sign-in-with-telegram/blob/trunk/CHANGELOG.md';

/**
 * Print a message and exit non-zero.
 *
 * @param message Text written to stderr before exiting.
 */
export function fail(message: string): never {
	console.error(message);
	process.exit(1);
}

/**
 * Extract the plugin's `Version:` header from the main PHP file. The
 * release-please block annotation sits on its own lines and doesn't match
 * `Version:`, so it's ignored.
 *
 * @param php The plugin PHP file's contents.
 */
export function extractPhpVersion(php: string): string | null {
	const match = php.match(/^[ \t]*\*[ \t]*Version:[ \t]*([^\s/]+)/m);
	return match ? match[1] : null;
}

/**
 * Extract the `Stable tag:` value from a readme.txt body.
 *
 * @param readme The readme.txt contents.
 */
export function extractStableTag(readme: string): string | null {
	const match = readme.match(/^Stable tag:[ \t]*([^\s]+)/m);
	return match ? match[1] : null;
}
