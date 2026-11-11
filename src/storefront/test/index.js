/**
 * Internal dependencies
 */
import {
	DIALOG_ID,
	closeDialog,
	emptyHost,
	init,
	isAllowedEmbedUrl,
	mountIframe,
	openDialog,
	resetState,
	setTitle,
	viewerTitle,
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
		host: document.querySelector(
			'.arkid-catalogue-link__dialog-iframe-host'
		),
	};
}

// What Assets.php localizes: the server's filtered allow-list.
function allowHosts(hosts = ['catalogue.arkid.app']) {
	window.arkidCatalogueLinkStorefront = { allowedHosts: hosts };
}

describe('storefront dialog', () => {
	beforeEach(() => {
		resetState();
		allowHosts();
	});

	afterEach(() => {
		document.body.innerHTML = '';
		delete window.arkidCatalogueLinkStorefront;
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
		[
			'the close button',
			(dialog) =>
				dialog
					.querySelector('.arkid-catalogue-link__dialog-close')
					.click(),
		],
		['a backdrop click', (dialog) => dialog.click()],
		[
			'Escape',
			(dialog) =>
				dialog.dispatchEvent(
					new window.Event('cancel', { cancelable: true })
				),
		],
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
			dialog.querySelector('#arkid-catalogue-link__dialog-title')
				.textContent
		).toBe('Test Chair — 3D viewer');

		setTitle(dialog, '');
		expect(
			dialog.querySelector('#arkid-catalogue-link__dialog-title')
				.textContent
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
		const trigger = document.querySelector(
			'.arkid-catalogue-link__trigger'
		);
		trigger.removeAttribute('data-embed-url');
		const open = jest.spyOn(window, 'open').mockImplementation(() => {});

		expect(() => openDialog(null, trigger)).not.toThrow();
		expect(open).not.toHaveBeenCalled();
		open.mockRestore();
	});

	it('tolerates a dialog with no iframe host', () => {
		document.body.innerHTML = `<dialog id="${DIALOG_ID}"></dialog>`;
		const dialog = document.getElementById(DIALOG_ID);

		expect(() =>
			mountIframe(dialog, EMBED_URL, 'Test Chair')
		).not.toThrow();
		expect(() => emptyHost(dialog)).not.toThrow();
	});

	it('ignores clicks that are not on a trigger', () => {
		const { dialog } = renderPage();
		document.body.insertAdjacentHTML(
			'beforeend',
			'<p id="elsewhere">hi</p>'
		);
		init();

		document.getElementById('elsewhere').click();

		expect(dialog.open).toBe(false);
	});
});

/**
 * The trigger's `data-embed-url` is page content. WordPress lets a user without
 * `unfiltered_html` keep `class` and `data-*` on a button, so a trigger can be
 * planted in a product description; without a check here, `javascript:` set as
 * an iframe's `src` runs in the page's origin.
 */
