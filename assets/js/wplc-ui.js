/**
 * WP License Captain: copy buttons and confirmation prompts.
 *
 * Markup contract:
 *   <button type="button" data-wplc-copy="ID-OF-INPUT" data-wplc-copied="Copied" data-wplc-copy-failed="Copying failed">Copy</button>
 *   <span role="status" aria-live="polite" data-wplc-copy-status="ID-OF-INPUT"></span>   (optional)
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

	/**
	 * Tell the result to assistive technology. A text change on the focused button is not
	 * announced reliably, so the message goes to a live region: the one next to the button
	 * when the markup has it, otherwise one that is created once per page.
	 */
	function speak( button, message ) {
		var region = document.querySelector( '[data-wplc-copy-status="' + button.getAttribute( 'data-wplc-copy' ) + '"]' );
		if ( ! region ) {
			region = document.getElementById( 'wplc-live-region' );
		}
		if ( ! region ) {
			region = document.createElement( 'span' );
			region.id = 'wplc-live-region';
			region.setAttribute( 'role', 'status' );
			region.setAttribute( 'aria-live', 'polite' );
			region.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;';
			document.body.appendChild( region );
		}
		// Emptied first so that the same message is announced again on a second click.
		region.textContent = '';
		window.setTimeout( function () {
			region.textContent = message;
		}, 50 );
		window.setTimeout( function () {
			if ( region.textContent === message ) {
				region.textContent = '';
			}
		}, 5000 );
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
		speak( button, copied );
		window.setTimeout( function () {
			button.textContent = button.getAttribute( 'data-wplc-label' );
		}, 2000 );
	}

	/**
	 * Copying did not work (no clipboard permission, old browser): say so and
	 * leave the key selected, so it can be copied with the keyboard.
	 */
	function announceFailure( button, field ) {
		var failed = button.getAttribute( 'data-wplc-copy-failed' );
		if ( field && field.select ) {
			field.focus();
			field.select();
		}
		if ( failed ) {
			speak( button, failed );
		}
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
					} else {
						announceFailure( button, field );
					}
				}
			);
			return;
		}

		if ( fallbackCopy( field ) ) {
			announce( button );
		} else {
			announceFailure( button, field );
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
