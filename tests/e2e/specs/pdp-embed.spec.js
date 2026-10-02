const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { activateTheme, permalink, setPosition } = require('../helpers');

const fixtures = JSON.parse(
	fs.readFileSync(path.join(__dirname, '../.auth/fixtures.json'), 'utf8')
);

/**
 * Product display page embeds — the plugin's primary surface.
 *
 * Every test here runs once per theme family, because the two render the product
 * page through completely different machinery and the plugin has to hook both:
 *
 *  - classic: WooCommerce's PHP template fires `woocommerce_after_single_product_summary`
 *    and friends, with the data tabs hooked at priority 10.
 *  - block:   the page is blocks, and WooCommerce's SingleProductTemplateCompatibility
 *    buffers those same actions and injects them around specific blocks.
 *
 * That difference is not cosmetic. "Below product" rendered *underneath the
 * reviews* on every block theme until the block path got its own hook, and no
 * spec caught it because the suite only ever ran against whatever theme happened
 * to be active. The theme comes from the Playwright project, see
 * playwright.config.js.
 */
test.describe('PDP embeds', () => {
	test.beforeAll(({}, testInfo) => {
		activateTheme(testInfo.project.metadata.theme);
	});

	test('renders an inline viewer above the product', async ({ page }) => {
		setPosition(fixtures.withViewer, 'above_product');
		await page.goto(permalink(fixtures.withViewer));

		const embed = page.locator('.arkid-catalogue-link__embed--above');
		await expect(embed).toBeVisible();
		await expect(embed.locator('iframe')).toHaveAttribute(
			'sandbox',
			'allow-scripts allow-same-origin allow-popups allow-forms'
		);
	});

	/**
	 * The regression that motivated running this file twice.
	 */
	test('renders below the product but above the data tabs', async ({
		page,
	}) => {
		setPosition(fixtures.withViewer, 'below_product');
		await page.goto(permalink(fixtures.withViewer));

		const embed = page.locator('.arkid-catalogue-link__embed--below');
		await expect(embed.locator('iframe')).toBeVisible();

		const tabs = page.locator('.woocommerce-tabs');
		expect(
			await tabs.count(),
			'Without the tabs on the page this assertion proves nothing.'
		).toBeGreaterThan(0);

		const embedBox = await embed.boundingBox();
		const tabsBox = await tabs.boundingBox();
		expect(embedBox.y).toBeLessThan(tabsBox.y);
	});

	test('renders exactly one viewer, never a duplicate', async ({ page }) => {
		setPosition(fixtures.withViewer, 'below_product');
		await page.goto(permalink(fixtures.withViewer));

		// On a block theme BOTH the block filter and the legacy action fire for
		// the same product; only one of them may produce output.
		await expect(
			page.locator('.arkid-catalogue-link__embed--below')
		).toHaveCount(1);
	});

	test('renders a standalone button that opens the modal', async ({
		page,
	}) => {
		setPosition(fixtures.withViewer, 'button_only');
		await page.goto(permalink(fixtures.withViewer));

		const trigger = page.locator('.arkid-catalogue-link__trigger--standalone');
		await expect(trigger).toBeVisible();
		await expect(trigger).toHaveAttribute('aria-expanded', 'false');

		await trigger.click();

		const dialog = page.locator('#arkid-catalogue-link__dialog');
		await expect(dialog).toBeVisible();
		await expect(dialog.locator('iframe')).toHaveCount(1);
		await expect(trigger).toHaveAttribute('aria-expanded', 'true');
	});

	test('closes the modal on Escape and restores focus', async ({ page }) => {
		setPosition(fixtures.withViewer, 'button_only');
		await page.goto(permalink(fixtures.withViewer));

		const trigger = page.locator('.arkid-catalogue-link__trigger--standalone');
		await trigger.click();
		await page.keyboard.press('Escape');

		await expect(page.locator('#arkid-catalogue-link__dialog')).toBeHidden();
		await expect(trigger).toHaveAttribute('aria-expanded', 'false');
		// WCAG 2.2 AA.
		await expect(trigger).toBeFocused();
	});

	test('opening and closing repeatedly leaves one iframe', async ({ page }) => {
		setPosition(fixtures.withViewer, 'button_only');
		await page.goto(permalink(fixtures.withViewer));

		const trigger = page.locator('.arkid-catalogue-link__trigger--standalone');
		const dialog = page.locator('#arkid-catalogue-link__dialog');

		for (let i = 0; i < 3; i++) {
			await trigger.click();
			await expect(dialog).toBeVisible();
			await page.keyboard.press('Escape');
			await expect(dialog).toBeHidden();
		}

		await trigger.click();
		await expect(dialog.locator('iframe')).toHaveCount(1);
	});

	/**
	 * The browser-level guard for the classic gallery bug: a product with no
	 * featured image used to have every gallery slide replaced by a viewer.
	 */
	test('a product without a featured image gets exactly one viewer slide', async ({
		page,
	}) => {
		setPosition(fixtures.noFeatured, 'first_image');
		await page.goto(permalink(fixtures.noFeatured));

		await expect(
			page.locator('.arkid-catalogue-link__slide--first')
		).toHaveCount(1);
	});

	test('always emits a no-script fallback link', async ({ page }) => {
		setPosition(fixtures.withViewer, 'below_product');
		await page.goto(permalink(fixtures.withViewer));

		const noscript = page.locator('.arkid-catalogue-link__embed--below noscript');
		await expect(noscript).toHaveCount(1);
	});

	/**
	 * The viewer is a third-party iframe, so its URL is the one thing that must
	 * never be built wrong: an un-allow-listed host renders nothing at all, and a
	 * missing `source` costs ARkid their attribution.
	 */
	test('the iframe points at an allow-listed host and carries our source', async ({
		page,
	}) => {
		setPosition(fixtures.withViewer, 'above_product');
		await page.goto(permalink(fixtures.withViewer));

		const src = await page
			.locator('.arkid-catalogue-link__embed--above iframe')
			.getAttribute('src');

		const url = new URL(src);
		expect(url.protocol).toBe('https:');
		expect(url.hostname).toBe('catalogue.arkid.app');
		expect(url.searchParams.get('source')).toBe('woocommerce');
		expect(src.match(/source=/g)).toHaveLength(1);
	});
});
