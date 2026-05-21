#!/usr/bin/env node
/**
 * Post-build patches applied to wp-build's output:
 *
 *   1. Provide build/modules/boot/index.min.asset.php from the
 *      automattic/jetpack-wp-build-polyfills composer package. wp-build's
 *      generated page templates at build/pages/<slug>/page.php gate ALL
 *      script enqueueing on file_exists() of that asset file. wp-build@0.10
 *      stopped emitting it, so without this copy the admin page renders an
 *      empty <body> with no JS — silently blank. Tracked upstream at
 *      https://github.com/WordPress/gutenberg/issues/77883.
 *
 *   2. Prepend `defined( 'ABSPATH' ) || exit;` to every generated PHP file
 *      under build/. wp-build doesn't emit the guard, and
 *      WordPress/plugin-check-action flags every .php file lacking one.
 *      Cheap to add and idempotent (the walk skips files that already
 *      carry an ABSPATH check).
 *
 * Run as part of `npm run build` (see package.json).
 */

const fs = require('node:fs');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '..');

// 1. Boot-asset shim.
const bootSource = path.join(
	repoRoot,
	'vendor/automattic/jetpack-wp-build-polyfills/build/modules/boot/index.asset.php'
);
const bootTargetDir = path.join(repoRoot, 'build/modules/boot');
const bootTarget = path.join(bootTargetDir, 'index.min.asset.php');

if (!fs.existsSync(bootSource)) {
	process.stderr.write(
		`✘ Polyfill boot asset not found at ${path.relative(repoRoot, bootSource)}.\n` +
			`  Run 'composer install' first.\n`
	);
	process.exit(1);
}

fs.mkdirSync(bootTargetDir, { recursive: true });
fs.copyFileSync(bootSource, bootTarget);

process.stdout.write(
	`   ✔ Copied ${path.relative(repoRoot, bootSource)} → ${path.relative(repoRoot, bootTarget)}\n`
);

// 2. ABSPATH guards.
const buildDir = path.join(repoRoot, 'build');
const guardLine = "defined( 'ABSPATH' ) || exit;";
let patched = 0;
let skipped = 0;

for (const file of walkPhpFiles(buildDir)) {
	const contents = fs.readFileSync(file, 'utf8');
	if (/defined\s*\(\s*['"]ABSPATH['"]\s*\)/.test(contents)) {
		skipped += 1;
		continue;
	}

	// wp-build emits two PHP shapes:
	//   - multi-line files starting with `<?php\n` (or `<?php\r\n`)
	//   - single-line asset.php files of the form `<?php return array(...);`
	// Insert the guard right after the opening tag in both cases.
	const replaced = contents.replace(/^<\?php(\r?\n|\s+)/, (_, sep) => {
		const eol = sep.includes('\n') ? sep : '\n';
		return `<?php${eol}${eol}${guardLine}${eol}${eol}`;
	});

	if (replaced === contents) {
		process.stderr.write(
			`✘ Could not insert ABSPATH guard into ${path.relative(repoRoot, file)} ` +
				`— unexpected opening sequence.\n`
		);
		process.exit(1);
	}

	fs.writeFileSync(file, replaced);
	patched += 1;
}

process.stdout.write(
	`   ✔ ABSPATH guard inserted into ${patched} PHP file(s) under build/ ` +
		`(${skipped} already had one)\n`
);

/**
 * Yield every *.php path under `dir` recursively.
 *
 * @param {string} dir
 * @return {Generator<string>}
 */
function* walkPhpFiles(dir) {
	if (!fs.existsSync(dir)) {
		return;
	}
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			yield* walkPhpFiles(full);
		} else if (entry.isFile() && entry.name.endsWith('.php')) {
			yield full;
		}
	}
}
