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

// Script handles @wordpress/boot needs at runtime, derived from its
// package.json dependencies. wp-base-styles is a style, not a script —
// the page template enqueues styles separately. Newer handles
// (wp-admin-ui, wp-lazy-editor, wp-route, wp-theme, wp-private-apis)
// are registered by Gutenberg; the page falls back gracefully when a
// handle is unknown.
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
	'wp-icons',
	'wp-keyboard-shortcuts',
	'wp-keycodes',
	'wp-lazy-editor',
	'wp-notices',
	'wp-primitives',
	'wp-private-apis',
	'wp-route',
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
process.stdout.write( `   ✔ Stubbed ${ rel } (${ dependencies.length } deps)\n` );
