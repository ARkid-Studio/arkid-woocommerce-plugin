/**
 * The colours of a viewer, as a row of dots, for the pieces of the admin that
 * are built in the browser (the picker's results and the meta box preview).
 *
 * The same markup and classes as PHP's `Admin\ColorDots`, which renders the
 * viewers table: one dot per colour of the product, in display order. A colour
 * the viewer shows is filled; one it hides keeps its colour and is struck
 * through. Each dot says its state in words too, so it never depends on colour.
 */

/**
 * A colour that is safe to use as a CSS value, or ''. Only `#rgb` and `#rrggbb`
 * are accepted, as in PHP, so nothing else can ride into a style.
 *
 * @param {*} rgb The colour as ARkid sends it.
 * @return {string} The colour, lower case, or ''.
 */
export function safeColor(rgb) {
	return typeof rgb === 'string' &&
		/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(rgb)
		? rgb.toLowerCase()
		: '';
}

/**
 * What a dot says about its colour: "Copper: shown" or "Copper: hidden".
 *
 * @param {string}  name  The colour's name.
 * @param {boolean} shown Whether the viewer shows it.
 * @param {Object}  i18n  The translated templates, `%s` for the name.
 * @return {string} The label.
 */
export function dotLabel(name, shown, i18n = {}) {
	const template =
		(shown ? i18n.colorShown : i18n.colorHidden) ||
		(shown ? '%s: shown' : '%s: hidden');
	// A function, not a string: in a replacement string `$&` is a pattern, and
	// colour names are free text.
	return template.replace('%s', () => name);
}

/**
 * The dots of a viewer, or null when its product has no colours.
 *
 * Built with the DOM, not with markup: names come from ARkid.
 *
 * @param {Array}  colors The viewer's `all_colors`.
 * @param {Object} i18n   The translated templates.
 * @return {HTMLElement|null} The row of dots.
 */
export function buildColorDots(colors, i18n = {}) {
	if (!Array.isArray(colors) || !colors.length) {
		return null;
	}

	const wrapper = document.createElement('span');
	wrapper.className = 'arkid-catalogue-link__dots';

	colors.forEach((color) => {
		if (!color || typeof color.name !== 'string') {
			return;
		}
		const shown = color.enabled !== false;
		const label = dotLabel(color.name, shown, i18n);
		const dot = document.createElement('span');
		dot.className = shown
			? 'arkid-catalogue-link__dot'
			: 'arkid-catalogue-link__dot is-hidden';
		dot.setAttribute('role', 'img');
		dot.setAttribute('aria-label', label);
		dot.title = label;
		dot.dataset.state = shown ? 'shown' : 'hidden';
		if (typeof color.variant_id === 'string') {
			dot.dataset.variantId = color.variant_id;
		}
		const css = safeColor(color.rgb);
		if (css) {
			dot.style.setProperty('--arkid-dot', css);
		}
		wrapper.appendChild(dot);
	});

	return wrapper.childElementCount ? wrapper : null;
}
