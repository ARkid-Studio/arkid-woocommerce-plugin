const { test, expect } = require('@playwright/test');
const { wp } = require('../helpers');

const PRODUCTS = '/wp-admin/edit.php?post_type=product';
const TITLES = '#the-list .column-name .row-title';

/**
 * The row of a product, found by its own title: the column of a linked product
 * names its viewer, so the words of one title can be in other rows.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          title
 */
function rowOf(page, title) {
	return page.locator('#the-list tr').filter({
		has: page.getByRole('link', { name: title, exact: true }),
	});
}

/**
 * The titles the list shows, in alphabetical order: the list's own order is by
 * date, which says nothing about what is being tested.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number}                          count How many to wait for.
 */
async function listed(page, count) {
	await expect(page.locator(TITLES)).toHaveCount(count);
	return (await page.locator(TITLES).allTextContents()).sort();
}

function createProduct(title, embedId = '') {
	const id = wp([
		'post',
		'create',
		'--post_type=product',
		'--post_status=publish',
		`--post_title=${title}`,
		'--porcelain',
	]);
	if (embedId) {
		wp(['post', 'meta', 'update', id, '_arkid_embed_id', embedId]);
	}
	return id;
}

/**
 * The ARkid parts of Products → All Products: the column, the views with their
 * counts, the dropdowns, and what the list does when ARkid cannot answer.
 *
 * The API stub (tests/e2e/mu-plugins/arkid-e2e-api-stub.php) lists the viewer
 * `e2e-viewer` ("ARkid E2E Viewer", row 1) and one catalogue product ("ARkid E2E
 * Product"), and rejects the key `bad-key` with a 403. The two fixture products
 * show `e2e-viewer`, with a stored address and thumbnail.
 *
 * Runs under the block-theme project only: the admin does not vary with the
 * storefront theme (see playwright.config.js).
 */
