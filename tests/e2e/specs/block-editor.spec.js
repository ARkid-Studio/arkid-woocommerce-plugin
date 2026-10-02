const { test, expect } = require('@playwright/test');
const { wp } = require('../helpers');

const BLOCK = 'arkid-catalogue-link/embed';

/**
 * Create a post that already contains the block, with attributes as the editor
 * would have persisted them.
 */
function makePostWithBlock(attrs) {
	const markup = `<!-- wp:${BLOCK} ${JSON.stringify(attrs)} /-->`;

	return wp([
		'post',
		'create',
		'--post_type=page',
		'--post_status=publish',
		'--post_title=ARkid block page',
		`--post_content=${markup}`,
		'--porcelain',
	]);
}

/**
 * The editor block.
 *
 * The block is server-rendered (`save: () => null`), so what ends up in
 * post_content is only the attribute payload — which is exactly why the block
 * persists `embedUrl` alongside `embedId`. A block that stored only the id would
 * have to resolve it through the API on every front-end render, putting a
 * third-party HTTP call on the critical path of a page view.
 */
test.describe('embed block', () => {
	test.beforeAll(() => {
		// The welcome modal covers the inserter on a fresh admin user.
		try {
			wp([
				'user',
				'meta',
				'update',
				'admin',
				'wp_persisted_preferences',
				JSON.stringify({
					'core/edit-post': { welcomeGuide: false },
					core: { welcomeGuide: false },
				}),
				'--format=json',
			]);
		} catch {
			// Preference shape varies by version; the dismissal below covers it.
		}
	});

	test('is available in the inserter and opens a viewer picker', async ({
		page,
	}) => {
		// A new *page* opens behind a "Choose a pattern" modal that covers the
		// inserter; a new post does not. The block is post-type agnostic, so use
		// the surface with less chrome in the way.
		await page.goto('/wp-admin/post-new.php?post_type=post');

		const inserter = page.locator('button[aria-label="Block Inserter"]');
		await expect(inserter).toBeVisible();

		// Dismiss anything that opened over the canvas while the editor booted.
		const modal = page.locator('.components-modal__screen-overlay');
		if (await modal.count()) {
			await page.keyboard.press('Escape');
			await expect(modal).toHaveCount(0);
		}

		await page
			.locator('button[aria-label="Block Inserter"]')
			.first()
			.click();

		const search = page
			.locator(
				'.block-editor-inserter__search input, input.components-search-control__input'
			)
			.first();
		await search.fill('ARkid');

		const results = page.locator('.block-editor-block-types-list__item');
		await expect(results).toHaveCount(1);
		await expect(results.first()).toContainText('ARkid Catalogue Link');
		await results.first().click();

		// The canvas is its own iframe in this editor, so the block is NOT in the
		// top-level document — a plain page.locator() silently finds nothing.
		const canvas = page.frameLocator('[name="editor-canvas"]');

		await expect(
			canvas.locator('[data-type="arkid-catalogue-link/embed"]')
		).toBeVisible();
	});

	test('renders the persisted viewer on the front end', async ({ page }) => {
		const id = makePostWithBlock({
			embedId: 'e2e-viewer',
			embedUrl: 'https://catalogue.arkid.app/e/e2e-viewer',
			productName: 'Block Chair',
		});

		await page.goto(wp(['post', 'url', id]));

		const iframe = page.locator('iframe.arkid-catalogue-link__viewer');
		await expect(iframe).toBeVisible();

		const src = await iframe.getAttribute('src');
		expect(new URL(src).hostname).toBe('catalogue.arkid.app');
		expect(new URL(src).searchParams.get('source')).toBe('woocommerce');

		wp(['post', 'delete', String(id), '--force']);
	});

	/**
	 * The design guarantee: front-end rendering must not depend on the API.
	 * Stripping the key makes ClientFactory return null, so any render path that
	 * tried to resolve the embed would produce nothing.
	 */
	test('renders without an API key, proving no call on the render path', async ({
		page,
	}) => {
		const before = wp([
			'option',
			'get',
			'woocommerce_arkid-catalogue-link_settings',
			'--format=json',
		]);

		wp([
			'option',
			'update',
			'woocommerce_arkid-catalogue-link_settings',
			JSON.stringify({ api_key: '' }),
			'--format=json',
		]);

		const id = makePostWithBlock({
			embedId: 'e2e-viewer',
			embedUrl: 'https://catalogue.arkid.app/e/e2e-viewer',
			productName: 'Keyless Chair',
		});

		try {
			await page.goto(wp(['post', 'url', id]));
			await expect(
				page.locator('iframe.arkid-catalogue-link__viewer')
			).toBeVisible();
		} finally {
			wp([
				'option',
				'update',
				'woocommerce_arkid-catalogue-link_settings',
				before,
				'--format=json',
			]);
			wp(['post', 'delete', String(id), '--force']);
		}
	});

	/**
	 * A block saved before 1.0.0 carries only `embedId`. It must not render a
	 * broken iframe — better nothing than an element pointing at nowhere.
	 */
	test('a legacy block with no embedUrl renders nothing rather than a broken frame', async ({
		page,
	}) => {
		const id = makePostWithBlock({ embedId: 'e2e-viewer' });

		await page.goto(wp(['post', 'url', id]));

		await expect(page.locator('iframe.arkid-catalogue-link__viewer')).toHaveCount(
			0
		);

		wp(['post', 'delete', String(id), '--force']);
	});
});
