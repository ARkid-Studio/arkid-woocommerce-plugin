/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');
const { wp } = require('../e2e/helpers');
const config = require('./config');

const SETTINGS_OPTION = 'woocommerce_arkid-catalogue-link_settings';
const ADMIN_USER = process.env.E2E_WP_USER || 'admin';

function attempt(label, fn) {
	try {
		fn();
	} catch (err) {
		console.log(
			`[assets teardown] ${label}: ${String(err.message).trim()}`
		);
	}
}

/**
 * Undo what global-setup did to the site, so running this against a dev store
 * leaves it as it was. On a CI runner none of it matters, the site is thrown
 * away; the stub is the part that must never survive, because while it is in
 * place every request to ARkid silently gets recorded data back.
 */
module.exports = async () => {
	const mu = path.join(config.WP_PATH, 'wp-content/mu-plugins');
	for (const file of [
		'arkid-assets-api-stub.php',
		'arkid-assets-api-stub.json',
	]) {
		fs.rmSync(path.join(mu, file), { force: true });
	}

	const fixtures = path.join(config.STATE_DIR, 'fixtures.json');
	if (fs.existsSync(fixtures)) {
		const { productId } = JSON.parse(fs.readFileSync(fixtures, 'utf8'));
		attempt('delete demo product', () =>
			wp(['post', 'delete', String(productId), '--force'])
		);
	}

	const backupFile = path.join(config.STATE_DIR, 'backup.json');
	if (!fs.existsSync(backupFile)) {
		return;
	}
	const backup = JSON.parse(fs.readFileSync(backupFile, 'utf8'));

	attempt('blogname', () =>
		wp(['option', 'update', 'blogname', backup.blogname])
	);
	attempt('blogdescription', () =>
		wp(['option', 'update', 'blogdescription', backup.blogdescription])
	);
	attempt('display name', () =>
		wp([
			'user',
			'update',
			ADMIN_USER,
			`--display_name=${backup.displayName}`,
		])
	);
	attempt('theme', () => wp(['theme', 'activate', backup.theme]));
	attempt('meta box order', () =>
		wp(['user', 'meta', 'delete', ADMIN_USER, 'meta-box-order_product'])
	);

	if (backup.settings === null) {
		attempt('settings', () => wp(['option', 'delete', SETTINGS_OPTION]));
	} else {
		attempt('settings', () =>
			wp([
				'option',
				'update',
				SETTINGS_OPTION,
				backup.settings,
				'--format=json',
			])
		);
	}

	fs.rmSync(backupFile, { force: true });
	console.log('[assets teardown] stub removed, site restored');
};
