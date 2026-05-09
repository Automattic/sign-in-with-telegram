#!/usr/bin/env node
/**
 * Provide build/modules/boot/index.min.asset.php from the
 * automattic/jetpack-wp-build-polyfills composer package.
 *
 * wp-build's generated page templates at build/pages/<slug>/page.php gate
 * ALL script enqueueing on file_exists() of build/modules/boot/index.min.asset.php.
 * wp-build@0.10 stopped emitting that file, so without it the admin page
 * renders an empty <body> with no JS — silently blank.
 *
 * The polyfills package ships a properly-built asset.php with the correct
 * classic-script dependencies (no script-module-only handles, which would
 * cause WP to silently refuse to print the prerequisites script).
 *
 * Run as a `postbuild` hook in package.json. Replaces the equivalent
 * `provide-boot-asset-file` bin script that the polyfill's npm twin would
 * ship if it were published — the npm package is private, the composer
 * package doesn't carry the bin/ directory.
 *
 * Tracked upstream: https://github.com/WordPress/gutenberg/issues/77883
 */

const fs = require('node:fs');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '..');
const source = path.join(
	repoRoot,
	'vendor/automattic/jetpack-wp-build-polyfills/build/modules/boot/index.asset.php'
);
const targetDir = path.join(repoRoot, 'build/modules/boot');
const target = path.join(targetDir, 'index.min.asset.php');

if (!fs.existsSync(source)) {
	process.stderr.write(
		`✘ Polyfill boot asset not found at ${path.relative(repoRoot, source)}.\n` +
			`  Run 'composer install' first.\n`
	);
	process.exit(1);
}

fs.mkdirSync(targetDir, { recursive: true });
fs.copyFileSync(source, target);

process.stdout.write(
	`   ✔ Copied ${path.relative(repoRoot, source)} → ${path.relative(repoRoot, target)}\n`
);
