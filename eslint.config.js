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
		// failure on every import/* rule that consults the resolver.
		// Disable the affected rules; tsc — and, for bin/, Node itself at
		// runtime — do the real import validation.
		rules: {
			'import/no-extraneous-dependencies': 'off',
			'import/no-duplicates': 'off',
			'import/no-unresolved': 'off',
			'import/named': 'off',
		},
	},
	{
		// TypeScript already carries parameter types in the signature, so
		// JSDoc shouldn't duplicate them. @wordpress/eslint-plugin disables
		// this for *.ts/*.tsx but not *.mts/*.cts — cover every TS extension.
		files: ['**/*.{ts,tsx,mts,cts}'],
		rules: {
			'jsdoc/require-param-type': 'off',
		},
	},
	{
		// bin/ holds standalone Node maintenance scripts — the bin/cli/
		// maintenance CLI and setup-agent.mts, run directly by Node 24 via
		// type-stripping. Console output is their interface, so no-console
		// doesn't apply.
		files: ['bin/**'],
		rules: {
			'no-console': 'off',
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
