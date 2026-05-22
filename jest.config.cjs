// @ts-check
/**
 * Jest config. Extends @wordpress/jest-preset-default for the WP-flavoured
 * defaults (jsdom environment, transform pipeline, etc.) and uses ts-jest
 * for the TypeScript test files under each package's src tree.
 */
module.exports = {
	preset: '@wordpress/jest-preset-default',
	testMatch: [
		'<rootDir>/packages/*/src/**/__tests__/**/*.test.{ts,tsx,js,jsx}',
		'<rootDir>/routes/**/__tests__/**/*.test.{ts,tsx,js,jsx}',
	],
	transform: {
		'^.+\\.(ts|tsx)$': [
			'ts-jest',
			{
				tsconfig: '<rootDir>/tsconfig.json',
				// ts-jest forces `module: commonjs` for the Jest runtime,
				// which falls back to TypeScript's legacy node10 module
				// resolution. TS 6 deprecates node10; silence the warning.
				diagnostics: { ignoreCodes: [5107] },
			},
		],
	},
	moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx', 'json'],
};
