const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '../..');

/**
 * Everything the WordPress.org assets pipeline needs to agree on, in one place.
 *
 * To change what the screenshots show, change HERO_VIEWER: the demo product's
 * title, featured image and the viewer in the storefront shots all come from
 * that one entry of the recorded API payload.
 */
module.exports = {
	ROOT,

	BASE_URL: process.env.WP_BASE_URL || 'http://localhost:8888',
	WP_PATH: process.env.WP_PATH || '/var/www/html',

	// Where the finished PNGs land. `.wordpress-org/` is the folder name the
	// WordPress.org deploy actions expect; it is gitignored, because the files
	// are generated (see tests/store-assets/README.md).
	OUT_DIR: process.env.ASSETS_OUT_DIR || path.join(ROOT, '.wordpress-org'),

	// Scratch: the logged-in browser state and the ids global-setup created.
	// Both are per-run and gitignored.
	STATE_DIR: path.join(__dirname, '.state'),

	// What the demo store calls itself. It shows in the admin bar and the
	// storefront header, so it must not look like a real shop.
	STORE_NAME: 'Demo Store',
	STORE_TAGLINE: 'A demo store for ARkid Catalogue Link',

	// `product_name` of the catalogue entry the demo product is built around.
	//
	// Brand rule for anything published: the only logos that may appear are
	// ARkid's own and Maxi-Cosi's. A viewer draws its brand's logo inside the
	// 3D scene, so the hero must be an ARkid or Maxi-Cosi model. Other brands'
	// products may appear in lists and as banner artwork, never as the hero.
	HERO_VIEWER: 'Fame - New Generation',

	// Placeholder content for the demo product. Deliberately generic, so it
	// reads right whichever viewer HERO_VIEWER picks.
	PRODUCT: {
		price: '49.00',
		shortDescription:
			'A demo product, here to show ARkid Catalogue Link on a product page.',
		description:
			'<p>A demo product, here to show ARkid Catalogue Link on a product page.</p>' +
			'<p>Open the 3D viewer to turn the product around and, on a phone, place it in your own living room in AR.</p>',
	},

	// A fake key. The settings field is a password input, so only dots are ever
	// drawn, and the stubbed API never checks it.
	DEMO_API_KEY: 'demo-api-key-not-a-real-credential',

	// Brands whose logos may appear in published images. A viewer draws its
	// brand's logo inside the 3D scene, so this is what decides which viewer can
	// be the hero. Other brands' products may still appear as list rows and as
	// banner artwork, because a cover image carries no logo.
	APPROVED_LOGO_BRANDS: [/^arkid/i, /maxi[\s-]?cosi/i],

	// Chrome flags that make WebGL work on a machine with no GPU, which is every
	// CI runner and this sandbox: the 3D viewer renders on SwiftShader.
	CHROMIUM_ARGS: [
		'--use-angle=swiftshader',
		'--enable-unsafe-swiftshader',
		'--ignore-gpu-blocklist',
	],

	isApprovedBrand(viewer) {
		return module.exports.APPROVED_LOGO_BRANDS.some((pattern) =>
			pattern.test(viewer.brand_name || '')
		);
	},

	/**
	 * The catalogue the screenshots show, one entry per product.
	 *
	 * `catalogue.json` is what `npm run assets:record` wrote from the live test
	 * account. Without it, fall back to the contract fixtures so the pipeline
	 * still runs on a machine with no API key.
	 *
	 * Either source can list a product more than once (the same product in two
	 * colourways). A merchant's picker showing "Felix Learning Tower" twice
	 * would look like a bug, so keep the first.
	 */
	loadViewers() {
		const recorded = path.join(__dirname, 'catalogue.json');
		const source = fs.existsSync(recorded)
			? recorded
			: path.join(ROOT, 'tests/fixtures/api/embed-list.json');

		const raw = JSON.parse(fs.readFileSync(source, 'utf8'));
		const seen = new Set();
		return raw.data.filter((embed) => {
			const key = `${embed.brand_name}|${embed.product_name}`;
			if (seen.has(key)) {
				return false;
			}
			seen.add(key);
			return true;
		});
	},

	heroViewer() {
		const viewers = module.exports.loadViewers();
		const hero = viewers.find(
			(viewer) => viewer.product_name === module.exports.HERO_VIEWER
		);
		if (!hero) {
			throw new Error(
				`HERO_VIEWER "${module.exports.HERO_VIEWER}" is not in the catalogue.`
			);
		}
		// Not a suggestion: this is the one place a third-party logo could slip
		// into a published image, so refuse rather than render it.
		if (!module.exports.isApprovedBrand(hero)) {
			throw new Error(
				`HERO_VIEWER "${hero.product_name}" is by "${hero.brand_name}", and only ` +
					'ARkid and Maxi-Cosi logos may appear in published images. ' +
					'Pick one of their viewers.'
			);
		}
		return hero;
	},
};
