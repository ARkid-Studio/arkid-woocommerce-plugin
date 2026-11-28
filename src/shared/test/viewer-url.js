/**
 * Internal dependencies
 */
import { isAllowedEmbedUrl, isHttpsUrl } from '../viewer-url';

const HOSTS = ['catalogue.arkid.app'];

describe('isHttpsUrl', () => {
	it.each([
		['https://catalogue.arkid.app/configure/edit/abc', true],
		['http://catalogue.arkid.app/x', false],
		['javascript:alert(1)', false],
		['data:text/html,<script>alert(1)</script>', false],
		['//catalogue.arkid.app/x', false],
		['/relative', false],
		['', false],
		[null, false],
		[undefined, false],
		[{ toString: () => 'https://catalogue.arkid.app/' }, false],
	])('%p -> %p', (url, expected) => {
		expect(isHttpsUrl(url)).toBe(expected);
	});
});

describe('isAllowedEmbedUrl', () => {
	it.each([
		['https://catalogue.arkid.app/embed/abc?source=woocommerce', true],
		['https://CATALOGUE.arkid.app/embed/abc', true],
		['https://eu.catalogue.arkid.app/embed/abc', true],
		['https://catalogue.arkid.app.evil.example/embed/abc', false],
		['https://evilcatalogue.arkid.app/embed/abc', false],
		['https://evil.example/?u=catalogue.arkid.app', false],
		['https://catalogue.arkid.app@evil.example/embed/abc', false],
		['http://catalogue.arkid.app/embed/abc', false],
		['javascript:alert(1)', false],
		['not a url', false],
		[42, false],
	])('%p -> %p', (url, expected) => {
		expect(isAllowedEmbedUrl(url, HOSTS)).toBe(expected);
	});

	it('fails closed without a list of hosts', () => {
		const url = 'https://catalogue.arkid.app/embed/abc';

		expect(isAllowedEmbedUrl(url, [])).toBe(false);
		expect(isAllowedEmbedUrl(url, undefined)).toBe(false);
		expect(isAllowedEmbedUrl(url, 'catalogue.arkid.app')).toBe(false);
	});

	it('ignores entries of the list that are not host names', () => {
		const url = 'https://catalogue.arkid.app/embed/abc';

		expect(isAllowedEmbedUrl(url, [null, '', 42])).toBe(false);
		expect(isAllowedEmbedUrl(url, [null, '', 'catalogue.arkid.app'])).toBe(
			true
		);
	});

	it('takes the list it is given, not a global', () => {
		window.arkidCatalogueLinkStorefront = { allowedHosts: HOSTS };

		expect(
			isAllowedEmbedUrl('https://viewer.example.com/e/1', [
				'viewer.example.com',
			])
		).toBe(true);
		expect(isAllowedEmbedUrl('https://viewer.example.com/e/1', HOSTS)).toBe(
			false
		);

		delete window.arkidCatalogueLinkStorefront;
	});
});