describe('embed URL allow-list', () => {
	beforeEach(() => {
		resetState();
		allowHosts();
	});

	afterEach(() => {
		document.body.innerHTML = '';
		delete window.arkidCatalogueLinkStorefront;
	});

	it.each([
		[
			'an https URL on the allowed host',
			'https://catalogue.arkid.app/embed/x',
			true,
		],
		[
			'a subdomain of the allowed host',
			'https://eu.catalogue.arkid.app/embed/x',
			true,
		],
		['a host in capitals', 'https://CATALOGUE.ARKID.APP/embed/x', true],
		['a javascript: URL', 'javascript:alert(1)', false],
		['a data: URL', 'data:text/html,<script>alert(1)</script>', false],
		['plain http', 'http://catalogue.arkid.app/embed/x', false],
		['another host', 'https://evil.example/embed/x', false],
		[
			'a look-alike suffix',
			'https://catalogue.arkid.app.evil.example/embed/x',
			false,
		],
		[
			'a look-alike prefix',
			'https://evilcatalogue.arkid.app/embed/x',
			false,
		],
		[
			'userinfo that names the allowed host',
			'https://catalogue.arkid.app@evil.example/',
			false,
		],
		['a protocol-relative URL', '//catalogue.arkid.app/embed/x', false],
		['a relative path', '/embed/x', false],
		['an empty string', '', false],
		['not a string', null, false],
	])('%s', (_label, url, expected) => {
		expect(isAllowedEmbedUrl(url)).toBe(expected);
	});

	it('follows the list the server sends, not a hard-coded host', () => {
		allowHosts(['viewer.example.com']);

		expect(isAllowedEmbedUrl('https://viewer.example.com/e/1')).toBe(true);
		expect(isAllowedEmbedUrl('https://catalogue.arkid.app/e/1')).toBe(
			false
		);
	});

	it.each([
		['no list was localized', undefined],
		['the list is empty', { allowedHosts: [] }],
		['the list is not an array', { allowedHosts: 'catalogue.arkid.app' }],
	])('fails closed when %s', (_label, config) => {
		window.arkidCatalogueLinkStorefront = config;

		expect(isAllowedEmbedUrl('https://catalogue.arkid.app/e/1')).toBe(
			false
		);
	});

	it('does not mount a viewer from an untrusted URL', () => {
		const { dialog, host } = renderPage();

		mountIframe(dialog, 'javascript:window.__pwned = true', 'Test Chair');

		expect(host.querySelector('iframe')).toBeNull();
	});

	it('does not open the dialog for a planted trigger', () => {
		const { trigger, dialog, host } = renderPage();
		trigger.setAttribute(
			'data-embed-url',
			'javascript:window.__pwned = true'
		);
		init();

		trigger.click();

		expect(dialog.open).toBe(false);
		expect(host.querySelector('iframe')).toBeNull();
		expect(trigger.getAttribute('aria-expanded')).toBe('false');
		expect(window.__pwned).toBeUndefined();
	});

	it('does not open a new window for an untrusted URL either', () => {
		const { trigger } = renderPage({ withDialog: false });
		trigger.setAttribute('data-embed-url', 'https://evil.example/phish');
		const open = jest.spyOn(window, 'open').mockImplementation(() => {});

		openDialog(null, trigger);

		expect(open).not.toHaveBeenCalled();
		open.mockRestore();
	});
});

/**
 * The modal's accessible name comes from strings the server translates. It
 * used to be hard-coded English, so every translated storefront announced an
 * English dialog.
 */
describe('viewer title', () => {
	const GERMAN = {
		viewerTitle: '%s – 3D-Viewer',
		viewerTitleNoName: '3D-Viewer',
	};

	afterEach(() => {
		document.body.innerHTML = '';
		delete window.arkidCatalogueLinkStorefront;
	});

	it('uses the translated strings the server sends', () => {
		window.arkidCatalogueLinkStorefront = { i18n: GERMAN };

		expect(viewerTitle('Stuhl')).toBe('Stuhl – 3D-Viewer');
		expect(viewerTitle('')).toBe('3D-Viewer');
	});

	it('falls back to English without them', () => {
		expect(viewerTitle('Chair')).toBe('Chair — 3D viewer');
		expect(viewerTitle('')).toBe('3D viewer');
	});

	it('ignores a translation that lost its placeholder', () => {
		window.arkidCatalogueLinkStorefront = {
			i18n: { viewerTitle: '3D-Viewer' },
		};

		expect(viewerTitle('Chair')).toBe('Chair — 3D viewer');
	});

	it.each([['$&'], ['$1'], ["$'"], ['$$']])(
		'keeps a product name containing %s literally',
		(pattern) => {
			const name = `Chair ${pattern} Co`;

			expect(viewerTitle(name)).toBe(`${name} — 3D viewer`);
		}
	);

	it('titles both the iframe and the dialog heading', () => {
		window.arkidCatalogueLinkStorefront = {
			allowedHosts: ['catalogue.arkid.app'],
			i18n: GERMAN,
		};
		const { dialog, host } = renderPage();

		mountIframe(dialog, EMBED_URL, 'Stuhl');
		setTitle(dialog, 'Stuhl');

		expect(host.querySelector('iframe').title).toBe('Stuhl – 3D-Viewer');
		expect(
			dialog.querySelector('#arkid-catalogue-link__dialog-title')
				.textContent
		).toBe('Stuhl – 3D-Viewer');
	});
});
