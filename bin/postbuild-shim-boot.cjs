#!/usr/bin/env node
// @ts-check
/**
 * Stub generator for build/modules/boot/index.min.asset.php.
 *
 * wp-build 0.10 dropped @wordpress/boot from its bundled output ("now expected
 * to be provided by WordPress Core 7.0+ or the Gutenberg plugin"), but the
 * generated page templates at build/pages/<slug>/page.php still gate ALL
 * script enqueueing on file_exists() of this asset file. Without it, the
 * admin page renders an empty <body> with no JS — silently blank.
 *
 * This shim writes a minimal asset descriptor whose `dependencies` are the
 * script handles needed before the inline `import("@wordpress/boot")` resolves.
 * The list is derived from @wordpress/boot's package.json. Handles WP doesn't
 * recognize get silently dropped, so it's safe to list newer Gutenberg-only
 * handles too.
 *
 * Run as a `postbuild` hook in package.json. Once wp-build's template learns
 * to look up @wordpress/boot from Core's script-module registry instead of
 * a local file path, this shim can go away.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const repoRoot = path.resolve( __dirname, '..' );
const targetDir = path.join( repoRoot, 'build', 'modules', 'boot' );
const targetFile = path.join( targetDir, 'index.min.asset.php' );

// Classic-script handles to load before the inline `import("@wordpress/boot")`.
// Listing a handle that isn't registered as a classic script causes WP to
// silently refuse to print the prerequisites script at runtime — verified
// empirically with Gutenberg active. The script-module-only counterparts
// (wp-icons, wp-lazy-editor, wp-route) resolve via the import map at JS
// runtime and don't belong here. wp-base-styles is a style, not a script.
const dependencies = [
	'react-jsx-runtime',
	'wp-a11y',
	'wp-admin-ui',
	'wp-commands',
	'wp-components',
	'wp-compose',
	'wp-core-data',
	'wp-data',
	'wp-editor',
	'wp-element',
	'wp-html-entities',
	'wp-i18n',
	'wp-keyboard-shortcuts',
	'wp-keycodes',
	'wp-notices',
	'wp-primitives',
	'wp-private-apis',
	'wp-theme',
	'wp-url',
];

const version = String( Date.now() );

const phpContent =
	`<?php return array(\n` +
	`	'dependencies' => array(\n` +
	dependencies.map( ( h ) => `\t\t'${ h }',` ).join( '\n' ) +
	`\n\t),\n` +
	`\t'version' => '${ version }',\n` +
	`);\n`;

fs.mkdirSync( targetDir, { recursive: true } );
fs.writeFileSync( targetFile, phpContent );

const rel = path.relative( repoRoot, targetFile );
process.stdout.write(
	`   ✔ Stubbed ${ rel } (${ dependencies.length } deps)\n`
);
