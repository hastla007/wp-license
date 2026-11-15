/**
 * A-124: the copy button tells success and failure to assistive technology.
 *
 * Runs assets/js/wplc-ui.js against a minimal fake DOM (no dependencies):
 *   node --test tests/js/
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const SOURCE = fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', 'wplc-ui.js' ), 'utf8' );

function element( tag, attributes ) {
	const attrs = Object.assign( {}, attributes || {} );
	return {
		tagName: tag.toUpperCase(),
		id: attrs.id || '',
		textContent: attrs.text || '',
		value: attrs.value || '',
		style: {},
		focused: false,
		selected: false,
		getAttribute( name ) {
			return Object.prototype.hasOwnProperty.call( attrs, name ) ? attrs[ name ] : null;
		},
		setAttribute( name, value ) {
			attrs[ name ] = String( value );
			if ( 'id' === name ) {
				this.id = String( value );
			}
		},
		hasAttribute( name ) {
			return Object.prototype.hasOwnProperty.call( attrs, name );
		},
		closest( selector ) {
			return '[data-wplc-copy]' === selector && this.hasAttribute( 'data-wplc-copy' ) ? this : null;
		},
		focus() {
			this.focused = true;
		},
		select() {
			this.selected = true;
		},
	};
}

/**
 * Build a page with a key field, a copy button and (optionally) its live region.
 */
function page( options ) {
	const listeners = {};
	const nodes = [];
	const timers = [];
	const field = element( 'input', { id: 'key', value: 'WPLC-TEST' } );
	const button = element( 'button', { 'data-wplc-copy': 'key', 'data-wplc-copied': 'Copied', 'data-wplc-copy-failed': 'Copying failed', text: 'Copy' } );
	nodes.push( field, button );
	if ( options.region ) {
		nodes.push( element( 'span', { role: 'status', 'aria-live': 'polite', 'data-wplc-copy-status': 'key' } ) );
	}
	const document = {
		body: {
			appendChild( node ) {
				nodes.push( node );
			},
		},
		addEventListener( type, callback ) {
			( listeners[ type ] = listeners[ type ] || [] ).push( callback );
		},
		getElementById( id ) {
			return nodes.find( ( node ) => node.id === id ) || null;
		},
		querySelector( selector ) {
			const match = /^\[data-wplc-copy-status="([^"]+)"\]$/.exec( selector );
			return match ? nodes.find( ( node ) => node.getAttribute( 'data-wplc-copy-status' ) === match[ 1 ] ) || null : null;
		},
		createElement( tag ) {
			return element( tag );
		},
		execCommand() {
			return options.execCommand;
		},
	};
	const window = {
		navigator: options.clipboard ? { clipboard: { writeText: () => ( options.clipboard === 'ok' ? Promise.resolve() : Promise.reject( new Error( 'denied' ) ) ) } } : {},
		setTimeout( callback ) {
			timers.push( callback );
		},
		confirm: () => true,
	};
	vm.runInNewContext( SOURCE, { window, document } );

	return {
		field,
		button,
		region: () => document.querySelector( '[data-wplc-copy-status="key"]' ) || document.getElementById( 'wplc-live-region' ),
		click() {
			listeners.click.forEach( ( callback ) => callback( { target: button, preventDefault() {} } ) );
		},
		// Runs the timer that writes the message (the first queued one after the click).
		flushFirstTimer() {
			const callback = timers.shift();
			if ( callback ) {
				callback();
			}
		},
	};
}

test( 'success is announced in the live region next to the button', async () => {
	const ui = page( { region: true, clipboard: 'ok' } );
	ui.click();
	await Promise.resolve();
	ui.flushFirstTimer();
	assert.strictEqual( ui.region().textContent, 'Copied' );
	assert.strictEqual( ui.button.textContent, 'Copied' );
} );

test( 'a failure is announced and the key stays selected for the keyboard', async () => {
	const ui = page( { region: true, clipboard: 'denied', execCommand: false } );
	ui.click();
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	ui.flushFirstTimer();
	assert.strictEqual( ui.region().textContent, 'Copying failed' );
	assert.ok( ui.field.selected );
	assert.strictEqual( ui.button.textContent, 'Copy' );
} );

test( 'without a region in the markup a polite live region is created once', () => {
	const ui = page( { region: false, clipboard: null, execCommand: true } );
	ui.click();
	ui.flushFirstTimer();
	const region = ui.region();
	assert.ok( region );
	assert.strictEqual( region.getAttribute( 'role' ), 'status' );
	assert.strictEqual( region.getAttribute( 'aria-live' ), 'polite' );
	assert.strictEqual( region.textContent, 'Copied' );
} );
