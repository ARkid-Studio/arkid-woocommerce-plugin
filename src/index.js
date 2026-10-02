/**
 * ARkid Catalogue Link — admin scripts.
 *
 *  - Reveal/Hide toggle for the API key field on the integration settings page.
 *  - jQuery + Select2 ajax picker for the product edit meta box.
 *  - "Refresh" link on the Viewers list (vanilla — just a page reload).
 */

/**
 * Internal dependencies
 */
import './index.scss';

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
					select.dispatchEvent(new Event('change', { bubbles: true }));
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
			toggleRemoveButton($select, true);
		});

		// Catches every clear path: Select2's allowClear `x`, the Remove
		// button (programmatic `val('').trigger('change')`), and any
		// future call site.
		$select.on('change', () => {
			const value = $select.val();
			if (value === '' || value === null) {
				updatePreview($select, '');
				toggleRemoveButton($select, false);
			}
		});
	});
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

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', () => {
		bindRevealToggles();
		bindEmbedPicker();
		bindRemoveViewerButtons();
	});
} else {
	bindRevealToggles();
	bindEmbedPicker();
	bindRemoveViewerButtons();
}
