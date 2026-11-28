/**
 * ARkid Catalogue Link — the viewer preview of wp-admin.
 *
 * A button with `data-embed-url` (the viewers list, the product meta box) opens
 * one shared `<dialog>` and loads the viewer into it, only then: the viewer is
 * WebGL, and wp-admin must not start a dozen of them on load.
 *
 * `showModal()` gives the dialog its focus trap and Escape; the rest is what
 * the browser does not do: focus goes back to the button that opened it, a click
 * on the backdrop closes it, and the frame is emptied on close so that the
 * viewer stops.
 *
 * The address is checked against the hosts PHP allows
 * (`arkidCatalogueLinkAdmin.allowedHosts`) before it is ever set on a frame,
 * and the links of the footer are only used when they are https.
 */

/**
 * Internal dependencies
 */
import { isAllowedEmbedUrl, isHttpsUrl } from '../shared/viewer-url';

export const DIALOG_ID = 'arkid-catalogue-link-preview';
export const TRIGGER_SELECTOR = '.arkid-catalogue-link__preview';

const HOST_SELECTOR = '.arkid-catalogue-link__admin-dialog-host';
const STATUS_SELECTOR = '.arkid-catalogue-link__admin-dialog-status';

let opener = null;

function settings() {
	return window.arkidCatalogueLinkAdmin || {};
}

/**
 * Whether the viewer at `url` may be previewed.
 *
 * @param {*} url The value of `data-embed-url`.
 * @return {boolean} Whether it may.
 */
export function isPreviewable(url) {
	return isAllowedEmbedUrl(url, settings().allowedHosts);
}

/**
 * The title of the viewer's frame: "Name 3D viewer".
 *
 * @param {string} name The viewer's product, possibly empty.
 * @return {string} The title.
 */
export function frameTitle(name) {
	const i18n = settings().i18n || {};
	if (!name) {
		return i18n.viewerFrameTitleNoName || '3D viewer';
	}
	const template =
		typeof i18n.viewerFrameTitle === 'string' &&
		i18n.viewerFrameTitle.includes('%s')
			? i18n.viewerFrameTitle
			: '%s 3D viewer';
	// A function, not a string: product names are free text and `$&` is a pattern.
	return template.replace('%s', () => name);
}

/** Test seam: the module remembers which button to give focus back to. */
export function resetState() {
	opener = null;
}

function emptyHost(dialog) {
	const host = dialog.querySelector(HOST_SELECTOR);
	if (host) {
		host.replaceChildren();
	}
}

function setLink(dialog, role, url) {
	const link = dialog.querySelector(`[data-role="${role}"]`);
	if (!link) {
		return;
	}
	if (isHttpsUrl(url)) {
		link.href = url;
		link.hidden = false;
	} else {
		link.removeAttribute('href');
		link.hidden = true;
	}
}

function mountFrame(dialog, url, name) {
	// The sink checks as well as the caller: this is the line that would
	// otherwise hand an attribute's value to the browser.
	if (!isPreviewable(url)) {
		return false;
	}
	const host = dialog.querySelector(HOST_SELECTOR);
	if (!host) {
		return false;
	}

	const status = dialog.querySelector(STATUS_SELECTOR);
	if (status) {
		status.hidden = false;
	}

	const frame = document.createElement('iframe');
	frame.className = 'arkid-catalogue-link__admin-dialog-viewer';
	frame.src = url;
	frame.title = frameTitle(name);
	frame.setAttribute('allowfullscreen', '');
	frame.setAttribute('allow', 'xr-spatial-tracking');
	frame.setAttribute(
		'sandbox',
		'allow-scripts allow-same-origin allow-popups allow-forms'
	);
	frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
	frame.addEventListener('load', () => {
		if (status) {
			status.hidden = true;
		}
	});
	host.replaceChildren(frame);

	return true;
}

/**
 * Open the preview for a button.
 *
 * @param {HTMLElement} dialog  The preview `<dialog>`.
 * @param {HTMLElement} trigger The button, with its `data-*` attributes.
 * @return {boolean} Whether the preview opened.
 */
export function openPreview(dialog, trigger) {
	const url = trigger.getAttribute('data-embed-url') || '';
	const name = trigger.getAttribute('data-product-name') || '';

	if (!dialog || !isPreviewable(url)) {
		return false;
	}

	const title = dialog.querySelector(
		'.arkid-catalogue-link__admin-dialog-title'
	);
	if (title) {
		title.textContent = name;
	}
	setLink(dialog, 'edit', trigger.getAttribute('data-edit-url') || '');
	setLink(
		dialog,
		'analytics',
		trigger.getAttribute('data-analytics-url') || ''
	);

	if (!mountFrame(dialog, url, name)) {
		return false;
	}

	opener = trigger;
	trigger.setAttribute('aria-expanded', 'true');

	if (typeof dialog.showModal === 'function') {
		dialog.showModal();
	} else {
		dialog.setAttribute('open', 'open');
	}

	const close = dialog.querySelector('[data-role="close"]');
	if (close) {
		close.focus();
	}

	return true;
}

function handleClosed(dialog) {
	emptyHost(dialog);
	if (opener) {
		opener.setAttribute('aria-expanded', 'false');
		if (opener.isConnected) {
			opener.focus();
		}
		opener = null;
	}
}

export function closePreview(dialog) {
	if (typeof dialog.close === 'function') {
		dialog.close();
		return;
	}
	dialog.removeAttribute('open');
	handleClosed(dialog);
}

/**
 * Bind the buttons of the page and the dialog's own controls.
 *
 * Delegated, because the meta box changes its button's attributes (and the
 * viewers list is rendered by PHP) after this runs.
 */
export function init() {
	const dialog = document.getElementById(DIALOG_ID);
	if (!dialog) {
		return;
	}

	document.addEventListener('click', (event) => {
		const trigger = event.target.closest(TRIGGER_SELECTOR);
		if (!trigger || !trigger.hasAttribute('data-embed-url')) {
			return;
		}
		event.preventDefault();
		openPreview(dialog, trigger);
	});

	dialog
		.querySelectorAll('[data-role="close"], [data-role="dismiss"]')
		.forEach((button) =>
			button.addEventListener('click', () => closePreview(dialog))
		);

	// A click on the backdrop lands on the dialog itself, which the frame fills.
	dialog.addEventListener('click', (event) => {
		if (event.target === dialog) {
			closePreview(dialog);
		}
	});

	dialog.addEventListener('close', () => handleClosed(dialog));
}
