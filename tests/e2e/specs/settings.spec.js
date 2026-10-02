const { test, expect } = require('@playwright/test');
const { clearPosition, getSetting, wp } = require('../helpers');

const SETTINGS_URL =
	'/wp-admin/admin.php?page=wc-settings&tab=integration&section=arkid-catalogue-link';

// WooCommerce derives field ids from `woocommerce_<integration id>_<field>`.
const FIELD = {
	apiKey: '#woocommerce_arkid-catalogue-link_api_key',
	position: '#woocommerce_arkid-catalogue-link_default_position',
	buttonText: '#woocommerce_arkid-catalogue-link_button_text',
	consent: '#woocommerce_arkid-catalogue-link_require_consent',
};

/**
 * The integration settings screen.
 *
 * These settings decide what every product display page renders, so a screen
 * that silently fails to persist is indistinguishable from a rendering bug —
 * and the API key in particular is the one field a merchant is guaranteed to
 * touch during onboarding.
 *
 * Runs under the block-theme project only; playwright.config.js narrows the
 * classic project to the front-end specs, because the admin does not vary with
 * the storefront theme.
 */
test.describe('integration settings', () => {
	test('exposes every documented field', async ({ page }) => {
		await page.goto(SETTINGS_URL);

		await expect(page.locator(FIELD.apiKey)).toBeVisible();
		await expect(page.locator(FIELD.position)).toBeVisible();
		await expect(page.locator(FIELD.buttonText)).toBeVisible();
		await expect(page.locator(FIELD.consent)).toBeVisible();
	});

	/**
	 * The key is a credential: it must not be sitting in the DOM as plain text
	 * on a screen a merchant might be sharing.
	 */
	test('masks the API key behind a reveal toggle', async ({ page }) => {
		await page.goto(SETTINGS_URL);

		const field = page.locator(FIELD.apiKey);
		await expect(field).toHaveAttribute('type', 'password');

		// The real toggle, not a `:near()` guess — a fuzzy selector here matched
		// the Save button and silently submitted the form mid-suite.
		const reveal = page.locator('button.arkid-catalogue-link__reveal');
		await expect(reveal).toHaveAttribute('aria-pressed', 'false');

		await reveal.click();
		await expect(field).toHaveAttribute('type', 'text');
		await expect(reveal).toHaveAttribute('aria-pressed', 'true');

		await reveal.click();
		await expect(field).toHaveAttribute('type', 'password');
	});

	test('persists a changed position and button text', async ({ page }) => {
		await page.goto(SETTINGS_URL);

		await page.selectOption(FIELD.position, 'above_product');
		await page.fill(FIELD.buttonText, 'View in 3D please');
		await page.click('button[name="save"], .woocommerce-save-button');

		await expect(page.locator('.updated, .notice-success')).toBeVisible();

		// Assert against the option, not the re-rendered form: the form could
		// echo back what was typed without WooCommerce having stored it.
		expect(getSetting('default_position')).toBe('above_product');
		expect(getSetting('button_text')).toBe('View in 3D please');
	});

	test('a saved position drives what the product page renders', async ({
		page,
	}) => {
		const fixtures = require('../.auth/fixtures.json');

		// Clear the per-product override so the site default is what applies.
		clearPosition(fixtures.withViewer);

		await page.goto(SETTINGS_URL);
		await page.selectOption(FIELD.position, 'button_only');
		await page.click('button[name="save"], .woocommerce-save-button');
		await expect(page.locator('.updated, .notice-success')).toBeVisible();

		await page.goto(wp(['post', 'url', String(fixtures.withViewer)]));
		await expect(
			page.locator('.arkid-catalogue-link__trigger--standalone')
		).toBeVisible();
	});

	/**
	 * An empty button text must fall back to the localized default rather than
	 * rendering an unlabelled control.
	 */
	test('an empty button text falls back to the default label', async ({
		page,
	}) => {
		const fixtures = require('../.auth/fixtures.json');

		await page.goto(SETTINGS_URL);
		await page.fill(FIELD.buttonText, '');
		await page.selectOption(FIELD.position, 'button_only');
		await page.click('button[name="save"], .woocommerce-save-button');
		await expect(page.locator('.updated, .notice-success')).toBeVisible();

		await page.goto(wp(['post', 'url', String(fixtures.withViewer)]));

		const label = await page
			.locator('.arkid-catalogue-link__trigger--standalone')
			.innerText();

		expect(label.trim()).not.toBe('');
	});
});
