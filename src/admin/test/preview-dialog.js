/**
 * Internal dependencies
 */
import {
	DIALOG_ID,
	closePreview,
	frameTitle,
	init,
	isPreviewable,
	openPreview,
	resetState,
} from '../preview-dialog';

const URL_OK = 'https://catalogue.arkid.app/embed/abc?source=woocommerce';
const EDIT = 'https://catalogue.arkid.app/configure/edit/abc';
const ANALYTICS = 'https://catalogue.arkid.app/analytics/abc';

function button({
	url = URL_OK,
	name = 'Felix Learning Tower',
	edit = EDIT,
	analytics = ANALYTICS,
} = {}) {
	return `<button type="button" class="arkid-catalogue-link__preview" data-embed-url="${url}" data-product-name="${name}" data-edit-url="${edit}" data-analytics-url="${analytics}">Preview</button>`;
}

function renderPage(buttonHtml = button()) {
	document.body.innerHTML = `
		${buttonHtml}
		<dialog id="${DIALOG_ID}" aria-labelledby="${DIALOG_ID}-title">
			<h2 id="${DIALOG_ID}-title" class="arkid-catalogue-link__admin-dialog-title"></h2>
			<button type="button" data-role="dismiss">x</button>
			<p class="arkid-catalogue-link__admin-dialog-status" role="status">Loading</p>
			<div class="arkid-catalogue-link__admin-dialog-host"></div>
			<a data-role="edit" hidden>Edit in ARkid</a>
			<a data-role="analytics" hidden>Analytics</a>
			<button type="button" data-role="close">Close</button>
		</dialog>
	`;

	return {
		trigger: document.querySelector('.arkid-catalogue-link__preview'),
		dialog: document.getElementById(DIALOG_ID),
		host: document.querySelector(
			'.arkid-catalogue-link__admin-dialog-host'
		),
		status: document.querySelector(
			'.arkid-catalogue-link__admin-dialog-status'
		),
	};
}

beforeEach(() => {
	resetState();
	window.arkidCatalogueLinkAdmin = {
		allowedHosts: ['catalogue.arkid.app'],
		i18n: { viewerFrameTitle: '%s 3D viewer' },
	};
});

afterEach(() => {
	delete window.arkidCatalogueLinkAdmin;
	document.body.innerHTML = '';
});

describe('isPreviewable', () => {
	it('allows an https address on an ARkid host', () => {
		expect(isPreviewable(URL_OK)).toBe(true);
	});

	it.each([
		'https://evil.example/embed/abc',
		'http://catalogue.arkid.app/embed/abc',
		'javascript:alert(1)',
		'',
	])('refuses %p', (url) => {
		expect(isPreviewable(url)).toBe(false);
	});

	it('refuses everything when the server sent no hosts', () => {
		window.arkidCatalogueLinkAdmin = {};

		expect(isPreviewable(URL_OK)).toBe(false);
	});

	it('follows a host the server added', () => {
		window.arkidCatalogueLinkAdmin = {
			allowedHosts: ['viewer.example.com'],
		};

		expect(isPreviewable('https://viewer.example.com/e/1')).toBe(true);
		expect(isPreviewable(URL_OK)).toBe(false);
	});
});

describe('frameTitle', () => {
	it('names the viewer in the translated words', () => {
		window.arkidCatalogueLinkAdmin.i18n.viewerFrameTitle = '%s 3D-Viewer';

		expect(frameTitle('Felix Tower')).toBe('Felix Tower 3D-Viewer');
	});

	it('has a name without a product, and does not read it as a pattern', () => {
		expect(frameTitle('')).toBe('3D viewer');
		expect(frameTitle('Sand $& Sky')).toBe('Sand $& Sky 3D viewer');
	});
});

