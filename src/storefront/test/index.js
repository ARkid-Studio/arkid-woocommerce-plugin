/**
 * Internal dependencies
 */
import {
	DIALOG_ID,
	closeDialog,
	emptyHost,
	init,
	mountIframe,
	openDialog,
	resetState,
	setTitle,
} from '../index';

const EMBED_URL = 'https://catalogue.arkid.app/e/abc-123?source=woocommerce';

function renderPage({ withDialog = true } = {}) {
	document.body.innerHTML = `
		<button
			type="button"
			class="arkid-catalogue-link__trigger"
			aria-expanded="false"
			data-embed-url="${EMBED_URL}"
			data-product-name="Test Chair"
		>Open</button>
		${
			withDialog
				? `<dialog id="${DIALOG_ID}">
			<div class="arkid-catalogue-link__dialog-frame">
				<h2 id="arkid-catalogue-link__dialog-title"></h2>
				<button type="button" class="arkid-catalogue-link__dialog-close">x</button>
				<div class="arkid-catalogue-link__dialog-iframe-host"
					data-iframe-allow="xr-spatial-tracking"
					data-iframe-sandbox="allow-scripts allow-same-origin allow-popups allow-forms"></div>
			</div>
		</dialog>`
				: ''
		}
	`;

	return {
		trigger: document.querySelector('.arkid-catalogue-link__trigger'),
		dialog: document.getElementById(DIALOG_ID),
		host: document.querySelector('.arkid-catalogue-link__dialog-iframe-host'),
	};
}

describe('storefront dialog', () => {
	beforeEach(() => {
		resetState();
	});

	afterEach(() => {
		document.body.innerHTML = '';
	});

	it('opens from a delegated trigger click', () => {
		const { trigger, dialog } = renderPage();
		init();

		trigger.click();

		expect(dialog.open).toBe(true);
		expect(trigger.getAttribute('aria-expanded')).toBe('true');
	});

	it('mounts the iframe with the security attributes the server also sets', () => {
		const { dialog, host } = renderPage();

		mountIframe(dialog, EMBED_URL, 'Test Chair');

		const iframe = host.querySelector('iframe');
		expect(iframe.getAttribute('src')).toBe(EMBED_URL);
		expect(iframe.getAttribute('sandbox')).toBe(
			'allow-scripts allow-same-origin allow-popups allow-forms'
		);
		expect(iframe.getAttribute('allow')).toBe('xr-spatial-tracking');
		expect(iframe.getAttribute('referrerpolicy')).toBe(
			'strict-origin-when-cross-origin'
		);
		expect(iframe.getAttribute('loading')).toBe('lazy');
	});

	it('honours a host that narrows the sandbox', () => {
		const { dialog, host } = renderPage();
		host.dataset.iframeSandbox = 'allow-scripts';

		mountIframe(dialog, EMBED_URL, 'Test Chair');

		expect(host.querySelector('iframe').getAttribute('sandbox')).toBe(
			'allow-scripts'
		);
	});

	it('never accumulates iframes across open/close cycles', () => {
		const { trigger, dialog, host } = renderPage();
		init();

		trigger.click();
		closeDialog(dialog);
		trigger.click();

		expect(host.querySelectorAll('iframe')).toHaveLength(1);
	});

	it.each([
		['the close button', (dialog) => dialog.querySelector('.arkid-catalogue-link__dialog-close').click()],
		['a backdrop click', (dialog) => dialog.click()],
		['Escape', (dialog) => dialog.dispatchEvent(new window.Event('cancel', { cancelable: true }))],
	])('closes on %s and returns focus to the trigger', (_label, act) => {
		const { trigger, dialog, host } = renderPage();
		init();
		trigger.click();

		act(dialog);

		expect(dialog.open).toBe(false);
		expect(host.querySelector('iframe')).toBeNull();
		expect(trigger.getAttribute('aria-expanded')).toBe('false');
		// WCAG 2.2 AA: focus must come back to what opened the dialog.
		expect(trigger.ownerDocument.activeElement).toBe(trigger);
	});

	it('titles the dialog after the product', () => {
		const { dialog } = renderPage();

		setTitle(dialog, 'Test Chair');
		expect(
			dialog.querySelector('#arkid-catalogue-link__dialog-title').textContent
		).toBe('Test Chair — 3D viewer');

		setTitle(dialog, '');
		expect(
			dialog.querySelector('#arkid-catalogue-link__dialog-title').textContent
		).toBe('3D viewer');
	});

	it('falls back to a new window when the dialog was filtered out', () => {
		const { trigger } = renderPage({ withDialog: false });
		const open = jest.spyOn(window, 'open').mockImplementation(() => {});

		openDialog(null, trigger);

		expect(open).toHaveBeenCalledWith(EMBED_URL, '_blank', 'noopener');
		open.mockRestore();
	});

	it('does nothing when a trigger carries no embed url', () => {
		renderPage({ withDialog: false });
		const trigger = document.querySelector('.arkid-catalogue-link__trigger');
		trigger.removeAttribute('data-embed-url');
		const open = jest.spyOn(window, 'open').mockImplementation(() => {});

		expect(() => openDialog(null, trigger)).not.toThrow();
		expect(open).not.toHaveBeenCalled();
		open.mockRestore();
	});

	it('tolerates a dialog with no iframe host', () => {
		document.body.innerHTML = `<dialog id="${DIALOG_ID}"></dialog>`;
		const dialog = document.getElementById(DIALOG_ID);

		expect(() => mountIframe(dialog, EMBED_URL, 'Test Chair')).not.toThrow();
		expect(() => emptyHost(dialog)).not.toThrow();
	});

	it('ignores clicks that are not on a trigger', () => {
		const { dialog } = renderPage();
		document.body.insertAdjacentHTML('beforeend', '<p id="elsewhere">hi</p>');
		init();

		document.getElementById('elsewhere').click();

		expect(dialog.open).toBe(false);
	});
});
