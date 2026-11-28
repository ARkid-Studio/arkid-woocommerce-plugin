const { test, expect } = require('@playwright/test');
const { setPosition, wp } = require('../helpers');

const fixtures = require('../.auth/fixtures.json');

const REMOVE = '.arkid-catalogue-link__remove-viewer';
const POSITION = '#arkid_catalogue_link_position';

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

	/**
	 * Removing a viewer used to delete the position override together with the
	 * link, so a merchant who unlinked a viewer and picked another one lost a
	 * deliberate "Button only" or "Above product" choice without noticing. This
	 * goes through the real form: the select still posts its value when the
	 * viewer has been removed.
	 */
	test('keeps the position override when the viewer is removed', async ({
		page,
	}) => {
		const id = wp([
			'post',
			'create',
			'--post_type=product',
			'--post_status=publish',
			'--post_title=E2E Unlink',
			'--porcelain',
		]);

		try {
			wp(['post', 'meta', 'update', id, '_arkid_embed_id', 'e2e-viewer']);
			wp([
				'post',
				'meta',
				'update',
				id,
				'_arkid_embed_url',
				'https://catalogue.arkid.app/e/e2e-viewer',
			]);
			setPosition(id, 'button_only');

			await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
			await expect(page.locator(POSITION)).toHaveValue('button_only');

			await page.locator(REMOVE).click();
			await expect(page.locator(REMOVE)).toBeHidden();
			await page.locator('#publish').click();
			await page.waitForURL(/message=1/);

			const meta = JSON.parse(
				wp([
					'post',
					'meta',
					'list',
					id,
					'--keys=_arkid_embed_id,_arkid_embed_position',
					'--fields=meta_key,meta_value',
					'--format=json',
				])
			);
			expect(meta).toEqual([
				{
					meta_key: '_arkid_embed_position',
					meta_value: 'button_only',
				},
			]);
			await expect(page.locator(POSITION)).toHaveValue('button_only');
		} finally {
			wp(['post', 'delete', id, '--force']);
		}
	});
});