describe('openPreview', () => {
	it('loads the viewer into the dialog only when opened', () => {
		const { trigger, dialog, host } = renderPage();
		expect(host.querySelector('iframe')).toBeNull();

		expect(openPreview(dialog, trigger)).toBe(true);

		const frame = host.querySelector('iframe');
		expect(frame.src).toBe(URL_OK);
		expect(frame.title).toBe('Felix Learning Tower 3D viewer');
		expect(frame.getAttribute('sandbox')).toBe(
			'allow-scripts allow-same-origin allow-popups allow-forms'
		);
		expect(frame.getAttribute('allow')).toBe('xr-spatial-tracking');
		expect(frame.getAttribute('referrerpolicy')).toBe(
			'strict-origin-when-cross-origin'
		);
		expect(dialog.open).toBe(true);
		expect(
			dialog.querySelector('.arkid-catalogue-link__admin-dialog-title')
				.textContent
		).toBe('Felix Learning Tower');
		expect(trigger.getAttribute('aria-expanded')).toBe('true');
	});

	it('puts focus in the dialog', () => {
		const { trigger, dialog } = renderPage();

		openPreview(dialog, trigger);

		expect(dialog.ownerDocument.activeElement).toBe(
			dialog.querySelector('[data-role="close"]')
		);
	});

	it('shows the links into ARkid that are https and hides the others', () => {
		const { trigger, dialog } = renderPage(
			button({ edit: EDIT, analytics: 'javascript:alert(1)' })
		);

		openPreview(dialog, trigger);

		const edit = dialog.querySelector('[data-role="edit"]');
		const analytics = dialog.querySelector('[data-role="analytics"]');
		expect(edit.hidden).toBe(false);
		expect(edit.href).toBe(EDIT);
		expect(analytics.hidden).toBe(true);
		expect(analytics.hasAttribute('href')).toBe(false);
	});

	it('hides links a previous viewer left behind', () => {
		const { trigger, dialog } = renderPage();
		openPreview(dialog, trigger);
		closePreview(dialog);

		trigger.setAttribute('data-edit-url', '');
		openPreview(dialog, trigger);

		expect(dialog.querySelector('[data-role="edit"]').hidden).toBe(true);
	});

	it.each([
		['another host', 'https://evil.example/embed/abc'],
		['a lookalike host', 'https://catalogue.arkid.app.evil.example/e/1'],
		['plain http', 'http://catalogue.arkid.app/embed/abc'],
		['javascript', 'javascript:alert(document.domain)'],
		['data', 'data:text/html,<script>alert(1)</script>'],
		['nothing', ''],
	])('refuses %s: no frame, no dialog', (_, url) => {
		const { trigger, dialog, host } = renderPage(button({ url }));

		expect(openPreview(dialog, trigger)).toBe(false);

		expect(host.querySelector('iframe')).toBeNull();
		expect(dialog.open).not.toBe(true);
	});

	it('shows the loading status until the frame has loaded', () => {
		const { trigger, dialog, host, status } = renderPage();

		openPreview(dialog, trigger);
		expect(status.hidden).toBe(false);

		host.querySelector('iframe').dispatchEvent(new window.Event('load'));
		expect(status.hidden).toBe(true);
	});

	it('shows the loading status again for the next viewer', () => {
		const { trigger, dialog, host, status } = renderPage();
		openPreview(dialog, trigger);
		host.querySelector('iframe').dispatchEvent(new window.Event('load'));

		closePreview(dialog);
		openPreview(dialog, trigger);

		expect(status.hidden).toBe(false);
	});

	it('keeps one frame however often it is opened', () => {
		const { trigger, dialog, host } = renderPage();

		openPreview(dialog, trigger);
		openPreview(dialog, trigger);

		expect(host.querySelectorAll('iframe')).toHaveLength(1);
	});

	it('does not read a product name as markup', () => {
		const { trigger, dialog } = renderPage();
		trigger.setAttribute(
			'data-product-name',
			'<img src=x onerror=alert(1)>'
		);

		openPreview(dialog, trigger);

		expect(dialog.querySelector('img')).toBeNull();
		expect(
			dialog.querySelector('.arkid-catalogue-link__admin-dialog-title')
				.textContent
		).toBe('<img src=x onerror=alert(1)>');
	});
});

describe('init', () => {
	it('opens from a click on a button anywhere in the page', () => {
		const { trigger, dialog, host } = renderPage();
		init();

		trigger.click();

		expect(host.querySelector('iframe').src).toBe(URL_OK);
		expect(dialog.open).toBe(true);
	});

	it('follows a button whose address is changed after load, as the meta box does', () => {
		const { trigger, dialog, host } = renderPage();
		init();
		trigger.setAttribute(
			'data-embed-url',
			'https://catalogue.arkid.app/embed/other'
		);

		trigger.click();

		expect(host.querySelector('iframe').src).toBe(
			'https://catalogue.arkid.app/embed/other'
		);
		expect(dialog.open).toBe(true);
	});

	it('ignores a preview button that has no address, as the meta box does with no viewer', () => {
		const { trigger, dialog } = renderPage();
		init();
		trigger.removeAttribute('data-embed-url');

		trigger.click();

		expect(dialog.open).not.toBe(true);
	});

	it('closes from its close buttons, empties the frame and gives focus back', () => {
		const { trigger, dialog, host } = renderPage();
		init();
		trigger.click();

		dialog.querySelector('[data-role="close"]').click();

		expect(dialog.open).toBe(false);
		expect(host.querySelector('iframe')).toBeNull();
		expect(dialog.ownerDocument.activeElement).toBe(trigger);
		expect(trigger.getAttribute('aria-expanded')).toBe('false');
	});

	it('closes from the header button too', () => {
		const { trigger, dialog } = renderPage();
		init();
		trigger.click();

		dialog.querySelector('[data-role="dismiss"]').click();

		expect(dialog.open).toBe(false);
	});

	it('closes on a click on the backdrop, not on the content', () => {
		const { trigger, dialog } = renderPage();
		init();
		trigger.click();

		dialog
			.querySelector('.arkid-catalogue-link__admin-dialog-host')
			.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
		expect(dialog.open).toBe(true);

		dialog.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
		expect(dialog.open).toBe(false);
	});

	/**
	 * The browser closes a modal dialog on Escape and fires `close`. The viewer
	 * has to stop and focus has to go back, however the dialog was closed.
	 */
	it('stops the viewer and returns focus when the browser closes the dialog itself', () => {
		const { trigger, dialog, host } = renderPage();
		init();
		trigger.click();

		dialog.open = false;
		dialog.dispatchEvent(new window.Event('close'));

		expect(host.querySelector('iframe')).toBeNull();
		expect(dialog.ownerDocument.activeElement).toBe(trigger);
	});

	it('does nothing on a page without the dialog', () => {
		document.body.innerHTML = button();

		expect(() => init()).not.toThrow();
		document.querySelector('.arkid-catalogue-link__preview').click();
	});
});
