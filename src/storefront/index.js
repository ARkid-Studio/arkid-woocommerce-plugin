/**
 * ARkid Catalogue Link — storefront interactivity (vanilla JS).
 *
 * - Binds clicks on `.arkid-catalogue-link__trigger` to open the shared
 *   `<dialog>` and lazily mount the iframe.
 * - Closes on Esc, backdrop click, and the close button. Restores focus.
 * - Toggles `aria-expanded` on the trigger.
 *
 * Consent: when `arkidCatalogueLinkStorefront.requireConsent` is true, the
 * server already declined to render the embed if consent is missing — the
 * markup simply isn't on the page. This script only attaches behaviour to
 * markup that exists.
 */

/* eslint-disable @typescript-eslint/no-use-before-define -- Function declarations are hoisted; ordering reflects call flow. */
export const DIALOG_ID = 'arkid-catalogue-link__dialog';
export const TRIGGER_SELECTOR = '.arkid-catalogue-link__trigger';

let lastTrigger = null;

/**
 * Whether `url` may be loaded as a viewer: an absolute https URL on a host the
 * server allows. The list is the one PHP's IframeFactory uses, handed over in
 * `arkidCatalogueLinkStorefront.allowedHosts`.
 *
 * The URL comes from a `data-embed-url` attribute, and attributes are page
 * content, not a trusted channel: WordPress lets users without
 * `unfiltered_html` keep `class` and `data-*` on a button, so a trigger can be
 * planted in a product description. A `javascript:` URL set as an iframe's `src`
 * would then run in the page's origin.
 *
 * Fails closed: with no list from the server, nothing is allowed.
 *
 * @param {*} url The value to check.
 * @return {boolean} Whether it may be embedded.
 */
export function isAllowedEmbedUrl(url) {
	const config = window.arkidCatalogueLinkStorefront;
	const hosts =
		config && Array.isArray(config.allowedHosts) ? config.allowedHosts : [];

	// Mirror the server, which accepts only a literal `https://` URL.
	if (
		typeof url !== 'string' ||
		!url.startsWith('https://') ||
		!hosts.length
	) {
		return false;
	}

	let host;
	try {
		host = new URL(url).hostname.toLowerCase();
	} catch {
		return false;
	}

	return hosts.some(
		(allowed) =>
			typeof allowed === 'string' &&
			allowed !== '' &&
			(host === allowed || host.endsWith(`.${allowed}`))
	);
}

/**
 * The modal's accessible name: the iframe title and the dialog heading.
 *
 * Translated on the server and handed over in
 * `arkidCatalogueLinkStorefront.i18n`; English if the strings are missing.
 *
 * @param {string} productName Product the viewer belongs to, possibly empty.
 * @return {string} The title.
 */
export function viewerTitle(productName) {
	const config = window.arkidCatalogueLinkStorefront;
	const i18n = (config && config.i18n) || {};

	if (!productName) {
		return i18n.viewerTitleNoName || '3D viewer';
	}

	const template =
		typeof i18n.viewerTitle === 'string' && i18n.viewerTitle.includes('%s')
			? i18n.viewerTitle
			: '%s — 3D viewer';
	// A function, not a string: in a replacement string `$&` and `$1` are
	// patterns, and product names are free text. (A name containing `$1` once
	// corrupted the block gallery markup.)
	return template.replace('%s', () => productName);
}

/** Test seam: the module keeps focus-restore state between calls. */
export function resetState() {
	lastTrigger = null;
}

export function init() {
	const dialog = document.getElementById(DIALOG_ID);

	document.addEventListener('click', (event) => {
		const trigger = event.target.closest(TRIGGER_SELECTOR);
		if (!trigger) {
			return;
		}
		event.preventDefault();
		openDialog(dialog, trigger);
	});

	if (!dialog) {
		return;
	}

	const closeButton = dialog.querySelector(
		'.arkid-catalogue-link__dialog-close'
	);
	if (closeButton) {
		closeButton.addEventListener('click', () => closeDialog(dialog));
	}

	// Backdrop click: close when the click target is the dialog itself
	// (i.e. outside the inner frame).
	dialog.addEventListener('click', (event) => {
		if (event.target === dialog) {
			closeDialog(dialog);
		}
	});

	dialog.addEventListener('cancel', (event) => {
		// Native Esc handler — fall through to our close so we can
		// restore focus.
		event.preventDefault();
		closeDialog(dialog);
	});

	dialog.addEventListener('close', () => {
		emptyHost(dialog);
		if (lastTrigger) {
			lastTrigger.setAttribute('aria-expanded', 'false');
			lastTrigger.focus();
			lastTrigger = null;
		}
	});
}

export function openDialog(dialog, trigger) {
	const url = trigger.getAttribute('data-embed-url') || '';

	// Covers the new-window fallback below as well as the dialog.
	if (!isAllowedEmbedUrl(url)) {
		return;
	}

	if (!dialog) {
		// Defensive: someone hooked a trigger but the dialog was filtered
		// out. Open the embed URL in a new window as a fallback.
		window.open(url, '_blank', 'noopener');
		return;
	}

	const productName =
		trigger.getAttribute('data-product-name') || document.title || '';

	mountIframe(dialog, url, productName);
	setTitle(dialog, productName);

	lastTrigger = trigger;
	trigger.setAttribute('aria-expanded', 'true');

	if (typeof dialog.showModal === 'function') {
		dialog.showModal();
	} else {
		// Old-browser fallback.
		dialog.setAttribute('open', 'open');
	}
}

export function closeDialog(dialog) {
	if (typeof dialog.close === 'function') {
		dialog.close();
	} else {
		dialog.removeAttribute('open');
		emptyHost(dialog);
		if (lastTrigger) {
			lastTrigger.setAttribute('aria-expanded', 'false');
			lastTrigger.focus();
			lastTrigger = null;
		}
	}
}

export function mountIframe(dialog, url, productName) {
	// The sink checks as well as the caller: this is the line that would
	// otherwise hand an attribute's value to the browser.
	if (!isAllowedEmbedUrl(url)) {
		return;
	}
	const host = dialog.querySelector(
		'.arkid-catalogue-link__dialog-iframe-host'
	);
	if (!host) {
		return;
	}
	const iframe = document.createElement('iframe');
	iframe.className = 'arkid-catalogue-link__viewer';
	iframe.src = url;
	iframe.title = viewerTitle(productName);
	iframe.setAttribute('allowfullscreen', '');
	iframe.setAttribute(
		'allow',
		host.dataset.iframeAllow || 'xr-spatial-tracking'
	);
	iframe.setAttribute(
		'sandbox',
		host.dataset.iframeSandbox ||
			'allow-scripts allow-same-origin allow-popups allow-forms'
	);
	iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
	iframe.setAttribute('loading', 'lazy');
	host.appendChild(iframe);
}

export function emptyHost(dialog) {
	const host = dialog.querySelector(
		'.arkid-catalogue-link__dialog-iframe-host'
	);
	if (host) {
		host.replaceChildren();
	}
}

export function setTitle(dialog, productName) {
	const titleEl = dialog.querySelector('#arkid-catalogue-link__dialog-title');
	if (titleEl) {
		titleEl.textContent = viewerTitle(productName);
	}
}
