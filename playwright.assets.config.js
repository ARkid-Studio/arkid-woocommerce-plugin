const { defineConfig, devices } = require('@playwright/test');
const path = require('path');
const config = require('./tests/store-assets/config');

/**
 * Playwright config for the WordPress.org screenshots — NOT the test suite.
 *
 * Kept apart from playwright.config.js on purpose. The e2e suite stays offline
 * and deterministic (it stubs the ARkid API and never loads a real viewer);
 * this run needs the opposite, because the point of a screenshot is the real
 * 3D viewer from catalogue.arkid.app and the real product cover images. A flaky
 * network must be able to fail THIS job without ever touching a PR's checks.
 *
 *   npm run assets:screenshots
 */
module.exports = defineConfig({
	testDir: './tests/store-assets',
	testMatch: /screenshots\.spec\.js/,
	globalSetup: require.resolve('./tests/store-assets/global-setup.js'),
	globalTeardown: require.resolve('./tests/store-assets/global-teardown.js'),
	outputDir: './tests/_output/store-assets',
	// Generous: the viewer downloads a model and renders it on a software GL
	// stack, and each shot waits for that.
	timeout: 120_000,
	expect: { timeout: 15_000 },
	// One site, one database, serial shots that depend on each other.
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['github'], ['list']] : [['list']],
	use: {
		...devices['Desktop Chrome'],
		baseURL: config.BASE_URL,
		storageState: path.join(config.STATE_DIR, 'admin.json'),
		// 1280x800 CSS pixels at 2x: sharp when a visitor clicks a screenshot
		// open on the plugin page, and still a normal-sized PNG.
		viewport: { width: 1280, height: 800 },
		deviceScaleFactor: 2,
		locale: 'en-US',
		launchOptions: {
			// Lets a machine whose Playwright browser build does not match the
			// installed @playwright/test point at a Chromium it does have.
			executablePath: process.env.CHROMIUM_PATH || undefined,
			args: config.CHROMIUM_ARGS,
		},
		trace: 'off',
		video: 'off',
		screenshot: 'only-on-failure',
		actionTimeout: 15_000,
	},
});
