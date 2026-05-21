/**
 * Flat ESLint config — combines:
 * - @eslint/js recommended (the official ESLint baseline)
 * - @wordpress/eslint-plugin recommended (the WordPress baseline, which
 *   auto-detects the installed `typescript` package and layers in
 *   typescript-eslint plus prettier when those are present).
 */
const js = require('@eslint/js');
const wp = require('@wordpress/eslint-plugin');

module.exports = [
	js.configs.recommended,
	...wp.configs.recommended,
	{
		// eslint-import-resolver-typescript@4 trips on TypeScript 6's API
		// surface ("invalid interface loaded as resolver") and surfaces the
		// failure on every import/* rule that touches the resolver.
		// Disabling the two affected rules; tsc does the real import
		// validation anyway.
		rules: {
			'import/no-extraneous-dependencies': 'off',
			'import/no-duplicates': 'off',
		},
	},
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
