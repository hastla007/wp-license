/**
 * Editor side of the blocks "My licenses" and "Changelog". Both are rendered on
 * the server; the editor shows a placeholder and, for the changelog, the settings.
 * Labels come from PHP (window.wplcBlocks), so this file has no translatable strings.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor ) {
		return;
	}

	var el = wp.element.createElement;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var components = wp.components || {};
	var s = window.wplcBlocks || {};

	function placeholder( props, title, text ) {
		return el(
			'div',
			useBlockProps( { className: 'wplc-block-placeholder' } ),
			el( 'strong', null, title ),
			el( 'p', null, text )
		);
	}

	wp.blocks.registerBlockType( 'wplc/licenses', {
		apiVersion: 3,
		title: s.licensesTitle,
		description: s.licensesDescription,
		category: 'widgets',
		icon: 'admin-network',
		supports: { html: false, multiple: false },
		edit: function ( props ) {
			return placeholder( props, s.licensesTitle, s.licensesPlaceholder );
		},
		save: function () {
			return null;
		}
	} );

	wp.blocks.registerBlockType( 'wplc/changelog', {
		apiVersion: 3,
		title: s.changelogTitle,
		description: s.changelogDescription,
		category: 'widgets',
		icon: 'list-view',
		supports: { html: false },
		attributes: {
			product: { type: 'string', default: '' },
			limit: { type: 'number', default: 10 }
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var controls = null;
			if ( InspectorControls && components.PanelBody && components.TextControl ) {
				controls = el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: s.changelogTitle },
						el( components.TextControl, {
							label: s.changelogProduct,
							value: attributes.product,
							onChange: function ( value ) {
								props.setAttributes( { product: value } );
							}
						} ),
						el( components.TextControl, {
							label: s.changelogLimit,
							type: 'number',
							min: 1,
							max: 50,
							value: attributes.limit,
							onChange: function ( value ) {
								props.setAttributes( { limit: parseInt( value, 10 ) || 10 } );
							}
						} )
					)
				);
			}
			return el(
				wp.element.Fragment,
				null,
				controls,
				placeholder( props, s.changelogTitle, attributes.product ? attributes.product : s.changelogPlaceholder )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
