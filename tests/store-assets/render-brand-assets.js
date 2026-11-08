/* eslint-disable no-console */
/**
 * Renders the WordPress.org icon and banner.
 *
 *   npm run assets:brand
 *
 * They are drawn in a browser from HTML/SVG rather than exported from a design
 * tool, so a change to the wording or the colours is an edit to this file and a
 * re-run, not a trip to someone's Figma. The vector logo lives in brand/.
 *
 * Writes into config.OUT_DIR:
 *   icon-128x128.png  icon-256x256.png  banner-772x250.png  banner-1544x500.png
 */
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const config = require('./config');

const BRAND = path.join(__dirname, 'brand');
const mark = fs.readFileSync(path.join(BRAND, 'arkid-a.svg'), 'utf8');
const wordmark = fs.readFileSync(
	path.join(BRAND, 'arkid-wordmark.svg'),
	'utf8'
);

// ARkid navy: #132042 is the logo, the gradient is sampled from the icon the
// Shopware plugin already ships, so the three marketplaces match.
const NAVY_GRADIENT =
	'radial-gradient(120% 120% at 18% 82%, #17306f 0%, #122455 55%, #0f1d46 100%)';

const BASE_CSS = `
	* { box-sizing: border-box; margin: 0; padding: 0; }
	html, body { background: transparent; }
	body { font-family: Inter, "Helvetica Neue", Helvetica, Arial, "Liberation Sans", sans-serif; }
	svg { display: block; width: 100%; height: auto; }
`;

function iconHtml(size) {
	return `<!doctype html><meta charset="utf-8"><style>${BASE_CSS}
		.icon { width: ${size}px; height: ${size}px; background: ${NAVY_GRADIENT};
			display: grid; place-items: center; color: #fff; }
		.icon .mark { width: 60%; }
	</style><div class="icon"><div class="mark">${mark}</div></div>`;
}

/**
 * Three products from the catalogue, hero in the middle.
 *
 * The covers are the same transparent renders the plugin's own viewer list
 * shows, loaded from ARkid's CDN: the banner sells the catalogue, so it should
 * show products from it. Which products is decided by tests/fixtures and
 * config.HERO_VIEWER, not here.
 */
function bannerProducts() {
	const hero = config.heroViewer();
	const others = config
		.loadViewers()
		.filter(
			(viewer) =>
				viewer.embed_id !== hero.embed_id &&
				viewer.brand_name !== 'ARkid Studio'
		)
		.slice(0, 2);
	const [left, right] = others;
	return [left, hero, right].filter(Boolean).map((viewer) => viewer.image);
}

function bannerHtml() {
	const [a, b, c] = bannerProducts();
	return `<!doctype html><meta charset="utf-8"><style>${BASE_CSS}
		.banner { position: relative; width: 772px; height: 250px; overflow: hidden;
			background: ${NAVY_GRADIENT}; color: #fff; }
		.copy { position: absolute; left: 44px; top: 0; bottom: 0; width: 330px;
			display: flex; flex-direction: column; justify-content: center; }
		.wordmark { width: 124px; margin-bottom: 14px; }
		.name { font-size: 25px; font-weight: 700; letter-spacing: -0.01em; line-height: 1.15; }
		.tag { margin-top: 10px; font-size: 14px; line-height: 1.4; color: rgba(255,255,255,0.82); }
		.stage { position: absolute; left: 372px; right: 0; top: 0; bottom: 0; }
		.stage::before { content: ""; position: absolute; left: 0; right: 0; bottom: -110px; height: 230px;
			border-radius: 50%; background: radial-gradient(closest-side, rgba(255,255,255,0.22), rgba(255,255,255,0)); }
		.stage img { position: absolute; width: auto;
			filter: drop-shadow(0 8px 12px rgba(5,10,30,0.45)); }
		.stage .p-a { left: 8px; height: 120px; bottom: 38px; }
		.stage .p-b { left: 98px; height: 184px; bottom: 26px; z-index: 2; }
		.stage .p-c { left: 278px; height: 118px; bottom: 38px; }
	</style>
	<div class="banner">
		<div class="copy">
			<div class="wordmark">${wordmark}</div>
			<div class="name">Catalogue Link</div>
			<div class="tag">3D &amp; AR viewers for WooCommerce</div>
		</div>
		<div class="stage">
			<img class="p-a" src="${a}" alt="">
			<img class="p-b" src="${b}" alt="">
			<img class="p-c" src="${c}" alt="">
		</div>
	</div>`;
}

async function render(browser, html, width, height, scale, file) {
	const context = await browser.newContext({
		viewport: { width, height },
		deviceScaleFactor: scale,
	});
	const page = await context.newPage();
	await page.setContent(html, { waitUntil: 'load' });
	// `load` waits for the images, but a failed fetch still "loads" as a broken
	// icon. A banner with a hole in it must fail the run, not ship.
	const broken = await page.evaluate(() =>
		[...document.images]
			.filter((img) => !img.naturalWidth)
			.map((i) => i.src)
	);
	if (broken.length) {
		throw new Error(`Could not load: ${broken.join(', ')}`);
	}
	await page.screenshot({
		path: path.join(config.OUT_DIR, file),
		omitBackground: false,
	});
	await context.close();
	console.log(`[brand] ${file}`);
}

(async () => {
	fs.mkdirSync(config.OUT_DIR, { recursive: true });
	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM_PATH || undefined,
	});
	try {
		await render(browser, iconHtml(128), 128, 128, 1, 'icon-128x128.png');
		await render(browser, iconHtml(256), 256, 256, 1, 'icon-256x256.png');
		await render(browser, bannerHtml(), 772, 250, 1, 'banner-772x250.png');
		await render(browser, bannerHtml(), 772, 250, 2, 'banner-1544x500.png');
	} finally {
		await browser.close();
	}
})().catch((err) => {
	console.error(err);
	process.exit(1);
});
