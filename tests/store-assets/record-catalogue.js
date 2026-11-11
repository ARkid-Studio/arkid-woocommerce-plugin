/* eslint-disable no-console */
/**
 * Records the ARkid catalogue that the WordPress.org screenshots show.
 *
 *   npm run assets:record
 *
 * Calls the real ARkid API with the test account's key (dotenvx supplies
 * ARKID_API_KEY from .env, as for `npm run test:contract`) and writes the
 * response, verbatim, to tests/store-assets/catalogue.json. The screenshots
 * then use that file instead of the contract fixtures in tests/fixtures/api/,
 * which are for the test suite and must not change just because the marketing
 * data does.
 *
 * Re-record whenever the test account's catalogue changes, and look at what it
 * wrote before committing it: it is published in a public repository.
 */
const fs = require('fs');
const path = require('path');

const URL = 'https://catalogue.arkid.app/api/ecom/embed?source=woocommerce';
const OUT = path.join(__dirname, 'catalogue.json');

(async () => {
	const key = process.env.ARKID_API_KEY;
	if (!key || key.startsWith('encrypted:')) {
		throw new Error(
			'ARKID_API_KEY is not usable. Run this through `npm run assets:record` ' +
				'with .env.keys present (see CONTRIBUTING.md, "Secrets: dotenvx").'
		);
	}

	const response = await fetch(URL, {
		headers: {
			Accept: 'application/json',
			Authorization: `Basic ${Buffer.from(`woocommerce:${key}`).toString('base64')}`,
		},
	});
	if (!response.ok) {
		throw new Error(`ARkid API answered HTTP ${response.status}.`);
	}

	const payload = await response.json();
	if (!payload || !Array.isArray(payload.data) || !payload.data.length) {
		throw new Error('ARkid API returned no viewers.');
	}

	fs.writeFileSync(OUT, `${JSON.stringify(payload, null, '\t')}\n`);
	console.log(`[record] ${payload.data.length} viewers -> ${OUT}`);
	for (const viewer of payload.data) {
		console.log(
			`  #${viewer.row_number}  ${viewer.brand_name} — ${viewer.product_name}`
		);
	}
})().catch((err) => {
	console.error(err.message);
	process.exit(1);
});
