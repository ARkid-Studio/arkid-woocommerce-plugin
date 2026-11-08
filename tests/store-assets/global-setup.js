/* eslint-disable no-console */
const { chromium, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { wp } = require('../e2e/helpers');
const config = require('./config');

const SETTINGS_OPTION = 'woocommerce_arkid-catalogue-link_settings';
const ADMIN_USER = process.env.E2E_WP_USER || 'admin';
const ADMIN_PASS = process.env.E2E_WP_PASS || 'password';

async function waitForSite() {
	const api = await request.newContext();
	for (let attempt = 0; attempt < 30; attempt++) {
		try {
			const res = await api.get(`${config.BASE_URL}/wp-json/`);
			if (res.ok()) {
				const body = await res.json();
				if (
					!(body.namespaces || []).includes('arkid-catalogue-link/v1')
				) {
					throw new Error(
						'The arkid-catalogue-link/v1 REST namespace is missing — the plugin is not booting.'
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
	throw new Error(`WordPress did not become ready at ${config.BASE_URL}`);
}

function optionOrNull(name) {
	try {
		return wp(['option', 'get', name, '--format=json']);
	} catch {
		return null;
	}
}

/**
 * Remember what the run is about to overwrite, so global-teardown can put it
 * back. CI sites are thrown away, but a developer running this against their
 * dev store should not lose their site title or API key.
 */
function backUp() {
	const backup = {
		blogname: wp(['option', 'get', 'blogname']),
		blogdescription: wp(['option', 'get', 'blogdescription']),
		displayName: wp(['user', 'get', ADMIN_USER, '--field=display_name']),
		theme: wp(['theme', 'list', '--status=active', '--field=name']),
		settings: optionOrNull(SETTINGS_OPTION),
	};
	fs.writeFileSync(
		path.join(config.STATE_DIR, 'backup.json'),
		JSON.stringify(backup)
	);
}

function ensureTheme() {
	try {
		wp(['theme', 'get', 'twentytwentyfive', '--field=name']);
	} catch {
		console.log('[assets] installing twentytwentyfive');
		wp(['theme', 'install', 'twentytwentyfive']);
	}
	wp(['theme', 'activate', 'twentytwentyfive']);
}

function installApiStub(viewers) {
	const target = path.join(config.WP_PATH, 'wp-content/mu-plugins');
	fs.mkdirSync(target, { recursive: true });
	fs.copyFileSync(
		path.join(__dirname, 'mu-plugins/arkid-assets-api-stub.php'),
		path.join(target, 'arkid-assets-api-stub.php')
	);
	fs.writeFileSync(
		path.join(target, 'arkid-assets-api-stub.json'),
		JSON.stringify(viewers)
	);
}

function seedStore() {
	wp(['option', 'update', 'woocommerce_coming_soon', 'no']);
	wp(['option', 'update', 'blogname', config.STORE_NAME]);
	wp(['option', 'update', 'blogdescription', config.STORE_TAGLINE]);
	wp(['user', 'update', ADMIN_USER, '--display_name=Store Manager']);

	// Read-merge-write: the option is only created once a merchant saves the
	// settings screen, so `wp option patch` would fail on a fresh install.
	let settings = {};
	try {
		const raw = JSON.parse(
			wp(['option', 'get', SETTINGS_OPTION, '--format=json'])
		);
		if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
			settings = raw;
		}
	} catch {
		// Absent on a fresh install.
	}
	settings.api_key = config.DEMO_API_KEY;
	settings.default_position = 'below_product';
	wp([
		'option',
		'update',
		SETTINGS_OPTION,
		JSON.stringify(settings),
		'--format=json',
	]);
}

/**
 * The demo product: the hero viewer's own title and cover image, so the product
 * photo and the 3D viewer agree. No viewer is attached yet — the first
 * screenshot picks it through the real meta box, which is the flow it shows.
 */
function createProduct(hero) {
	const id = wp([
		'wc',
		'product',
		'create',
		`--user=${ADMIN_USER}`,
		`--name=${hero.product_name}`,
		'--type=simple',
		`--regular_price=${config.PRODUCT.price}`,
		`--description=${config.PRODUCT.description}`,
		`--short_description=${config.PRODUCT.shortDescription}`,
		`--images=${JSON.stringify([{ src: hero.image }])}`,
		'--porcelain',
	]);
	return id;
}

/**
 * Put our meta box at the top of the side column. Left where WordPress puts it,
 * below Publish, it sits at the bottom edge of a 1280x800 screenshot — and the
 * picker's dropdown then opens UPWARD, over the box's own heading.
 */
function orderMetaBoxes() {
	const order = {
		side: 'arkid-catalogue-link-metabox,submitdiv,product_catdiv,tagsdiv-product_tag,postimagediv,woocommerce-product-images',
		normal: 'woocommerce-product-data,postexcerpt,postcustom,slugdiv,commentsdiv',
		advanced: '',
	};
	wp([
		'user',
		'meta',
		'update',
		ADMIN_USER,
		'meta-box-order_product',
		JSON.stringify(order),
		'--format=json',
	]);
}

module.exports = async () => {
	fs.mkdirSync(config.STATE_DIR, { recursive: true });
	fs.mkdirSync(config.OUT_DIR, { recursive: true });

	await waitForSite();
	backUp();

	const viewers = config.loadViewers();
	const hero = config.heroViewer();

	ensureTheme();
	installApiStub(viewers);
	seedStore();
	orderMetaBoxes();

	const productId = createProduct(hero);
	fs.writeFileSync(
		path.join(config.STATE_DIR, 'fixtures.json'),
		JSON.stringify({ productId, hero }, null, 2)
	);
	console.log(
		`[assets] demo product ${productId} "${hero.product_name}" ready`
	);

	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM_PATH || undefined,
		args: config.CHROMIUM_ARGS,
	});
	const page = await browser.newPage({ baseURL: config.BASE_URL });
	await page.goto('/wp-login.php');
	await page.fill('#user_login', ADMIN_USER);
	await page.fill('#user_pass', ADMIN_PASS);
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/);
	await page
		.context()
		.storageState({ path: path.join(config.STATE_DIR, 'admin.json') });
	await browser.close();
};
