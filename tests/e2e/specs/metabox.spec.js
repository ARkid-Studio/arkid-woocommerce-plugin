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

	/**
	 * The three ways a selected viewer can be out of order read differently: a
	 * viewer deleted in ARkid will never sync, an unreachable ARkid will, and a
	 * viewer that was never fetched is only waiting. They used to share one
	 * message that promised the viewer "will appear once the connection
	 * succeeds".
	 */
	test.describe('link state', () => {
		const NOTICE = '.arkid-catalogue-link__link-notice';

		function makeProduct(title, embedId, withSnapshot) {
			const id = wp([
				'post',
				'create',
				'--post_type=product',
				'--post_status=publish',
				`--post_title=${title}`,
				'--porcelain',
			]);
			wp(['post', 'meta', 'update', id, '_arkid_embed_id', embedId]);
			if (withSnapshot) {
				wp([
					'post',
					'meta',
					'update',
					id,
					'_arkid_embed_url',
					'https://catalogue.arkid.app/e/e2e-viewer',
				]);
			}
			return id;
		}

		test('says a deleted viewer no longer exists, and offers the way out', async ({
			page,
		}) => {
			// The stub answers 404 for an id ending in -gone.
			const id = makeProduct('E2E Gone', 'e2e-gone', false);

			try {
				await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);

				const notice = page.locator(NOTICE);
				await expect(notice).toBeVisible();
				await expect(notice).toHaveAttribute('data-state', 'missing');
				await expect(notice).toContainText(
					'no longer exists in your ARkid account'
				);
				await expect(notice).not.toContainText('not been synced');
				await expect(page.locator(REMOVE)).toBeVisible();
			} finally {
				wp(['post', 'delete', id, '--force']);
			}
		});

		test('says ARkid could not be asked, and that the product keeps its viewer', async ({
			page,
		}) => {
			// The stub answers 503 for an id ending in -down.
			const id = makeProduct('E2E Down', 'e2e-down', true);

			try {
				await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);

				const notice = page.locator(NOTICE);
				await expect(notice).toHaveAttribute('data-state', 'linked');
				await expect(notice).toContainText('could not be checked');
				await expect(notice).not.toContainText('no longer exists');
			} finally {
				wp(['post', 'delete', id, '--force']);
			}
		});

		test('shows no notice for a viewer that is in order', async ({
			page,
		}) => {
			await page.goto(
				`/wp-admin/post.php?post=${fixtures.withViewer}&action=edit`
			);

			await expect(page.locator(REMOVE)).toBeVisible();
			await expect(page.locator(NOTICE)).toHaveCount(0);
		});

		test('hides the notice once another viewer is picked, because it is about the saved one', async ({
			page,
		}) => {
			const id = makeProduct('E2E Replace', 'e2e-gone', false);

			try {
				await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
				await expect(page.locator(NOTICE)).toBeVisible();

				await page
					.locator('#arkid-catalogue-link-metabox .select2-selection')
					.click();
				await page.locator('.select2-results__option').first().click();

				await expect(page.locator(NOTICE)).toBeHidden();
			} finally {
				wp(['post', 'delete', id, '--force']);
			}
		});

		test('hides the notice when the viewer is removed', async ({ page }) => {
			const id = makeProduct('E2E Remove gone', 'e2e-gone', false);

			try {
				await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
				await expect(page.locator(NOTICE)).toBeVisible();

				await page.locator(REMOVE).click();

				await expect(page.locator(NOTICE)).toBeHidden();
			} finally {
				wp(['post', 'delete', id, '--force']);
			}
		});

		test('flags a saved viewer that ARkid does not have', async ({
			page,
		}) => {
			// Saving a product that still selects a viewer ARkid answers 404 for
			// flags it missing and drops what it could have rendered.
			const id = makeProduct('E2E Save gone', 'e2e-gone', true);

			try {
				await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
				await page.locator('#publish').click();
				await page.waitForURL(/message=1/);

				const meta = JSON.parse(
					wp([
						'post',
						'meta',
						'list',
						id,
						'--keys=_arkid_embed_id,_arkid_embed_url,_arkid_embed_status',
						'--fields=meta_key,meta_value',
						'--format=json',
					])
				);
				expect(meta).toEqual(
					expect.arrayContaining([
						{ meta_key: '_arkid_embed_id', meta_value: 'e2e-gone' },
						{
							meta_key: '_arkid_embed_status',
							meta_value: 'missing',
						},
					])
				);
				expect(
					meta.find((row) => row.meta_key === '_arkid_embed_url')
				).toBeUndefined();
				await expect(page.locator(NOTICE)).toHaveAttribute(
					'data-state',
					'missing'
				);
			} finally {
				wp(['post', 'delete', id, '--force']);
			}
		});
	});
});
