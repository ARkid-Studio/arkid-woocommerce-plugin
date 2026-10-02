/* eslint-disable no-console */
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const WP_PATH = process.env.WP_PATH || '/var/www/html';
const SETTINGS_OPTION = 'woocommerce_arkid-catalogue-link_settings';
const AUTH_DIR = path.join(__dirname, '.auth');

function wp(args) {
	return execFileSync('wp', [`--path=${WP_PATH}`, ...args], {
		encoding: 'utf8',
	}).trim();
}

/**
 * Delete the products global-setup created.
 *
 * Each run seeds two; without this they accumulate, and a dev store that has run
 * the suite a dozen times is a shop full of "E2E Chair" duplicates.
 */
function removeFixtureProducts() {
	const manifest = path.join(AUTH_DIR, 'fixtures.json');
	if (!fs.existsSync(manifest)) {
		return;
	}

	const ids = Object.values(JSON.parse(fs.readFileSync(manifest, 'utf8')));
	for (const id of ids) {
		try {
			wp(['post', 'delete', String(id), '--force']);
		} catch {
			// Already gone, or never created — nothing to undo.
		}
	}
	console.log(`[teardown] removed ${ids.length} fixture product(s)`);
}

/**
 * Put back the theme the developer was on before the run switched projects.
 */
function restoreTheme() {
	const backup = path.join(AUTH_DIR, 'theme-backup.json');
	if (!fs.existsSync(backup)) {
		return;
	}

	const { active } = JSON.parse(fs.readFileSync(backup, 'utf8'));
	try {
		wp(['theme', 'activate', active]);
		console.log(`[teardown] reactivated ${active}`);
	} catch {
		// Theme was removed mid-run; nothing sensible to restore.
	}

	fs.unlinkSync(backup);
}

/**
 * Put back the API key the run overwrote with `e2e-key`.
 */
function restoreSettings() {
	const backup = path.join(AUTH_DIR, 'settings-backup.json');
	if (!fs.existsSync(backup)) {
		return;
	}

	const { previous } = JSON.parse(fs.readFileSync(backup, 'utf8'));

	if (previous === null) {
		try {
			wp(['option', 'delete', SETTINGS_OPTION]);
		} catch {
			// Already gone.
		}
		console.log('[teardown] settings option removed (none before the run)');
	} else {
		wp(['option', 'update', SETTINGS_OPTION, previous, '--format=json']);
		console.log('[teardown] restored the settings that preceded the run');
	}

	fs.unlinkSync(backup);
}

/**
 * Remove the API stub global-setup installed.
 *
 * Without this it survives the run, and every later request — a manual click
 * through the dev store, a `wp eval` against the real API — silently gets stub
 * data back instead of ARkid's. That is a genuinely confusing thing to debug,
 * because the plugin looks like it is working.
 */
module.exports = async () => {
	const stub = path.join(
		WP_PATH,
		'wp-content/mu-plugins/arkid-e2e-api-stub.php'
	);

	if (fs.existsSync(stub)) {
		fs.unlinkSync(stub);
		console.log(`[teardown] removed ${stub}`);
	}

	removeFixtureProducts();
	restoreSettings();
	restoreTheme();
};
