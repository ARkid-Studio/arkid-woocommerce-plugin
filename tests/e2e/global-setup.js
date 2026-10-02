/* eslint-disable no-console */
const { chromium, request } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
const WP_PATH = process.env.WP_PATH || '/var/www/html';
const USER = process.env.E2E_WP_USER || 'admin';
const PASS = process.env.E2E_WP_PASS || 'password';
const AUTH_DIR = path.join(__dirname, '.auth');
const SETTINGS_OPTION = 'woocommerce_arkid-catalogue-link_settings';

function wp(args) {
	return execFileSync('wp', [`--path=${WP_PATH}`, ...args], {
		encoding: 'utf8',
	}).trim();
}

// The WooCommerce settings option is only written once a merchant saves the
// settings screen, so on a fresh install `wp option patch` fails outright with
// "No data exists for key". Read-merge-write works whether or not the option
// exists, and preserves any keys an earlier run set.
function seedSetting(key, value) {
	let settings = {};
	try {
		const raw = JSON.parse(
			wp(['option', 'get', SETTINGS_OPTION, '--format=json'])
		);
		// An empty option round-trips as [], which would swallow the key.
		if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
			settings = raw;
		}
	} catch {
		// Option absent on a fresh install — start from an empty object.
	}

	settings[key] = value;
	wp(['option', 'update', SETTINGS_OPTION, JSON.stringify(settings), '--format=json']);
}

async function waitForSite() {
	const api = await request.newContext();
	for (let attempt = 0; attempt < 30; attempt++) {
		try {
			const res = await api.get(`${BASE_URL}/wp-json/`);
			if (res.ok()) {
				const body = await res.json();
				const namespaces = body.namespaces || [];
				// Fail on line one rather than as twenty mystery timeouts: if the
				// plugin didn't boot, its REST namespace is absent.
				if (!namespaces.includes('arkid-catalogue-link/v1')) {
					throw new Error(
						'The arkid-catalogue-link/v1 REST namespace is missing — the plugin is not booting. ' +
							'Check that vendor/ exists at the plugin path and that WooCommerce is active.'
					);
				}
				await api.dispose();
				return;
			}
		} catch (err) {
			if (String(err.message).includes('REST namespace')) {
				throw err;
			}
		}
		await new Promise((resolve) => setTimeout(resolve, 1000));
	}
	await api.dispose();
	throw new Error(`WordPress did not become ready at ${BASE_URL}`);
}

function assertNoAutoloadShim() {
	// The devcontainer installs a mu-plugin shim when the `vendor` volume is not
	// mounted at the plugin path. It makes the site work, but the site then does
	// not match what a merchant installs — so E2E results would be meaningless.
	const shim = path.join(
		WP_PATH,
		'wp-content/mu-plugins/zz-arkid-autoload-fallback.php'
	);
	if (fs.existsSync(shim)) {
		throw new Error(
			'The plugin is booting via the devcontainer autoloader fallback shim, not a real install.\n' +
				'Rebuild the stack from the host before running E2E: npm run dev:reset && npm run dev:up'
		);
	}
}

function seedFixtures() {
	wp(['option', 'update', 'woocommerce_coming_soon', 'no']);

	// Seeding overwrites whatever key the store had — including a real one a
	// developer set to click through the dev store. Stash it so the teardown can
	// put it back; without this, every E2E run silently de-authenticates it.
	let previous = null;
	try {
		previous = wp(['option', 'get', SETTINGS_OPTION, '--format=json']);
	} catch {
		// No option yet — the teardown deletes it instead of restoring.
	}
	fs.writeFileSync(
		path.join(AUTH_DIR, 'settings-backup.json'),
		JSON.stringify({ previous })
	);

	seedSetting('api_key', 'e2e-key');

	const withViewer = wp([
		'post',
		'create',
		'--post_type=product',
		'--post_status=publish',
		'--post_title=E2E Chair',
		'--porcelain',
	]);

	// A second product with NO featured image: this is the fixture that would
	// expose the classic gallery replacing every slide instead of only the first.
	const noFeatured = wp([
		'post',
		'create',
		'--post_type=product',
		'--post_status=publish',
		'--post_title=E2E Stool (no featured image)',
		'--porcelain',
	]);

	for (const id of [withViewer, noFeatured]) {
		wp(['post', 'meta', 'update', id, '_arkid_embed_id', 'e2e-viewer']);
		wp([
			'post',
			'meta',
			'update',
			id,
			'_arkid_embed_url',
			'https://catalogue.arkid.app/e/e2e-viewer',
		]);
		wp([
			'post',
			'meta',
			'update',
			id,
			'_arkid_embed_image',
			`${BASE_URL}/wp-content/uploads/e2e-thumb.png`,
		]);
	}

	fs.writeFileSync(
		path.join(AUTH_DIR, 'fixtures.json'),
		JSON.stringify({ withViewer, noFeatured }, null, 2)
	);
}

function installApiStub() {
	const target = path.join(WP_PATH, 'wp-content/mu-plugins');
	fs.mkdirSync(target, { recursive: true });
	fs.copyFileSync(
		path.join(__dirname, 'mu-plugins/arkid-e2e-api-stub.php'),
		path.join(target, 'arkid-e2e-api-stub.php')
	);
}

/**
 * Both theme families have to be present for the projects in
 * playwright.config.js, and the theme the developer was on has to survive the
 * run. Twenty Twenty-One is the classic one; it ships with WordPress.
 */
function ensureThemes() {
	const active = wp(['theme', 'list', '--status=active', '--field=name']);
	fs.writeFileSync(
		path.join(AUTH_DIR, 'theme-backup.json'),
		JSON.stringify({ active })
	);

	for (const slug of ['twentytwentyfive', 'twentytwentyone']) {
		try {
			wp(['theme', 'get', slug, '--field=name']);
		} catch {
			console.log(`[setup] installing ${slug}`);
			wp(['theme', 'install', slug]);
		}
	}
}

module.exports = async () => {
	fs.mkdirSync(AUTH_DIR, { recursive: true });

	assertNoAutoloadShim();
	ensureThemes();
	installApiStub();
	await waitForSite();
	seedFixtures();

	const browser = await chromium.launch();
	const page = await browser.newPage({ baseURL: BASE_URL });
	await page.goto('/wp-login.php');
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/);
	await page
		.context()
		.storageState({ path: path.join(AUTH_DIR, 'admin.json') });
	await browser.close();
};
