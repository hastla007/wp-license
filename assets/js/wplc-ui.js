/**
 * WP License Captain: copy buttons and confirmation prompts.
 *
 * Markup contract:
 *   <button type="button" data-wplc-copy="ID-OF-INPUT" data-wplc-copied="Copied">Copy</button>
 *   <form data-wplc-confirm="Question?"> ... </form>
 *
 * Everything works as a plain form submit / selectable input without this script.
 */
( function () {
	'use strict';

	function fallbackCopy( field ) {
		field.focus();
		field.select();
		if ( field.setSelectionRange ) {
			field.setSelectionRange( 0, field.value.length );
		}
		try {
			return document.execCommand( 'copy' );
		} catch ( e ) {
			return false;
		}
	}

	function announce( button ) {
		var copied = button.getAttribute( 'data-wplc-copied' );
		if ( ! copied ) {
			return;
		}
		if ( ! button.hasAttribute( 'data-wplc-label' ) ) {
			button.setAttribute( 'data-wplc-label', button.textContent );
		}
		button.textContent = copied;
		window.setTimeout( function () {
			button.textContent = button.getAttribute( 'data-wplc-label' );
		}, 2000 );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-wplc-copy]' ) : null;
		if ( ! button ) {
			return;
		}
		var field = document.getElementById( button.getAttribute( 'data-wplc-copy' ) );
		if ( ! field ) {
			return;
		}
		event.preventDefault();

		if ( window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( field.value ).then(
				function () {
					announce( button );
				},
				function () {
					if ( fallbackCopy( field ) ) {
						announce( button );
					}
				}
			);
			return;
		}

		if ( fallbackCopy( field ) ) {
			announce( button );
		}
	} );

	document.addEventListener( 'focusin', function ( event ) {
		var field = event.target;
		if ( field && field.hasAttribute && field.hasAttribute( 'data-wplc-select' ) && field.select ) {
			field.select();
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var question = form && form.getAttribute ? form.getAttribute( 'data-wplc-confirm' ) : null;
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );
}() );
