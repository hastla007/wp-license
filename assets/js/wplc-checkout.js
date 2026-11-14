/**
 * Classic checkout: the automatic-renewal opt-in is shown only while a payment
 * method that can renew is selected. A hidden box is unchecked, so an opt-in
 * is never submitted with a payment method that cannot honour it.
 */
( function ( $ ) {
	'use strict';

	function sync() {
		$( '.wplc-recurring-consent' ).each( function () {
			var box = $( this );
			var allowed = String( box.data( 'wplcGateways' ) || '' ).split( ' ' ).filter( Boolean );
			var chosen = $( 'input[name="payment_method"]:checked' ).val();
			// With a single payment method WooCommerce renders no radio buttons.
			if ( undefined === chosen ) {
				var only = $( 'input[name="payment_method"]' );
				chosen = 1 === only.length ? only.val() : undefined;
			}
			var supported = undefined === chosen || -1 !== allowed.indexOf( String( chosen ) );
			box.toggle( supported );
			if ( ! supported ) {
				box.find( 'input[type="checkbox"]' ).prop( 'checked', false );
			}
		} );
	}

	$( document.body ).on( 'updated_checkout payment_method_selected', sync );
	$( document.body ).on( 'change', 'input[name="payment_method"]', sync );
	$( sync );
}( jQuery ) );
