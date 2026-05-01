// @ts-check
/**
 * Flat ESLint config — combines:
 * - @eslint/js recommended (the official ESLint baseline)
 * - @wordpress/eslint-plugin recommended (the WordPress baseline, which
 *   auto-detects the installed `typescript` package and layers in
 *   typescript-eslint plus prettier when those are present).
 */
const js = require( '@eslint/js' );
const wp = require( '@wordpress/eslint-plugin' );

module.exports = [
	js.configs.recommended,
	...wp.configs.recommended,
	{
		ignores: [
			'build/**',
			'vendor/**',
			'node_modules/**',
			'packages/*/build/**',
			'packages/*/build-module/**',
			'temp/**',
		],
	},
];
