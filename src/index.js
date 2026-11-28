/**
 * ARkid Catalogue Link — admin scripts.
 *
 *  - Reveal/Hide toggle for the API key field on the integration settings page.
 *  - jQuery + Select2 ajax picker for the product edit meta box.
 *  - "Refresh" link on the Viewers list (vanilla — just a page reload).
 *  - Colour dots in the picker's results and under the selected viewer.
 *  - The viewer preview dialog of the Viewers list and the product meta box.
 */

/**
 * Internal dependencies
 */
import './index.scss';
import { buildColorDots } from './admin/color-dots';
import { init as initPreviewDialog } from './admin/preview-dialog';

const config = window.arkidCatalogueLinkAdmin || {};

function bindRevealToggles() {
	document
		.querySelectorAll('.arkid-catalogue-link__reveal')
		.forEach((button) => {
			button.addEventListener('click', () => {
				const targetId = button.dataset.target;
				if (!targetId) {
					return;
				}
				const input = document.getElementById(targetId);
				if (!input) {
					return;
				}
				const next = input.type === 'password' ? 'text' : 'password';
				input.type = next;
				button.setAttribute(
					'aria-pressed',
					next === 'text' ? 'true' : 'false'
				);
				button.textContent =
					next === 'text'
						? config.i18n?.hide || 'Hide'
						: config.i18n?.reveal || 'Reveal';
			});
		});
}

function bindRemoveViewerButtons() {
	const $ = window.jQuery;
	document
		.querySelectorAll('.arkid-catalogue-link__remove-viewer')
		.forEach((button) => {
			button.addEventListener('click', () => {
				const targetId = button.dataset.target;
				if (!targetId) {
					return;
				}
				const select = document.getElementById(targetId);
				if (!select) {
					return;
				}
				// Trigger change — the picker's own change handler will
				// hide the Remove button and the image preview.
				if ($ && $.fn && $.fn.selectWoo) {
					$(select).val('').trigger('change');
				} else {
					select.value = '';
					select.dispatchEvent(
						new Event('change', { bubbles: true })
					);
				}
			});
		});
}

/* eslint-disable @typescript-eslint/no-use-before-define -- Function declarations are hoisted; call order reflects flow. */

function bindEmbedPicker() {
	const $ = window.jQuery;
	if (!$ || !$.fn || !$.fn.selectWoo) {
		return;
	}

	$('.arkid-catalogue-link__embed-picker').each(function () {
		const $select = $(this);
		const restUrl = $select.data('rest-url') || config.restUrl;
		const nonce = $select.data('rest-nonce') || config.nonce;
		if (!restUrl) {
			return;
		}

		$select.selectWoo({
			placeholder:
				$select.data('placeholder') ||
				config.i18n?.searchPlaceholder ||
				'Search viewers…',
			allowClear: true,
			minimumInputLength: 0,
			width: '100%',
			ajax: {
				delay: 200,
				url: restUrl,
				dataType: 'json',
				headers: { 'X-WP-Nonce': nonce },
				data(params) {
					return { search: params.term || '' };
				},
				processResults(data) {
					return {
						results: (data || []).map((row) => ({
							id: row.value,
							text: row.label,
							embed: row.embed,
						})),
					};
				},
			},
			templateResult: (result) => resultTemplate(result),
			language: {
				noResults: () => config.i18n?.noResults || 'No viewers found.',
				searching: () => config.i18n?.searching || 'Searching…',
				errorLoading: () =>
					config.i18n?.loadError || 'Could not load viewers.',
			},
		});

		// Live preview update on Select2 pick — uses the embed payload
		// attached to the ajax result.
		$select.on('select2:select', (event) => {
			const embed = event.params?.data?.embed;
			updatePreview($select, embed?.image || '');
			updateColors($select, embed?.all_colors);
			updatePreviewButton($select, embed);
			toggleRemoveButton($select, true);
		});

		// Catches every clear path: Select2's allowClear `x`, the Remove
		// button (programmatic `val('').trigger('change')`), and any
		// future call site.
		$select.on('change', () => {
			syncLinkNotice($select);
			const value = $select.val();
			if (value === '' || value === null) {
				updatePreview($select, '');
				updateColors($select, null);
				updatePreviewButton($select, null);
				toggleRemoveButton($select, false);
			}
		});
	});
}

