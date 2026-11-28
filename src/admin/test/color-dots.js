/**
 * Internal dependencies
 */
import { buildColorDots, dotLabel, safeColor } from '../color-dots';

const COPPER = {
	variant_id: 'v1',
	name: 'Copper',
	rgb: '#B55F39',
	enabled: true,
};
const GRAPHITE = {
	variant_id: 'v2',
	name: 'Graphite',
	rgb: '#929498',
	enabled: false,
};

describe('safeColor', () => {
	it.each([
		['#b55f39', '#b55f39'],
		['#B55F39', '#b55f39'],
		['#fa0', '#fa0'],
		['b55f39', ''],
		['red', ''],
		['rgb(0,0,0)', ''],
		['#fff;color:red', ''],
		['#ffff', ''],
		['', ''],
		[null, ''],
		[undefined, ''],
		[123456, ''],
	])('%p -> %p', (given, expected) => {
		expect(safeColor(given)).toBe(expected);
	});
});

describe('dotLabel', () => {
	it('says shown or hidden in the given words', () => {
		const i18n = {
			colorShown: '%s: sichtbar',
			colorHidden: '%s: versteckt',
		};

		expect(dotLabel('Copper', true, i18n)).toBe('Copper: sichtbar');
		expect(dotLabel('Copper', false, i18n)).toBe('Copper: versteckt');
	});

	it('falls back to English', () => {
		expect(dotLabel('Copper', true)).toBe('Copper: shown');
		expect(dotLabel('Copper', false)).toBe('Copper: hidden');
	});

	it('does not read a colour name as a replacement pattern', () => {
		expect(dotLabel('Sand $& Sky', true)).toBe('Sand $& Sky: shown');
	});
});

describe('buildColorDots', () => {
	it('is null for a viewer without colours', () => {
		expect(buildColorDots([])).toBeNull();
		expect(buildColorDots(undefined)).toBeNull();
		expect(buildColorDots(null)).toBeNull();
		expect(buildColorDots('red')).toBeNull();
	});

	it('draws a dot per colour in order, with the state in words', () => {
		const dots = buildColorDots([COPPER, GRAPHITE]);
		const [first, second] = dots.children;

		expect(dots.className).toBe('arkid-catalogue-link__dots');
		expect(dots.children).toHaveLength(2);
		expect(first.getAttribute('aria-label')).toBe('Copper: shown');
		expect(first.title).toBe('Copper: shown');
		expect(first.dataset.state).toBe('shown');
		expect(first.dataset.variantId).toBe('v1');
		expect(first.style.getPropertyValue('--arkid-dot')).toBe('#b55f39');
		expect(first.classList.contains('is-hidden')).toBe(false);
		expect(second.getAttribute('aria-label')).toBe('Graphite: hidden');
		expect(second.dataset.state).toBe('hidden');
		expect(second.classList.contains('is-hidden')).toBe(true);
		expect(first.getAttribute('role')).toBe('img');
	});

	it('treats a colour without the flag as shown, as the server does', () => {
		const dots = buildColorDots([{ name: 'Plain', rgb: '#000000' }]);

		expect(dots.firstChild.dataset.state).toBe('shown');
	});

	it('uses the translated words it is given', () => {
		const dots = buildColorDots([GRAPHITE], {
			colorHidden: '%s: versteckt',
		});

		expect(dots.firstChild.getAttribute('aria-label')).toBe(
			'Graphite: versteckt'
		);
	});

	it('builds with the DOM so that names cannot become markup', () => {
		const dots = buildColorDots([
			{
				variant_id: 'v"><script>x</script>',
				name: '"><img src=x onerror=alert(1)>',
				rgb: 'red;background:url(https://evil.example/x)',
				enabled: true,
			},
		]);

		expect(dots.querySelector('script')).toBeNull();
		expect(dots.querySelector('img')).toBeNull();
		expect(dots.outerHTML).not.toContain('evil.example');
		expect(dots.firstChild.hasAttribute('style')).toBe(false);
		expect(dots.firstChild.getAttribute('aria-label')).toBe(
			'"><img src=x onerror=alert(1)>: shown'
		);
	});

	it('skips an entry that is not a colour', () => {
		expect(buildColorDots([null, 'red', { rgb: '#000' }])).toBeNull();
		expect(buildColorDots([null, COPPER]).children).toHaveLength(1);
	});
});
