/**
 * Which addresses may be loaded as an ARkid viewer.
 *
 * Shared by the storefront modal and the admin preview, so that both fail
 * closed in the same way. Neither knows where the list of hosts comes from:
 * PHP's `IframeFactory::allowed_hosts()` is the one source and each script is
 * handed it in its own localised settings.
 */

/**
 * Whether `url` is an absolute https URL, the only kind that is ever loaded or
 * linked from API data.
 *
 * @param {*} url The value to check.
 * @return {boolean} Whether it is one.
 */
export function isHttpsUrl(url) {
	if (typeof url !== 'string' || !url.startsWith('https://')) {
		return false;
	}

	try {
		return new URL(url).hostname !== '';
	} catch {
		return false;
	}
}

/**
 * Whether `url` may be loaded as a viewer: an absolute https URL on one of the
 * hosts, or on a subdomain of one.
 *
 * The URL comes from a `data-embed-url` attribute, and attributes are page
 * content, not a trusted channel: a `javascript:` URL set as an iframe's `src`
 * would run in the page's origin.
 *
 * Fails closed: with no hosts, nothing is allowed.
 *
 * @param {*}     url   The value to check.
 * @param {Array} hosts Bare, lower-case host names the server allows.
 * @return {boolean} Whether it may be embedded.
 */
export function isAllowedEmbedUrl(url, hosts) {
	const allowedHosts = Array.isArray(hosts) ? hosts : [];

	// Mirror the server, which accepts only a literal `https://` URL.
	if (!isHttpsUrl(url) || !allowedHosts.length) {
		return false;
	}

	const host = new URL(url).hostname.toLowerCase();

	return allowedHosts.some(
		(allowed) =>
			typeof allowed === 'string' &&
			allowed !== '' &&
			(host === allowed || host.endsWith(`.${allowed}`))
	);
}