/**
 * The notice above the picker describes the viewer that is saved. Once the
 * selection differs from it the notice is about something else, so hide it;
 * it comes back if the merchant goes back to the saved viewer.
 *
 * @param {Object} $select The picker, as a jQuery object.
 */
function syncLinkNotice($select) {
	const notice = $select
		.closest('.arkid-catalogue-link__metabox')
		.find('.arkid-catalogue-link__link-notice')[0];
	if (!notice) {
		return;
	}
	const saved = $select.attr('data-saved-id') || '';
	notice.hidden = String($select.val() || '') !== saved;
}

/**
 * One row of the picker's results: the viewer's label and its colour dots.
 * Built with the DOM, because the label and the names come from ARkid.
 *
 * @param {Object} result The Select2 result; `embed` is the REST payload.
 * @return {string|HTMLElement} What to show for the result.
 */
function resultTemplate(result) {
	const dots = buildColorDots(result.embed?.all_colors, config.i18n);
	if (result.loading || !dots) {
		return result.text;
	}

	const row = document.createElement('span');
	row.className = 'arkid-catalogue-link__result';
	const label = document.createElement('span');
	label.textContent = result.text;
	row.append(label, dots);

	return row;
}

/**
 * Show the colours of the selected viewer under its image.
 *
 * @param {Object}     $select The picker, as a jQuery object.
 * @param {Array|null} colors  The viewer's `all_colors`.
 */
function updateColors($select, colors) {
	const wrapper = $select
		.closest('.arkid-catalogue-link__metabox')
		.find('.arkid-catalogue-link__embed-colors')[0];
	if (!wrapper) {
		return;
	}
	const dots = buildColorDots(colors, config.i18n);
	wrapper.replaceChildren(...(dots ? [dots] : []));
	wrapper.hidden = !dots;
}

/**
 * Point the Preview button at the selected viewer, or hide it.
 *
 * The address is not trusted here: the dialog checks it against the allowed
 * hosts before it loads anything.
 *
 * @param {Object}      $select The picker, as a jQuery object.
 * @param {Object|null} embed   The viewer's REST payload.
 */
function updatePreviewButton($select, embed) {
	const button = $select
		.closest('.arkid-catalogue-link__metabox')
		.find('.arkid-catalogue-link__preview')[0];
	if (!button) {
		return;
	}
	if (!embed || !embed.embed_url) {
		['embed-url', 'product-name', 'edit-url', 'analytics-url'].forEach(
			(name) => button.removeAttribute(`data-${name}`)
		);
		button.hidden = true;
		return;
	}
	button.setAttribute('data-embed-url', embed.embed_url);
	button.setAttribute(
		'data-product-name',
		`${embed.brand_name || ''} ${embed.product_name || ''}`.trim()
	);
	button.setAttribute('data-edit-url', embed.edit_url || '');
	button.setAttribute('data-analytics-url', embed.analytics_url || '');
	button.hidden = false;
}

function updatePreview($select, imageUrl) {
	const wrapper = $select
		.closest('.arkid-catalogue-link__metabox')
		.find('.arkid-catalogue-link__embed-preview')[0];
	if (!wrapper) {
		return;
	}
	const img = wrapper.querySelector('img');
	if (!img) {
		return;
	}
	if (imageUrl) {
		img.src = imageUrl;
		wrapper.hidden = false;
	} else {
		img.removeAttribute('src');
		wrapper.hidden = true;
	}
}

function toggleRemoveButton($select, visible) {
	const button = $select
		.closest('.arkid-catalogue-link__metabox')
		.find('.arkid-catalogue-link__remove-viewer')[0];
	if (button) {
		button.hidden = !visible;
	}
}

function init() {
	bindRevealToggles();
	bindEmbedPicker();
	bindRemoveViewerButtons();
	initPreviewDialog();
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
