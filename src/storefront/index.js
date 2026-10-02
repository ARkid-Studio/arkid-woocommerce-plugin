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
	if (!dialog) {
		// Defensive: someone hooked a trigger but the dialog was filtered
		// out. Open the embed URL in a new window as a fallback.
		const url = trigger.getAttribute('data-embed-url');
		if (url) {
			window.open(url, '_blank', 'noopener');
		}
		return;
	}

	const url = trigger.getAttribute('data-embed-url') || '';
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
	const host = dialog.querySelector(
		'.arkid-catalogue-link__dialog-iframe-host'
	);
	if (!host) {
		return;
	}
	const iframe = document.createElement('iframe');
	iframe.className = 'arkid-catalogue-link__viewer';
	iframe.src = url;
	iframe.title = productName ? `${productName} — 3D viewer` : '3D viewer';
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
	iframe.setAttribute(
		'referrerpolicy',
		'strict-origin-when-cross-origin'
	);
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
	const titleEl = dialog.querySelector(
		'#arkid-catalogue-link__dialog-title'
	);
	if (titleEl) {
		titleEl.textContent = productName
			? `${productName} — 3D viewer`
			: '3D viewer';
	}
}
