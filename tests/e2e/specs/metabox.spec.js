const { test, expect } = require('@playwright/test');
const { wp } = require('../helpers');

const fixtures = require('../.auth/fixtures.json');

const REMOVE = '.arkid-catalogue-link__remove-viewer';

/**
 * The product edit screen's meta box.
 *
 * Runs under the block-theme project only: the admin does not vary with the
 * storefront theme (see playwright.config.js).
 */
test.describe('product meta box', () => {
	/**
	 * `hidden` is an attribute the browser styles with `display: none` — but only
	 * from its own stylesheet, which any author `display` rule beats. The button
	 * had `display: inline-block`, so "Remove viewer" sat under an empty picker,
	 * offering to remove nothing.
	 */
	test('hides "Remove viewer" when the product has no viewer', async ({
		page,
	}) => {
		const id = wp([
			'post',
			'create',
			'--post_type=product',
			'--post_status=publish',
			'--post_title=E2E No viewer',
			'--porcelain',
		]);

		try {
			await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
			await expect(
				page.locator('#arkid-catalogue-link-metabox')
			).toBeVisible();
			await expect(page.locator(REMOVE)).toBeHidden();
		} finally {
			wp(['post', 'delete', id, '--force']);
		}
	});

	// The other half: the fix must hide the button when it should be hidden, not
	// always.
	test('shows "Remove viewer" when the product has a viewer', async ({
		page,
	}) => {
		await page.goto(
			`/wp-admin/post.php?post=${fixtures.withViewer}&action=edit`
		);
		await expect(page.locator(REMOVE)).toBeVisible();
	});
});
