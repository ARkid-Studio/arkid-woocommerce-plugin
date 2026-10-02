/**
 * ARkid Catalogue Link — embed block (editor).
 *
 * Server-rendered block: the Edit component picks an embed via REST and
 * stores the embed_id in attributes; the frontend HTML is produced by
 * the PHP `render_callback`.
 */

/**
 * External dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	BlockControls,
	InspectorControls,
} from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
	Notice,
	ToolbarGroup,
	ToolbarButton,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import './editor.scss';
import './style.scss';

function EmbedEdit({ attributes, setAttributes }) {
	const { embedId, embedUrl } = attributes;
	const [embeds, setEmbeds] = useState(null);
	const [loadError, setLoadError] = useState('');

	// The catalogue row for the currently-picked viewer, once the list loads.
	const picked = embeds?.find((row) => row.value === embedId)?.embed;

	// The viewer was re-pointed in ARkid after this block was saved.
	const isStale = Boolean(picked && embedUrl && picked.embed_url !== embedUrl);

	// Blocks saved before 1.0.0 carry only embedId. Repair them in place the
	// first time they are opened in the editor. Declared here, above every early
	// return, because hooks must run in the same order on every render.
	useEffect(() => {
		if (!embedId || !picked || embedUrl) {
			return;
		}
		setAttributes({
			embedUrl: picked.embed_url || '',
			productName: picked.product_name || '',
		});
	}, [embedId, picked, embedUrl, setAttributes]);

	useEffect(() => {
		let cancelled = false;
		apiFetch({ path: 'arkid-catalogue-link/v1/embeds' })
			.then((rows) => {
				if (cancelled) {
					return;
				}
				setEmbeds(Array.isArray(rows) ? rows : []);
			})
			.catch((err) => {
				if (cancelled) {
					return;
				}
				setLoadError(
					err?.message ||
						__('Could not load viewers.', 'arkid-catalogue-link')
				);
				setEmbeds([]);
			});
		return () => {
			cancelled = true;
		};
	}, []);

	const blockProps = useBlockProps();

	if (embeds === null) {
		return (
			<div {...blockProps}>
				<Placeholder
					icon="format-video"
					label={__('ARkid Catalogue Link', 'arkid-catalogue-link')}
				>
					<Spinner />
				</Placeholder>
			</div>
		);
	}

	if (loadError) {
		return (
			<div {...blockProps}>
				<Placeholder
					icon="format-video"
					label={__('ARkid Catalogue Link', 'arkid-catalogue-link')}
				>
					<Notice status="error" isDismissible={false}>
						{loadError}
					</Notice>
				</Placeholder>
			</div>
		);
	}

	const options = [
		{ value: '', label: __('— Select a viewer —', 'arkid-catalogue-link') },
		...embeds.map((row) => ({
			value: row.value,
			label: row.label,
		})),
	];

	// Persist the URL alongside the id so the frontend render never has to call
	// the API. Without this the block would block a storefront page render on a
	// third-party request.
	const applyPick = (value) => {
		const row = embeds.find((r) => r.value === value)?.embed;
		setAttributes({
			embedId: value || '',
			embedUrl: row?.embed_url || '',
			productName: row?.product_name || '',
		});
	};

	const isPicked = embedId && options.some((o) => o.value === embedId);

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={__('Viewer', 'arkid-catalogue-link')}
					initialOpen
				>
					<SelectControl
						label={__('Viewer', 'arkid-catalogue-link')}
						value={embedId || ''}
						options={options}
						onChange={(value) =>
							applyPick(value)
						}
					/>
				</PanelBody>
			</InspectorControls>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						icon="update"
						label={__('Reload viewers', 'arkid-catalogue-link')}
						onClick={() => {
							setEmbeds(null);
							apiFetch({
								path: 'arkid-catalogue-link/v1/embeds',
								cache: 'no-store',
							})
								.then((rows) => setEmbeds(Array.isArray(rows) ? rows : []))
								.catch(() => setEmbeds([]));
						}}
					/>
				</ToolbarGroup>
			</BlockControls>
			<div {...blockProps}>
				{isStale && (
					<Notice
						status="warning"
						isDismissible={false}
						actions={[
							{
								label: __(
									'Update from ARkid',
									'arkid-catalogue-link'
								),
								onClick: () => applyPick(embedId),
							},
						]}
					>
						{__(
							'This viewer has been re-pointed in your ARkid catalogue. The saved link is out of date.',
							'arkid-catalogue-link'
						)}
					</Notice>
				)}
				{isPicked ? (
					<ServerSideRender
						block={metadata.name}
						attributes={attributes}
						EmptyResponsePlaceholder={() => (
							<Placeholder
								icon="format-video"
								label={__(
									'ARkid Catalogue Link',
									'arkid-catalogue-link'
								)}
							>
								{__(
									'Viewer not found.',
									'arkid-catalogue-link'
								)}
							</Placeholder>
						)}
					/>
				) : (
					<Placeholder
						icon="format-video"
						label={__(
							'ARkid Catalogue Link',
							'arkid-catalogue-link'
						)}
						instructions={__(
							'Pick a viewer from your catalogue.',
							'arkid-catalogue-link'
						)}
					>
						<SelectControl
							value={embedId || ''}
							options={options}
							onChange={(value) =>
								applyPick(value)
							}
						/>
						{!embeds.length && (
							<p>
								{__(
									'No viewers available. Check your API key.',
									'arkid-catalogue-link'
								)}
							</p>
						)}
					</Placeholder>
				)}
			</div>
		</>
	);
}

registerBlockType(metadata.name, {
	edit: EmbedEdit,
	save: () => null,
});
