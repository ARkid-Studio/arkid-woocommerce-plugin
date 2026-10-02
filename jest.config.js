const defaultConfig = require('@wordpress/scripts/config/jest-unit.config');

module.exports = {
	...defaultConfig,
	rootDir: __dirname,
	setupFilesAfterEnv: [
		...(defaultConfig.setupFilesAfterEnv || []),
		'<rootDir>/tests/js/setup-dialog.js',
	],
	testPathIgnorePatterns: [
		'/node_modules/',
		'<rootDir>/vendor/',
		'<rootDir>/build/',
		'<rootDir>/tests/e2e/',
	],
	collectCoverageFrom: ['src/**/*.js'],
	coverageReporters: ['text-summary', 'lcov'],
};
