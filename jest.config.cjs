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
	],
	transform: {
		'^.+\\.(ts|tsx)$': [
			'ts-jest',
			{ tsconfig: '<rootDir>/tsconfig.json' },
		],
	},
	moduleFileExtensions: [ 'ts', 'tsx', 'js', 'jsx', 'json' ],
};
