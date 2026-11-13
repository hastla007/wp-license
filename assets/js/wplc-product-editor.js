/**
 * WP License Captain: show the entitlement checklist of the product whose key is entered.
 *
 * Groups stay visible when they contain ticked boxes or when no group matches,
 * so nothing that will be saved is ever hidden. Without this script all groups are shown.
 */
( function () {
	'use strict';

	function refresh( wrapper ) {
		var scope = wrapper.closest( '.options_group, .form-row, .woocommerce_variation' ) || document;
		var input = scope.querySelector( 'input[name^="_wplc_product_key"]' );
		var key = input ? String( input.value ).toLowerCase() : '';
		var groups = wrapper.querySelectorAll( '[data-wplc-entitlement-group]' );
		var matched = false;
		var i;

		for ( i = 0; i < groups.length; i++ ) {
			if ( key && key.indexOf( groups[ i ].getAttribute( 'data-wplc-entitlement-group' ) ) !== -1 ) {
				matched = true;
			}
		}
		for ( i = 0; i < groups.length; i++ ) {
			var isMatch = key.indexOf( groups[ i ].getAttribute( 'data-wplc-entitlement-group' ) ) !== -1;
			var hasChecked = !! groups[ i ].querySelector( 'input[type="checkbox"]:checked' );
			groups[ i ].hidden = matched && ! isMatch && ! hasChecked;
		}
	}

	function refreshAll() {
		var wrappers = document.querySelectorAll( '.wplc-entitlements' );
		for ( var i = 0; i < wrappers.length; i++ ) {
			refresh( wrappers[ i ] );
		}
	}

	document.addEventListener( 'input', function ( event ) {
		if ( event.target && event.target.name && 0 === event.target.name.indexOf( '_wplc_product_key' ) ) {
			refreshAll();
		}
	} );

	document.addEventListener( 'DOMContentLoaded', refreshAll );

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'woocommerce_variations_loaded woocommerce_variations_added', refreshAll );
	}
}() );
