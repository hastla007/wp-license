/**
 * PK-01: the product editor offers a product key only the group the catalog maps it to.
 *
 * Runs assets/js/wplc-product-editor.js against a minimal fake DOM (no dependencies):
 *   node --test tests/js/
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const SOURCE = fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', 'wplc-product-editor.js' ), 'utf8' );

// What the server renders for a shop with the three project products (only learnboating imported
// with entitlements unless a test adds more).
const MAP = { 'learnboating-connector': 'learnboating', 'learnboating-form-connector': 'learnboating_form', 'boatingschool-form-connector': 'boatingschool_form' };

function group( name, checked ) {
	const box = { checked: !! checked };
	return {
		hidden: false,
		getAttribute( attr ) {
			return 'data-wplc-entitlement-group' === attr ? name : null;
		},
		querySelector( selector ) {
			return 'input[type="checkbox"]:checked' === selector && box.checked ? box : null;
		},
	};
}

function editor( groups, map ) {
	const listeners = {};
	const input = { name: '_wplc_product_key', value: '' };
	const none = { hidden: true };
	const wrapper = {
		getAttribute( attr ) {
			return 'data-wplc-product-groups' === attr ? JSON.stringify( map || MAP ) : null;
		},
		closest() {
			return scope;
		},
		querySelectorAll( selector ) {
			return '[data-wplc-entitlement-group]' === selector ? groups : [];
		},
		querySelector( selector ) {
			return '[data-wplc-entitlement-none]' === selector ? none : null;
		},
	};
	const scope = {
		querySelector() {
			return input;
		},
	};
	const document = {
		addEventListener( type, callback ) {
			( listeners[ type ] = listeners[ type ] || [] ).push( callback );
		},
		querySelectorAll( selector ) {
			return '.wplc-entitlements' === selector ? [ wrapper ] : [];
		},
	};
	vm.runInNewContext( SOURCE, { window: {}, document } );

	return {
		none,
		type( value ) {
			input.value = value;
			listeners.input.forEach( ( callback ) => callback( { target: input } ) );
		},
		shown: () => groups.filter( ( g ) => ! g.hidden ).map( ( g ) => g.getAttribute( 'data-wplc-entitlement-group' ) ),
	};
}

test( 'a key that contains another prefix is not offered that group (no substring match)', () => {
	const ui = editor( [ group( 'learnboating' ), group( 'voucher' ) ] );
	// Before the catalog file of the form connector is imported: nothing, and the hint is shown.
	ui.type( 'learnboating-form-connector' );
	assert.deepStrictEqual( ui.shown(), [] );
	assert.strictEqual( ui.none.hidden, false );
} );

test( 'each project product key shows only its own group', () => {
	const ui = editor( [ group( 'learnboating' ), group( 'learnboating_form' ), group( 'boatingschool_form' ), group( 'voucher' ) ] );
	ui.type( 'learnboating-form-connector' );
	assert.deepStrictEqual( ui.shown(), [ 'learnboating_form' ] );
	assert.strictEqual( ui.none.hidden, true );
	ui.type( 'LearnBoating-Connector' );
	assert.deepStrictEqual( ui.shown(), [ 'learnboating' ] );
	ui.type( 'boatingschool-form-connector' );
	assert.deepStrictEqual( ui.shown(), [ 'boatingschool_form' ] );
} );

test( 'an unknown key is offered no group; an empty key every group', () => {
	const ui = editor( [ group( 'learnboating' ), group( 'voucher' ) ] );
	ui.type( 'voucher-captain' );
	assert.deepStrictEqual( ui.shown(), [] );
	ui.type( '' );
	assert.deepStrictEqual( ui.shown(), [ 'learnboating', 'voucher' ] );
	assert.strictEqual( ui.none.hidden, true );
} );

test( 'a group with ticked boxes stays visible', () => {
	const ui = editor( [ group( 'learnboating', true ), group( 'voucher' ) ] );
	ui.type( 'learnboating-form-connector' );
	assert.deepStrictEqual( ui.shown(), [ 'learnboating' ] );
	assert.strictEqual( ui.none.hidden, true );
} );
