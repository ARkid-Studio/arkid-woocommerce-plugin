const { test, expect } = require('@playwright/test');
const { wp } = require('../helpers');

const fixtures = require('../.auth/fixtures.json');

const VIEWERS = '/wp-admin/admin.php?page=arkid-viewers';
const ROWS = '#the-list > tr';

/**
 * WooCommerce → ARkid Viewers.
 *
 * The API stub (tests/e2e/mu-plugins/arkid-e2e-api-stub.php) lists `e2e-viewer`,
 * which the two fixture products show, and as many further viewers as the
 * `arkid_e2e_viewers` option says. Statistics list traffic for `e2e-viewer` only.
 *
 * Runs under the block-theme project only: the admin does not vary with the
 * storefront theme (see playwright.config.js).
 */
test.describe('ARkid Viewers screen', () => {
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
				// A 1x1 transparent PNG.
				body: Buffer.from(
					'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
					'base64'
				),
			})
		);
	});

	test.afterEach(() => {
		// Back to the defaults the other specs expect: one viewer, statistics up,
		// and the admin's own Screen Options untouched.
		wp([
			'eval',
			`delete_option( 'arkid_e2e_viewers' );
			delete_option( 'arkid_e2e_stats' );
			delete_user_meta( 1, 'arkid_viewers_per_page' );
			delete_user_meta( 1, 'manage' . 'woocommerce_page_arkid-viewers' . 'columnshidden' );`,
		]);
	});

	test('lists a viewer with where it is shown, its colours and its interactions', async ({
		page,
	}) => {
		await page.goto(VIEWERS);

		const row = page.locator(ROWS).first();
		await expect(row).toContainText('E2E Viewer');

		// Both fixture products show it, each linked to its edit screen.
		const shownOn = row.locator('.column-shown_on');
		await expect(
			shownOn.getByRole('link', { name: 'E2E Chair' })
		).toHaveAttribute(
			'href',
			new RegExp(`post\\.php\\?post=${fixtures.withViewer}&action=edit`)
		);
		await expect(
			shownOn.getByRole('link', {
				name: 'E2E Stool (no featured image)',
			})
		).toBeVisible();

		// A shown colour and a hidden one, each with its state in words.
		const colors = row.locator('.column-colors');
		await expect(
			colors.getByRole('img', { name: 'Copper Terra: shown' })
		).toBeVisible();
		await expect(
			colors.getByRole('img', { name: 'Moon Graphite: hidden' })
		).toBeVisible();

		await expect(row.locator('.column-interactions')).toHaveText('14');
		await expect(row.locator('.column-views')).toHaveText('10');
		await expect(row.locator('.column-modified')).toContainText(
			'E2E Author'
		);
		await expect(
			row.locator('.column-actions').getByRole('link', { name: 'Edit' })
		).toHaveAttribute('target', '_blank');
		await expect(
			row
				.locator('.column-actions')
				.getByRole('link', { name: 'Analytics' })
		).toBeVisible();

		const views = page.locator('.subsubsub');
		await expect(views).toContainText('All (1)');
		await expect(views).toContainText('Live on a product (1)');
		await expect(views).toContainText('Not linked (0)');
		await expect(views).toContainText('No interactions, 30 days (0)');
	});

	test.describe('with several viewers', () => {
		test.beforeEach(() => {
			wp(['option', 'update', 'arkid_e2e_viewers', '4']);
		});

		test('the views, search and filters narrow the list', async ({
			page,
		}) => {
			await page.goto(VIEWERS);
			await expect(page.locator(ROWS)).toHaveCount(4);

			const views = page.locator('.subsubsub');
			await expect(views).toContainText('All (4)');
			await expect(views).toContainText('Live on a product (1)');
			await expect(views).toContainText('Not linked (3)');
			await expect(views).toContainText('No interactions, 30 days (3)');

			// The current view is marked, and a view is a link.
			await views.getByRole('link', { name: /Not linked/ }).click();
			await expect(page.locator(ROWS)).toHaveCount(3);
			await expect(
				views.getByRole('link', { name: /Not linked/ })
			).toHaveAttribute('aria-current', 'page');

			// Search keeps the view: it is a field of the same form.
			await page
				.getByRole('searchbox', { name: /Search viewers/ })
				.fill('viewer 3');
			await page.getByRole('button', { name: 'Search viewers' }).click();
			await expect(page.locator(ROWS)).toHaveCount(1);
			await expect(page.locator(ROWS).first()).toContainText(
				'E2E Viewer 3'
			);
			await expect(page).toHaveURL(/show=unlinked/);

			// Type and brand.
			await page.goto(VIEWERS);
			await page.getByLabel('Filter by type').selectOption('exp');
			await page.getByRole('button', { name: 'Filter' }).click();
			await expect(page.locator(ROWS)).toHaveCount(2);
			await page.getByLabel('Filter by brand').selectOption('Acme');
			await page.getByRole('button', { name: 'Filter' }).click();
			await expect(page.locator(ROWS)).toHaveCount(2);
			await expect(page).toHaveURL(/viewer_type=exp/);
			await expect(page).toHaveURL(/viewer_brand=Acme/);
		});

		test('a filter without matches says so and offers to clear it', async ({
			page,
		}) => {
			await page.goto(`${VIEWERS}&s=nothing-like-this`);

			await expect(page.locator('.no-items')).toContainText(
				'No viewers match these filters.'
			);
			await page.getByRole('link', { name: 'Clear the filters' }).click();
			await expect(page.locator(ROWS)).toHaveCount(4);
		});

		test('sorts by interactions and by last update', async ({ page }) => {
			await page.goto(VIEWERS);

			await page.locator('#interactions').getByRole('link').click();
			// Descending first: the viewer with traffic leads.
			await expect(
				page.locator(ROWS).first().locator('.column-product_name')
			).toContainText('E2E Viewer');
			await expect(
				page.locator(ROWS).first().locator('.column-interactions')
			).toHaveText('14');

			await page.locator('#modified').getByRole('link').click();
			await expect(page).toHaveURL(/orderby=modified/);
		});

		/**
		 * Screen Options → "Viewers per page" is submitted to wp-admin/admin.php
		 * before the screen's own code runs; this goes through that form for real.
		 */
		test('Screen Options sets how many viewers a page holds, and it sticks', async ({
			page,
		}) => {
			await page.goto(VIEWERS);

			await page.getByRole('button', { name: 'Screen Options' }).click();
			await page.getByLabel('Viewers per page').fill('2');
			await page.getByRole('button', { name: 'Apply' }).click();

			await expect(page.locator(ROWS)).toHaveCount(2);
			await expect(
				page.locator('.tablenav.top .displaying-num')
			).toHaveText('4 items');

			await page.locator('.tablenav.top .next-page').click();
			await expect(page).toHaveURL(/paged=2/);
			await expect(page.locator(ROWS)).toHaveCount(2);

			// A later visit keeps the choice.
			await page.goto(VIEWERS);
			await expect(page.locator(ROWS)).toHaveCount(2);
		});

		test('Screen Options can show the created column', async ({ page }) => {
			await page.goto(VIEWERS);
			await expect(page.locator('#created')).toBeHidden();

			await page.getByRole('button', { name: 'Screen Options' }).click();
			await page.getByRole('checkbox', { name: 'Created' }).check();

			await expect(page.locator('#created')).toBeVisible();
			await expect(
				page.locator(ROWS).first().locator('.column-created')
			).toBeVisible();
		});
	});

	test('"+N more" opens the Products list narrowed to the products of that viewer', async ({
		page,
	}) => {
		// The viewer is on four products: three are listed, the fourth is "+1 more".
		const extras = ['E2E Extra A', 'E2E Extra B'].map((title) =>
			wp([
				'post',
				'create',
				'--post_type=product',
				'--post_status=publish',
				`--post_title=${title}`,
				'--porcelain',
			])
		);
		const unlinked = wp([
			'post',
			'create',
			'--post_type=product',
			'--post_status=publish',
			'--post_title=E2E Unlinked',
			'--porcelain',
		]);
		for (const id of extras) {
			wp(['post', 'meta', 'update', id, '_arkid_embed_id', 'e2e-viewer']);
		}

		try {
			await page.goto(VIEWERS);
			await page
				.locator(ROWS)
				.first()
				.locator('.column-shown_on')
				.getByRole('link', { name: '+1 more' })
				.click();

			await expect(page).toHaveURL(/arkid_embed=e2e-viewer/);
			await expect(
				page.getByText(
					'Showing only the products that show one ARkid viewer.'
				)
			).toBeVisible();

			const titles = page.locator('#the-list .column-name .row-title');
			await expect(titles).toHaveCount(4);
			await expect(
				titles.filter({ hasText: 'E2E Unlinked' })
			).toHaveCount(0);
			for (const title of ['E2E Chair', 'E2E Extra A', 'E2E Extra B']) {
				await expect(titles.filter({ hasText: title })).toHaveCount(1);
			}

			// The way back is the list of every product again.
			await page.getByRole('link', { name: 'Show all products' }).click();
			await expect(page).not.toHaveURL(/arkid_embed/);
			await expect(
				page
					.locator('#the-list .column-name .row-title')
					.filter({ hasText: 'E2E Unlinked' })
			).toHaveCount(1);
			await expect(
				page.getByText(
					'Showing only the products that show one ARkid viewer.'
				)
			).toHaveCount(0);
		} finally {
			wp(['post', 'delete', ...extras, unlinked, '--force']);
		}
	});

	test('the list renders without interactions when the statistics are unavailable', async ({
		page,
	}) => {
		wp(['option', 'update', 'arkid_e2e_stats', 'down']);

		await page.goto(VIEWERS);

		await expect(page.locator(ROWS)).toHaveCount(1);
		await expect(page.locator('#interactions')).toHaveCount(0);
		await expect(page.locator('.subsubsub')).not.toContainText(
			'No interactions'
		);
		await expect(
			page.locator('.notice-warning', {
				hasText: 'could not be loaded from ARkid',
			})
		).toBeVisible();
		await expect(
			page.locator(ROWS).first().locator('.column-views')
		).toHaveText('10');
	});

	test.describe('preview', () => {
		test('opens the viewer in a dialog and closes on Escape with focus returned', async ({
			page,
		}) => {
			await page.goto(VIEWERS);
			const dialog = page.locator('#arkid-catalogue-link-preview');
			await expect(dialog).toBeHidden();
			await expect(dialog.locator('iframe')).toHaveCount(0);

			const opener = page
				.locator(ROWS)
				.first()
				.locator('.column-product_name')
				.getByRole('button', { name: /Preview/ });
			await opener.click();

			await expect(dialog).toBeVisible();
			await expect(dialog).toHaveAccessibleName('ARkid E2E Viewer');
			const frame = dialog.locator('iframe');
			await expect(frame).toHaveAttribute(
				'src',
				/^https:\/\/catalogue\.arkid\.app\/e\/e2e-viewer\?source=woocommerce$/
			);
			await expect(frame).toHaveAttribute(
				'sandbox',
				'allow-scripts allow-same-origin allow-popups allow-forms'
			);
			await expect(frame).toHaveAttribute(
				'title',
				'ARkid E2E Viewer 3D viewer'
			);
			await expect(
				dialog.getByRole('link', { name: 'Edit in ARkid' })
			).toHaveAttribute(
				'href',
				'https://catalogue.arkid.app/configure/edit/e2e-viewer'
			);
			await expect(
				dialog.getByRole('link', { name: 'Analytics' })
			).toBeVisible();
			await expect(
				dialog.getByRole('button', { name: 'Close', exact: true })
			).toBeFocused();

			// The page behind a modal dialog cannot be reached by the keyboard:
			// focus stays in the dialog, or leaves the page for the browser.
			for (let i = 0; i < 8; i++) {
				await page.keyboard.press('Tab');
				const reachable = await page.evaluate(() => {
					const dialogNode = document.querySelector(
						'#arkid-catalogue-link-preview'
					);
					const { activeElement, body } = dialogNode.ownerDocument;

					return (
						dialogNode.contains(activeElement) ||
						activeElement === body
					);
				});
				expect(reachable).toBe(true);
			}

			await page.keyboard.press('Escape');

			await expect(dialog).toBeHidden();
			await expect(dialog.locator('iframe')).toHaveCount(0);
			await expect(opener).toBeFocused();
		});

		test('the thumbnail and the Preview action open it too, and Close closes it', async ({
			page,
		}) => {
			await page.goto(VIEWERS);
			const dialog = page.locator('#arkid-catalogue-link-preview');
			const row = page.locator(ROWS).first();

			await row.locator('.column-image button').click();
			await expect(dialog).toBeVisible();
			await dialog
				.getByRole('button', { name: 'Close', exact: true })
				.click();
			await expect(dialog).toBeHidden();

			await row
				.locator('.column-actions')
				.getByRole('button', { name: /Preview/ })
				.click();
			await expect(dialog).toBeVisible();
			await page.getByRole('button', { name: 'Close preview' }).click();
			await expect(dialog).toBeHidden();
		});

		test('the product screen previews the selected viewer', async ({
			page,
		}) => {
			await page.goto(
				`/wp-admin/post.php?post=${fixtures.withViewer}&action=edit`
			);

			await page
				.locator('#arkid-catalogue-link-metabox')
				.getByRole('button', { name: 'Preview' })
				.click();

			const dialog = page.locator('#arkid-catalogue-link-preview');
			await expect(dialog).toBeVisible();
			await expect(dialog.locator('iframe')).toHaveAttribute(
				'src',
				/catalogue\.arkid\.app\/e\/e2e-viewer/
			);
			await page.keyboard.press('Escape');
			await expect(dialog).toBeHidden();
		});

		test('picking another viewer updates the colours and the preview, and removing it clears both', async ({
			page,
		}) => {
			// More than one viewer to pick from; the afterEach puts it back.
			wp(['option', 'update', 'arkid_e2e_viewers', '4']);
			await page.goto(
				`/wp-admin/post.php?post=${fixtures.withViewer}&action=edit`
			);

			const box = page.locator('#arkid-catalogue-link-metabox');
			const colors = box.locator('.arkid-catalogue-link__embed-colors');
			const preview = box.locator('.arkid-catalogue-link__preview');
			await expect(colors).toBeVisible();

			// The picker's results carry the dots of each viewer.
			await box.locator('.select2-selection').click();
			await page
				.locator('.select2-dropdown .select2-search__field')
				.fill('E2E Viewer 3');
			const option = page.locator('.select2-results__option', {
				hasText: 'E2E Viewer 3',
			});
			await expect(
				option.getByRole('img', { name: 'Copper Terra: shown' })
			).toBeVisible();
			await expect(
				option.getByRole('img', { name: 'Moon Graphite: hidden' })
			).toBeVisible();

			// Picking it replaces the colours and points Preview at it.
			await option.click();
			await expect(colors).toBeVisible();
			await expect(
				colors.getByRole('img', { name: 'Moon Graphite: hidden' })
			).toHaveCount(1);
			await expect(preview).toHaveAttribute(
				'data-product-name',
				'ARkid E2E Viewer 3'
			);
			await preview.click();
			const dialog = page.locator('#arkid-catalogue-link-preview');
			await expect(dialog).toBeVisible();
			await expect(
				dialog.getByRole('heading', { name: 'ARkid E2E Viewer 3' })
			).toBeVisible();
			await page.keyboard.press('Escape');
			await expect(dialog).toBeHidden();

			// Removing the viewer leaves nothing to show or preview.
			await box.getByRole('button', { name: 'Remove viewer' }).click();
			await expect(colors).toBeHidden();
			await expect(preview).toBeHidden();
			await expect(
				colors.locator('.arkid-catalogue-link__dot')
			).toHaveCount(0);
		});

		test('the product screen shows the colours of the selected viewer', async ({
			page,
		}) => {
			await page.goto(
				`/wp-admin/post.php?post=${fixtures.withViewer}&action=edit`
			);

			const colors = page.locator('.arkid-catalogue-link__embed-colors');
			await expect(
				colors.getByRole('img', { name: 'Copper Terra: shown' })
			).toBeVisible();
			await expect(
				colors.getByRole('img', { name: 'Moon Graphite: hidden' })
			).toBeVisible();
		});
	});

	test('collapses to its product column on a narrow screen', async ({
		page,
	}) => {
		await page.setViewportSize({ width: 600, height: 900 });
		await page.goto(VIEWERS);

		const row = page.locator(ROWS).first();
		await expect(row.locator('.column-product_name')).toBeVisible();
		await expect(row.locator('.column-brand_name')).toBeHidden();

		await row.locator('.toggle-row').click();
		await expect(row.locator('.column-brand_name')).toBeVisible();
		await expect(row.locator('.column-brand_name')).toHaveAttribute(
			'data-colname',
			'Brand'
		);
	});
});
