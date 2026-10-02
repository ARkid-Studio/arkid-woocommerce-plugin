const { execFileSync } = require('child_process');

const WP_PATH = process.env.WP_PATH || '/var/www/html';

/**
 * Run wp-cli against the dev install.
 *
 * @param {string[]} args
 * @return {string} trimmed stdout
 */
function wp(args) {
	return execFileSync('wp', [`--path=${WP_PATH}`, ...args], {
		encoding: 'utf8',
	}).trim();
}

function permalink(postId) {
	return wp(['post', 'url', String(postId)]);
}

/**
 * Drop a per-product override so the site default applies.
 *
 * `wp post meta delete` treats "the key was not there" as an error, which is not
 * a failure for our purposes — the desired state is "no override" either way.
 */
function clearPosition(productId) {
	try {
		wp([
			'post',
			'meta',
			'delete',
			String(productId),
			'_arkid_embed_position',
		]);
	} catch {
		// Already absent.
	}
}

function setPosition(productId, position) {
	wp([
		'post',
		'meta',
		'update',
		String(productId),
		'_arkid_embed_position',
		position,
	]);
}

function activeTheme() {
	return wp(['theme', 'list', '--status=active', '--field=name']);
}

/**
 * Switch themes, skipping the work when we are already on the right one.
 *
 * Theme state is global to the install, which is why playwright.config.js pins
 * workers to 1: two projects switching underneath each other would interleave.
 */
function activateTheme(slug) {
	if (activeTheme() === slug) {
		return;
	}
	wp(['theme', 'activate', slug]);
}

/**
 * Read a key out of the plugin's WooCommerce settings option.
 */
function getSetting(key) {
	try {
		const raw = wp([
			'option',
			'get',
			'woocommerce_arkid-catalogue-link_settings',
			'--format=json',
		]);
		const parsed = JSON.parse(raw);
		return parsed && typeof parsed === 'object' ? parsed[key] : undefined;
	} catch {
		return undefined;
	}
}

module.exports = {
	WP_PATH,
	activateTheme,
	clearPosition,
	activeTheme,
	getSetting,
	permalink,
	setPosition,
	wp,
};
