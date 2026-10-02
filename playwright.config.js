const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
	testDir: './tests/e2e/specs',
	globalSetup: require.resolve('./tests/e2e/global-setup.js'),
	globalTeardown: require.resolve('./tests/e2e/global-teardown.js'),
	outputDir: './tests/_output/e2e',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	// One shared WordPress install and one database: parallel workers would race
	// on options and product meta. The suite is small enough that serial is fine.
	workers: 1,
	fullyParallel: false,
	forbidOnly: Boolean(process.env.CI),
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI
		? [['github'], ['html', { outputFolder: 'tests/_output/e2e-report', open: 'never' }]]
		: [['list']],
	use: {
		// Apache serves 8080 and 8888 inside the container, and WordPress stores
		// its URL as :8888, so this resolves identically inside and out.
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		storageState: 'tests/e2e/.auth/admin.json',
		trace: 'retain-on-failure',
		video: 'retain-on-failure',
		screenshot: 'only-on-failure',
		actionTimeout: 15_000,
	},
	// One project per THEME FAMILY, not per browser.
	//
	// The plugin's risk is not "does this work in Firefox" — it is that classic
	// and block themes build the product page through entirely different
	// machinery, and the plugin has to hook both. "Below product" shipped broken
	// on every block theme precisely because the suite only ever ran against
	// whichever theme happened to be active.
	//
	// Firefox and WebKit are deliberately absent: their binaries install fine, but
	// this container is missing the system libraries they need (libgtk-3, libcairo
	// -gobject, libgdk_pixbuf …) and installing those needs root. Add them to
	// .devcontainer/Dockerfile and rebuild from the host to widen this axis.
	projects: [
		{
			name: 'block-theme',
			use: { ...devices['Desktop Chrome'] },
			metadata: { theme: 'twentytwentyfive', family: 'block' },
		},
		{
			name: 'classic-theme',
			use: { ...devices['Desktop Chrome'] },
			metadata: { theme: 'twentytwentyone', family: 'classic' },
			// Only the front-end specs are worth running twice. The admin screens
			// and the editor don't change with the storefront theme, so running
			// them again would double their cost for no coverage.
			testMatch: /pdp-embed\.spec\.js/,
		},
	],
	// No webServer: Apache is already the container's CMD.
});
