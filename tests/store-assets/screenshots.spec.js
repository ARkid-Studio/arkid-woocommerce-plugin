const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { permalink, setPosition, wp } = require('../e2e/helpers');
const config = require('./config');

const { productId, hero } = JSON.parse(
	fs.readFileSync(path.join(config.STATE_DIR, 'fixtures.json'), 'utf8')
);

const SETTINGS_URL =
	'/wp-admin/admin.php?page=wc-settings&tab=integration&section=arkid-catalogue-link';
// Newest first, the order a merchant who just added viewers cares about.
const VIEWERS_URL =
	'/wp-admin/admin.php?page=arkid-viewers&orderby=row_number&order=desc';

/**
 * Hides the admin furniture that is not about this plugin: update nags,
 * WooCommerce promos, the "Screen Options" tabs and the footer. A screenshot
 * with "WordPress 7.1 is available!" across the top dates itself in a week.
 */
const CLEAN_ADMIN_CSS = `
	#wpbody-content .notice,
	#wpbody-content .updated,
	#wpbody-content .error,
	.woocommerce-message,
	#screen-meta-links,
	#wpfooter,
	#adminmenu .update-plugins,
	#adminmenu .awaiting-mod { display: none !important; }
`;

// Anything smaller than this is a viewer that never drew its model: a blank
// canvas compresses to almost nothing. Fail loudly instead of publishing it.
const MIN_VIEWER_PNG_BYTES = 40_000;

function outFile(n) {
	return path.join(config.OUT_DIR, `screenshot-${n}.png`);
}

async function shoot(page, n, options = {}) {
	await page.screenshot({
		path: outFile(n),
		animations: 'disabled',
		...options,
	});
}

/**
 * Wait until the viewer iframe has actually drawn its 3D model.
 *
 * The iframe's `load` event fires long before that: the model is fetched and
 * decoded afterwards and drawn on a software GL stack, so a screenshot taken on
 * `load` is a white box.
 */
async function waitForViewer(page, iframe) {
	await iframe.scrollIntoViewIfNeeded();
	const frame = await (await iframe.elementHandle()).contentFrame();
	await frame.waitForLoadState('load');
	// `attached`, not `visible`: the viewer keeps an off-screen canvas ahead of
	// the one it draws on, and waiting for the first to be visible never ends.
	await frame.locator('canvas').first().waitFor({ state: 'attached' });
	await page.waitForTimeout(9_000);

	const probe = await iframe.screenshot();
	expect(
		probe.length,
		'The viewer iframe looks empty — the 3D model did not render.'
	).toBeGreaterThan(MIN_VIEWER_PNG_BYTES);
}

test.describe.configure({ mode: 'serial' });

test.describe('admin', () => {
	test('screenshot 1: picking a viewer on the product edit screen', async ({
		page,
	}) => {
		await page.goto(`/wp-admin/post.php?post=${productId}&action=edit`);
		await page.addStyleTag({ content: CLEAN_ADMIN_CSS });

		const box = page.locator('#arkid-catalogue-link-metabox');
		await expect(box).toBeVisible();

		// Open the real picker: it queries the plugin's REST route, which asks
		// the (stubbed) ARkid API for the catalogue.
		await box.locator('.select2-selection').click();
		const options = page.locator('.select2-results__option');
		await expect(options.first()).toBeVisible();
		await expect(options).toHaveCount(config.loadViewers().length);

		const heroOption = page.locator('.select2-results__option', {
			hasText: hero.product_name,
		});
		await heroOption.hover();
		await shoot(page, 1);

		// Finish the flow, so the storefront shots have a viewer to show — and
		// so the real save path (REST fetch, snapshot into post meta) is what
		// attaches it.
		await heroOption.click();
		await page.click('#publish');
		await page.waitForURL(/message=1/);
		expect(
			wp(['post', 'meta', 'get', String(productId), '_arkid_embed_id'])
		).toBe(hero.embed_id);
	});

	test('screenshot 4: integration settings', async ({ page }) => {
		await page.goto(SETTINGS_URL);
		await page.addStyleTag({ content: CLEAN_ADMIN_CSS });
		await expect(
			page.locator('#woocommerce_arkid-catalogue-link_api_key')
		).toBeVisible();
		await shoot(page, 4);
	});

	test('screenshot 5: the ARkid Viewers list', async ({ page }) => {
		await page.goto(VIEWERS_URL);
		await page.addStyleTag({ content: CLEAN_ADMIN_CSS });
		await expect(page.locator('.wp-list-table tbody tr')).toHaveCount(
			config.loadViewers().length
		);
		// A handful of rows does not fill a screen; crop to the table instead of
		// publishing a picture that is mostly empty grey. Tall enough for every
		// row, or the newest viewer — the one that leads — would be cut off.
		await shoot(page, 5, {
			clip: { x: 0, y: 0, width: 1280, height: 700 },
		});
	});
});

test.describe('storefront', () => {
	// The merchant's view of the shop has an admin bar; a shopper's does not.
	test.use({
		storageState: { cookies: [], origins: [] },
		viewport: { width: 1280, height: 1000 },
	});

	test('screenshot 2: the viewer below the product', async ({ page }) => {
		setPosition(productId, 'below_product');
		await page.goto(permalink(productId));

		const embed = page.locator('.arkid-catalogue-link__embed--below');
		await expect(embed).toBeVisible();
		await waitForViewer(page, embed.locator('iframe'));

		// From the top of the page down to just under the viewer: the product
		// above it is the context that shows WHERE the viewer sits. The rest of
		// the page (description, footer) is only height.
		await page.evaluate(() => window.scrollTo(0, 0));
		const box = await embed.boundingBox();
		await shoot(page, 2, {
			fullPage: true,
			clip: {
				x: 0,
				y: 0,
				width: 1280,
				height: Math.ceil(box.y + box.height + 24),
			},
		});
	});

	test('screenshot 3: the viewer in a modal from the gallery', async ({
		page,
	}) => {
		setPosition(productId, 'last_image');
		await page.goto(permalink(productId));

		// The viewer is the last slide of the product gallery; opening it is what
		// the shopper does, so do exactly that.
		await page.locator('.arkid-catalogue-link__trigger').first().click();
		const dialog = page.locator('#arkid-catalogue-link__dialog');
		await expect(dialog).toBeVisible();
		await waitForViewer(page, dialog.locator('iframe'));
		await shoot(page, 3);
	});
});