test.describe('ARkid on the Products list', () => {
	let extras = [];

	test.beforeEach(async ({ page }) => {
		// A browser must never reach ARkid from a test: not the viewer, not the images.
		await page.route('https://catalogue.arkid.app/**', (route) =>
			route.fulfill({
				contentType: 'text/html',
				body: '<!doctype html><title>stub</title><p>Stub viewer</p>',
			})
		);
		await page.route('https://cloud.arkid.app/**', (route) =>
			route.fulfill({
				contentType: 'image/png',
				body: Buffer.from(
					'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
					'base64'
				),
			})
		);

		extras = [
			// Its name matches the viewer, and the catalogue product, and nothing.
			createProduct('ARkid E2E Viewer'),
			createProduct('ARkid E2E Product'),
			createProduct('E2E Garden Hose'),
			// A viewer the account does not list, and one never fetched.
			createProduct('E2E Deleted Upstream', 'e2e-vanished'),
		];
		wp([
			'post',
			'meta',
			'update',
			extras[3],
			'_arkid_embed_url',
			'https://catalogue.arkid.app/e/e2e-vanished',
		]);
		extras.push(createProduct('E2E Not Synced', 'e2e-viewer'));
	});

	test.afterEach(() => {
		wp(['post', 'delete', ...extras, '--force']);
		extras = [];
		// Back to the key the other specs expect.
		wp([
			'option',
			'patch',
			'update',
			'woocommerce_arkid-catalogue-link_settings',
			'api_key',
			'e2e-key',
		]);
	});

	test('the ARkid column shows the viewer, its colours and the state of the link', async ({
		page,
	}) => {
		await page.goto(PRODUCTS);

		const cell = rowOf(page, 'E2E Chair').locator('.column-arkid');
		await expect(page.locator('thead th#arkid')).toHaveText('ARkid');
		await expect(cell).toContainText('#1 ARkid E2E Viewer');
		await expect(cell.getByText('Linked', { exact: true })).toBeVisible();
		await expect(
			cell.getByRole('img', { name: 'Copper Terra: shown' })
		).toBeVisible();
		await expect(
			cell.getByRole('img', { name: 'Moon Graphite: hidden' })
		).toBeVisible();

		await expect(
			rowOf(page, 'E2E Deleted Upstream').locator('.column-arkid')
		).toContainText('Unknown viewer');
		await expect(
			rowOf(page, 'E2E Not Synced').locator('.column-arkid')
		).toContainText('Not synced yet');
	});

	test('a product without a viewer shows the viewer or catalogue product its name matches', async ({
		page,
	}) => {
		await page.goto(PRODUCTS);

		const viewer = rowOf(page, 'ARkid E2E Viewer').locator('.column-arkid');
		await expect(viewer).toContainText('Match available');
		await expect(
			viewer.getByRole('link', {
				name: 'Suggested viewer: ARkid E2E Viewer',
			})
		).toBeVisible();

		await expect(
			rowOf(page, 'ARkid E2E Product').locator('.column-arkid')
		).toContainText(
			'Suggested from the ARkid catalogue: ARkid E2E Product'
		);

		const none = rowOf(page, 'E2E Garden Hose').locator('.column-arkid');
		await expect(none.getByText('No viewer')).toBeAttached();
		await expect(none).not.toContainText('Suggested');
	});

	test('the preview opens from the list and gives focus back', async ({
		page,
	}) => {
		await page.goto(PRODUCTS);

		const button = rowOf(page, 'E2E Chair')
			.locator('.column-arkid')
			.getByRole('button', { name: 'Preview #1 ARkid E2E Viewer' });
		await button.click();

		const dialog = page.getByRole('dialog');
		await expect(dialog).toBeVisible();
		await expect(dialog.locator('iframe')).toHaveAttribute(
			'src',
			/catalogue\.arkid\.app\/e\/e2e-viewer\?source=woocommerce/
		);

		await page.keyboard.press('Escape');
		await expect(dialog).toBeHidden();
		await expect(button).toBeFocused();
	});

	test('the views count the products and narrow the list', async ({
		page,
	}) => {
		await page.goto(PRODUCTS);

		const views = page.locator('ul.subsubsub');
		// Four products show a viewer: the two fixtures and the two made here.
		await expect(
			views.getByRole('link', { name: /^With viewer/ })
		).toContainText('(4)');
		await expect(
			views.getByRole('link', { name: /^Without viewer/ })
		).toContainText('(3)');
		await expect(
			views.getByRole('link', { name: /^Match available/ })
		).toContainText('(2)');
		await expect(
			views.getByRole('link', { name: /^Needs attention/ })
		).toContainText('(2)');

		await views.getByRole('link', { name: /^Match available/ }).click();
		await expect(page).toHaveURL(/arkid=match/);
		await expect(
			views.getByRole('link', { name: /^Match available/ })
		).toHaveAttribute('aria-current', 'page');
		expect(await listed(page, 2)).toEqual([
			'ARkid E2E Product',
			'ARkid E2E Viewer',
		]);

		await views.getByRole('link', { name: /^Needs attention/ }).click();
		expect(await listed(page, 2)).toEqual([
			'E2E Deleted Upstream',
			'E2E Not Synced',
		]);

		await views.getByRole('link', { name: /^Without viewer/ }).click();
		expect(await listed(page, 3)).toEqual([
			'ARkid E2E Product',
			'ARkid E2E Viewer',
			'E2E Garden Hose',
		]);

		await views.getByRole('link', { name: /^With viewer/ }).click();
		expect(await listed(page, 4)).toEqual([
			'E2E Chair',
			'E2E Deleted Upstream',
			'E2E Not Synced',
			'E2E Stool (no featured image)',
		]);
	});

	test('the dropdowns filter by the state and by one viewer, together', async ({
		page,
	}) => {
		await page.goto(PRODUCTS);

		await page
			.getByLabel('Filter by ARkid viewer')
			.first()
			.selectOption('attention');
		await page.getByRole('button', { name: 'Filter', exact: true }).click();
		await expect(page).toHaveURL(/arkid=attention/);
		await expect(page.locator(TITLES)).toHaveCount(2);

		// The viewer the fixtures show: of the two products that need attention
		// only the one that was never fetched has it.
		const viewer = page.locator('#arkid-catalogue-link-embed');
		await viewer.selectOption('e2e-viewer');
		await page.getByRole('button', { name: 'Filter', exact: true }).click();
		await expect(page).toHaveURL(/arkid_embed=e2e-viewer/);
		expect(await listed(page, 1)).toEqual(['E2E Not Synced']);

		await expect(viewer).toContainText('ARkid E2E Viewer (3 products)');
	});

	test('the list still renders, with a notice, when ARkid rejects the key', async ({
		page,
	}) => {
		wp([
			'option',
			'patch',
			'update',
			'woocommerce_arkid-catalogue-link_settings',
			'api_key',
			'bad-key',
		]);

		await page.goto(PRODUCTS);

		await expect(
			page.getByText(
				'The API key was rejected. Update it in the integration settings.'
			)
		).toBeVisible();
		await expect(page.locator(TITLES)).toHaveCount(
			Number(
				wp([
					'post',
					'list',
					'--post_type=product',
					'--post_status=publish',
					'--format=count',
				])
			)
		);

		// What is stored still shows, without a name; what needs ARkid is left out.
		const cell = rowOf(page, 'E2E Chair').locator('.column-arkid');
		await expect(cell).toContainText('e2e-viewer');
		await expect(cell).toContainText('Linked');
		const views = page.locator('ul.subsubsub');
		await expect(
			views.getByRole('link', { name: /^With viewer/ })
		).toBeVisible();
		await expect(
			views.getByRole('link', { name: /^Match available/ })
		).toHaveCount(0);
		await expect(
			page.locator('#arkid-catalogue-link-state option[value="match"]')
		).toHaveCount(0);
	});
});
