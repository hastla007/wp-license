/**
 * WP License Captain: show the entitlement checklist of the product whose key is entered.
 *
 * The group of a key comes only from the catalog (the map in data-wplc-product-groups, product
 * key => prefix); there is no match by substring (PK-01), so "acme-forms-connector" is never
 * offered the entitlements of "acme". With an empty key every group is shown; a key
 * the catalog does not know is offered no group. Groups with ticked boxes always stay visible, so
 * nothing that will be saved is ever hidden. Without this script the server-rendered state stays.
 */
( function () {
	'use strict';

	function groupMap( wrapper ) {
		try {
			var map = JSON.parse( wrapper.getAttribute( 'data-wplc-product-groups' ) || '{}' );
			return map && 'object' === typeof map ? map : {};
		} catch ( e ) {
			return {};
		}
	}

	function refresh( wrapper ) {
		var scope = wrapper.closest( '.options_group, .form-row, .woocommerce_variation' ) || document;
		var input = scope.querySelector( 'input[name^="_wplc_product_key"], input[name^="_wplc_variation_product_key"]' );
		var key = input ? String( input.value ).trim().toLowerCase() : '';
		var map = groupMap( wrapper );
		var own = key && Object.prototype.hasOwnProperty.call( map, key ) ? String( map[ key ] ) : null;
		var groups = wrapper.querySelectorAll( '[data-wplc-entitlement-group]' );
		var none = wrapper.querySelector( '[data-wplc-entitlement-none]' );
		var visible = 0;
		var i;

		for ( i = 0; i < groups.length; i++ ) {
			var isOwn = null !== own && groups[ i ].getAttribute( 'data-wplc-entitlement-group' ) === own;
			var hasChecked = !! groups[ i ].querySelector( 'input[type="checkbox"]:checked' );
			groups[ i ].hidden = '' !== key && ! isOwn && ! hasChecked;
			if ( ! groups[ i ].hidden ) {
				visible++;
			}
		}
		if ( none ) {
			none.hidden = '' === key || visible > 0;
		}
	}

	function refreshAll() {
		var wrappers = document.querySelectorAll( '.wplc-entitlements' );
		for ( var i = 0; i < wrappers.length; i++ ) {
			refresh( wrappers[ i ] );
		}
	}

	document.addEventListener( 'input', function ( event ) {
		if ( event.target && event.target.name && ( 0 === event.target.name.indexOf( '_wplc_product_key' ) || 0 === event.target.name.indexOf( '_wplc_variation_product_key' ) ) ) {
			refreshAll();
		}
	} );

	document.addEventListener( 'DOMContentLoaded', refreshAll );

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'woocommerce_variations_loaded woocommerce_variations_added', refreshAll );
	}
}() );
