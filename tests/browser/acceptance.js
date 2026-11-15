/**
 * Browser acceptance pass (automated part of T-130) against the E2E shop.
 *
 * Started by tests/browser/run-browser.sh, which prepares the site, the PHP server and
 * fixtures.json. Opens https://shop.test in headless Chromium through a local TLS proxy
 * (throw-away self-signed certificate, `--host-resolver-rules`), logs in as the E2E admin
 * and a customer and checks every plugin screen at 1280 px and 375 px, plus the admin
 * screens and the account once right-to-left.
 *
 * Per page: no PHP error output in the HTML, no JavaScript error from plugin assets, no
 * failed request for a plugin asset, and at 375 px no plugin-owned element wider than the
 * viewport (unless it sits in a container that scrolls by itself). Specific checks: A-118
 * (classic checkout keeps the opt-ins ticked across `update_checkout`), A-124 (copy button
 * announces success and failure in a live region), A-125 (entitlement checklist geometry in
 * the product editor and in a variation, LTR and RTL, wide and narrow), A-126 (detail and
 * settings tables scroll inside themselves).
 *
 * Output: one PASS/FAIL/NOTE line per check, screenshots and report.json in WPLC_BROWSER_OUT.
 * Exit code 1 when a check failed.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const http = require( 'http' );
const https = require( 'https' );
const os = require( 'os' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( 'playwright' );

const OUT = process.env.WPLC_BROWSER_OUT || path.join( os.tmpdir(), 'wplc-browser' );
const PORT = Number( process.env.WPLC_E2E_PORT || 8765 );
const PROXY_PORT = Number( process.env.WPLC_BROWSER_PROXY_PORT || 8766 );
const FIX = JSON.parse( fs.readFileSync( process.env.WPLC_BROWSER_FIXTURES, 'utf8' ) );
const ORIGIN = 'https://shop.test';

const results = [];
function record( status, label, detail ) {
	results.push( { status, label, detail: detail === undefined ? null : detail } );
	const suffix = detail === undefined || detail === null || detail === '' ? '' : '\n        ' + ( typeof detail === 'string' ? detail : JSON.stringify( detail ) );
	console.log( ( status + '  ' ).slice( 0, 6 ) + label + suffix );
}
const pass = ( label, detail ) => record( 'PASS', label, detail );
const fail = ( label, detail ) => record( 'FAIL', label, detail );
const note = ( label, detail ) => record( 'NOTE', label, detail );
const check = ( ok, label, detail ) => ( ok ? pass( label ) : fail( label, detail ) );

// ---------------------------------------------------------------------------
// TLS proxy: https://shop.test (mapped to 127.0.0.1:PROXY_PORT) -> PHP server on PORT.
// ---------------------------------------------------------------------------
function startProxy() {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'wplc-browser-tls-' ) );
	execFileSync( 'openssl', [ 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=shop.test', '-keyout', path.join( dir, 'key.pem' ), '-out', path.join( dir, 'cert.pem' ) ], { stdio: 'ignore' } );
	const server = https.createServer( { key: fs.readFileSync( path.join( dir, 'key.pem' ) ), cert: fs.readFileSync( path.join( dir, 'cert.pem' ) ) }, ( req, res ) => {
		const upstream = http.request( { host: '127.0.0.1', port: PORT, method: req.method, path: req.url, headers: req.headers }, ( up ) => {
			res.writeHead( up.statusCode, up.headers );
			up.pipe( res );
		} );
		upstream.on( 'error', ( e ) => {
			res.writeHead( 502 );
			res.end( String( e ) );
		} );
		req.pipe( upstream );
	} );
	return new Promise( ( resolve ) => server.listen( PROXY_PORT, '127.0.0.1', () => resolve( { server, dir } ) ) );
}

// ---------------------------------------------------------------------------
// Page helpers.
// ---------------------------------------------------------------------------
const PLUGIN_ASSET = /\/plugins\/wp-license\//;
const PHP_ERROR = /(<b>(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:|There has been a critical error|Fatal error: Uncaught)/;

/** Attach listeners that collect plugin-related console errors and failed plugin requests. */
function watch( page ) {
	const issues = { js: [], requests: [], foreign: [] };
	page.on( 'console', ( msg ) => {
		if ( 'error' !== msg.type() ) {
			return;
		}
		const where = ( msg.location() && msg.location().url ) || '';
		const text = msg.text();
		if ( PLUGIN_ASSET.test( where ) || /wplc/i.test( text ) ) {
			issues.js.push( text + ( where ? ' @ ' + where : '' ) );
		} else {
			issues.foreign.push( text.slice( 0, 160 ) );
		}
	} );
	page.on( 'pageerror', ( err ) => {
		const stack = String( err.stack || err );
		if ( PLUGIN_ASSET.test( stack ) || /wplc/i.test( stack ) ) {
			issues.js.push( stack.split( '\n' ).slice( 0, 3 ).join( ' | ' ) );
		} else {
			issues.foreign.push( 'pageerror: ' + String( err.message ).slice( 0, 160 ) );
		}
	} );
	page.on( 'response', ( res ) => {
		if ( res.status() >= 400 && PLUGIN_ASSET.test( res.url() ) ) {
			issues.requests.push( res.status() + ' ' + res.url() );
		}
	} );
	page.on( 'dialog', ( d ) => d.dismiss().catch( () => {} ) );
	return issues;
}

/**
 * Elements below `rootSelector` that stick out of the viewport and are not inside a
 * container that clips or scrolls within the viewport.
 */
async function overflow( page, rootSelector ) {
	return page.evaluate( ( sel ) => {
		const vw = document.documentElement.clientWidth;
		const describe = ( el ) => el.tagName.toLowerCase() + ( el.id ? '#' + el.id : '' ) + ( el.className && 'string' === typeof el.className ? '.' + el.className.trim().split( /\s+/ ).slice( 0, 3 ).join( '.' ) : '' );
		// An absolutely positioned element is only clipped by overflow containers at or above its
		// containing block (e.g. screen-reader labels inside a scrolling table escape it when the
		// table is not positioned, and widen the page).
		const clipped = ( el ) => {
			let needPositioned = 'absolute' === getComputedStyle( el ).position;
			for ( let a = el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement ) {
				const style = getComputedStyle( a );
				const positionedHere = 'static' !== style.position || 'none' !== style.transform;
				if ( needPositioned && ! positionedHere ) {
					continue;
				}
				needPositioned = needPositioned && 'absolute' === style.position;
				if ( /(auto|scroll|hidden|clip)/.test( style.overflowX ) ) {
					const r = a.getBoundingClientRect();
					if ( r.right <= vw + 1 && r.left >= -1 ) {
						return true;
					}
				}
			}
			return false;
		};
		const offenders = [];
		const offending = new Set();
		for ( const root of document.querySelectorAll( sel ) ) {
			for ( const el of [ root, ...root.querySelectorAll( '*' ) ] ) {
				const r = el.getBoundingClientRect();
				if ( 0 === r.width || 0 === r.height ) {
					continue; // Not rendered. Screen-reader-only text (1x1 px) still counts: it widens the page too.
				}
				const cs = getComputedStyle( el );
				if ( 'hidden' === cs.visibility || 'fixed' === cs.position ) {
					continue;
				}
				// Moved far off-screen on purpose (WordPress row actions: left -9999em until hovered).
				if ( r.right < -1000 || r.left > vw + 1000 ) {
					continue;
				}
				if ( ( r.right > vw + 1 || r.left < -1 ) && ! clipped( el ) ) {
					offending.add( el );
					offenders.push( { el, text: describe( el ) + ' [' + Math.round( r.left ) + '..' + Math.round( r.right ) + ' of ' + vw + ']' } );
				}
			}
		}
		// The innermost offenders are the cause; their ancestors only grow with them.
		const leaves = offenders.filter( ( o ) => ! [ ...o.el.querySelectorAll( '*' ) ].some( ( d ) => offending.has( d ) ) );
		return { vw, scrollWidth: document.documentElement.scrollWidth, innermost: leaves.sort( ( a, b ) => ( b.el.scrollWidth - b.el.clientWidth ) - ( a.el.scrollWidth - a.el.clientWidth ) ).slice( 0, 6 ).map( ( o ) => o.text + ' ' + o.el.outerHTML.slice( 0, 120 ) ), offenders: offenders.slice( 0, 4 ).map( ( o ) => o.text ), count: offenders.length, roots: document.querySelectorAll( sel ).length };
	}, rootSelector );
}

let shotCounter = 0;
async function shot( page, name ) {
	shotCounter++;
	const file = path.join( OUT, String( shotCounter ).padStart( 2, '0' ) + '-' + name.replace( /[^a-z0-9-]+/gi, '-' ).toLowerCase() + '.png' );
	await page.screenshot( { path: file, fullPage: true } ).catch( () => {} );
	return file;
}

/**
 * Visit one screen and run the generic checks.
 *
 * @param {object} opts { page, label, url, root, ready (selector), width }
 */
let FRONT_SHIM = null;
async function visit( page, issues, opts ) {
	issues.js.length = 0;
	issues.requests.length = 0;
	const response = await page.goto( opts.url, { waitUntil: 'load' } );
	if ( opts.ready ) {
		await page.waitForSelector( opts.ready, { timeout: 10000 } ).catch( () => {} );
	}
	if ( FRONT_SHIM && ! opts.url.includes( '/wp-admin/' ) ) {
		await page.addStyleTag( { content: FRONT_SHIM } );
	}
	if ( opts.after ) {
		await opts.after( page );
	}
	await page.waitForTimeout( 300 );
	const label = opts.label + ' @' + opts.width + ( opts.rtl ? ' rtl' : '' );
	const html = await page.content();
	const status = response ? response.status() : 0;
	check( status > 0 && status < 400, label + ': HTTP ' + status, opts.url );
	const phpError = html.match( PHP_ERROR );
	check( ! phpError, label + ': no PHP error output', phpError ? html.slice( Math.max( 0, phpError.index - 100 ), phpError.index + 300 ) : null );
	if ( opts.ready ) {
		check( ( await page.$( opts.ready ) ) !== null, label + ': plugin markup present (' + opts.ready + ')' );
	}
	if ( opts.css ) {
		check( ( await page.$( opts.css ) ) !== null, label + ': plugin stylesheet loaded (' + opts.css + ')' );
	}
	check( 0 === issues.js.length, label + ': no JavaScript errors from plugin assets', issues.js );
	check( 0 === issues.requests.length, label + ': plugin assets load', issues.requests );
	if ( opts.rtl ) {
		check( 'rtl' === ( await page.getAttribute( 'html', 'dir' ) ), label + ': <html dir="rtl">' );
	}
	if ( opts.root && opts.width <= 480 ) {
		const o = await overflow( page, opts.root );
		check( 0 === o.count, label + ': no plugin element wider than the viewport (' + opts.root + ')', o );
		if ( o.scrollWidth > o.vw && 0 === o.count ) {
			const whole = await overflow( page, 'body' );
			note( label + ': the page scrolls sideways (' + o.scrollWidth + ' > ' + o.vw + '), not caused by plugin markup', whole.innermost );
		}
	}
	await shot( page, label );
}

async function login( context, user, password ) {
	const page = await context.newPage();
	await page.goto( ORIGIN + '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', password );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	const ok = ( await context.cookies( ORIGIN ) ).some( ( c ) => c.name.startsWith( 'wordpress_logged_in_' ) );
	check( ok, 'login as ' + user );
	await page.close();
	return ok;
}

// ---------------------------------------------------------------------------
// Specific checks.
// ---------------------------------------------------------------------------

/** A-118: the opt-ins survive a checkout refresh triggered by a country change. */
async function classicCheckout( page, issues, width ) {
	const label = 'classic checkout @' + width;
	await page.goto( ORIGIN + '/?add-to-cart=' + FIX.simple_id, { waitUntil: 'load' } );
	await visit( page, issues, { label: 'classic checkout (loaded)', url: FIX.classic_url, ready: '.wplc-withdrawal-consent', root: '.woocommerce-checkout-review-order, .wplc-withdrawal-consent, .wplc-recurring-consent', width } );
	const hasCheckoutJs = await page.evaluate( () => 'undefined' !== typeof window.wc_checkout_params && !! window.jQuery );
	if ( ! hasCheckoutJs ) {
		fail( label + ': WooCommerce checkout script is not loaded, update_checkout cannot run' );
		return;
	}
	// Wait for the initial update_order_review that WooCommerce fires on load.
	await page.waitForFunction( () => ! document.querySelector( '.blockUI' ), null, { timeout: 15000 } ).catch( () => {} );
	await page.waitForTimeout( 800 );

	const fake = await page.$( '#payment_method_wplc_e2e_fake' );
	if ( fake ) {
		await page.evaluate( () => {
			const r = document.querySelector( '#payment_method_wplc_e2e_fake' );
			r.checked = true;
			window.jQuery( r ).trigger( 'click' ).trigger( 'change' );
		} );
	}
	const recurring = await page.$( 'input[name="wplc_recurring_consent"]' );
	check( !! recurring && ( await recurring.isVisible() ), label + ': automatic-renewal opt-in visible with a renewal-capable payment method' );
	const withdrawal = await page.$( 'input[name="wplc_withdrawal_consent"]' );
	check( !! withdrawal, label + ': withdrawal consent box present' );
	if ( ! recurring || ! withdrawal ) {
		return;
	}
	check( ! ( await recurring.isChecked() ) && ! ( await withdrawal.isChecked() ), label + ': both boxes start unticked' );
	await recurring.check();
	await withdrawal.check();
	await page.evaluate( () => {
		document.querySelectorAll( 'input[name="wplc_recurring_consent"], input[name="wplc_withdrawal_consent"]' ).forEach( ( el ) => {
			el.dataset.wplcBeforeRefresh = '1';
		} );
	} );

	for ( const country of [ 'AT', 'DE' ] ) {
		const refreshed = page.waitForResponse( ( r ) => r.url().includes( 'wc-ajax=update_order_review' ), { timeout: 15000 } ).catch( () => null );
		await page.evaluate( ( c ) => window.jQuery( '#billing_country' ).val( c ).trigger( 'change' ), country );
		const res = await refreshed;
		check( !! res && 200 === res.status(), label + ': country ' + country + ' triggers update_order_review' );
		await page.waitForFunction( () => ! document.querySelector( '.blockUI' ), null, { timeout: 15000 } ).catch( () => {} );
		await page.waitForTimeout( 300 );
		const state = await page.evaluate( () => {
			const r = document.querySelector( 'input[name="wplc_recurring_consent"]' );
			const w = document.querySelector( 'input[name="wplc_withdrawal_consent"]' );
			return {
				replaced: !! r && ! r.dataset.wplcBeforeRefresh && !! w && ! w.dataset.wplcBeforeRefresh,
				recurring: !! r && r.checked,
				recurringVisible: !! r && null !== r.offsetParent,
				withdrawal: !! w && w.checked,
			};
		} );
		// WooCommerce only swaps the payment section when its HTML changed; the first refresh after
		// ticking always changes it (unticked -> ticked), so that one proves the boxes were re-rendered.
		if ( 'AT' === country ) {
			check( state.replaced, label + ': the payment section was re-rendered by WooCommerce (country ' + country + ')', state );
		} else if ( ! state.replaced ) {
			note( label + ': WooCommerce kept the unchanged payment section (country ' + country + ')' );
		}
		check( state.recurring && state.recurringVisible, 'A-118 ' + label + ': renewal opt-in still ticked after update_checkout (country ' + country + ')', state );
		check( state.withdrawal, 'A-118 ' + label + ': withdrawal consent still ticked after update_checkout (country ' + country + ')', state );
		await page.evaluate( () => document.querySelectorAll( 'input[name="wplc_recurring_consent"], input[name="wplc_withdrawal_consent"]' ).forEach( ( el ) => {
			el.dataset.wplcBeforeRefresh = '1';
		} ) );
	}

	// Bank transfer cannot renew: the opt-in hides and unticks itself (wplc-checkout.js).
	if ( await page.$( '#payment_method_bacs' ) ) {
		await page.evaluate( () => {
			const r = document.querySelector( '#payment_method_bacs' );
			r.checked = true;
			window.jQuery( r ).trigger( 'change' );
		} );
		await page.waitForTimeout( 300 );
		const hidden = await page.evaluate( () => {
			const r = document.querySelector( 'input[name="wplc_recurring_consent"]' );
			return ! r || ( null === r.offsetParent && ! r.checked );
		} );
		check( hidden, label + ': renewal opt-in hidden and unticked for bank transfer' );
		await page.evaluate( () => {
			const r = document.querySelector( '#payment_method_wplc_e2e_fake' );
			r.checked = true;
			window.jQuery( r ).trigger( 'change' );
		} );
		await page.waitForTimeout( 300 );
		await page.check( 'input[name="wplc_recurring_consent"]' );
	}
	await shot( page, label + ' after refresh' );

	if ( width >= 1280 ) {
		// The real form POST (not covered by the server-side E2E flows).
		await page.fill( '#billing_phone', '030123456' ).catch( () => {} );
		const done = page.waitForURL( /order-received/, { timeout: 30000 } ).then( () => true ).catch( () => false );
		await page.click( '#place_order' );
		const ok = await done;
		const errors = ok ? '' : await page.locator( '.woocommerce-error, .woocommerce-NoticeGroup' ).allInnerTexts().catch( () => [] );
		check( ok, label + ': order placed through the classic checkout form with both consents', errors );
		if ( ok ) {
			const text = await page.locator( 'body' ).innerText();
			check( /automatic/i.test( text ) || /renew/i.test( text ), label + ': thank-you page mentions the automatic renewal' );
			await shot( page, 'classic checkout order received' );
		}
	}
}

/** A-124: the copy button speaks through a live region, on success and on failure. */
async function copyButton( page, width ) {
	const label = 'A-124 copy button @' + width;
	const button = await page.$( '[data-wplc-copy]' );
	if ( ! button ) {
		fail( label + ': no copy button on the licenses page' );
		return;
	}
	const target = await button.getAttribute( 'data-wplc-copy' );
	const copied = await button.getAttribute( 'data-wplc-copied' );
	const failed = await button.getAttribute( 'data-wplc-copy-failed' );
	const regionSel = '[data-wplc-copy-status="' + target + '"]';
	const region = await page.$( regionSel );
	const live = region ? await region.evaluate( ( el ) => ( { role: el.getAttribute( 'role' ), live: el.getAttribute( 'aria-live' ) } ) ) : null;
	check( !! live && 'status' === live.role && 'polite' === live.live, label + ': live region (role=status, aria-live=polite) next to the button', live );

	await button.click();
	await page.waitForFunction( ( [ sel, text ] ) => {
		const el = document.querySelector( sel ) || document.getElementById( 'wplc-live-region' );
		return el && el.textContent === text;
	}, [ regionSel, copied ], { timeout: 3000 } ).then( () => pass( label + ': success announced ("' + copied + '")' ) ).catch( () => fail( label + ': success not announced' ) );
	const clip = await page.evaluate( () => navigator.clipboard.readText().catch( () => null ) );
	const value = await page.inputValue( '#' + target );
	check( clip === value, label + ': clipboard holds the license key', { clip, value } );

	// Failure: no clipboard API result and execCommand refuses.
	await page.evaluate( () => {
		navigator.clipboard.writeText = () => Promise.reject( new Error( 'denied' ) );
		document.execCommand = () => false;
	} );
	await page.waitForTimeout( 2100 );
	await button.click();
	await page.waitForFunction( ( [ sel, text ] ) => {
		const el = document.querySelector( sel ) || document.getElementById( 'wplc-live-region' );
		return el && el.textContent === text;
	}, [ regionSel, failed ], { timeout: 3000 } ).then( () => pass( label + ': failure announced ("' + failed + '")' ) ).catch( () => fail( label + ': failure not announced' ) );
	const selected = await page.evaluate( ( id ) => {
		const f = document.getElementById( id );
		return document.activeElement === f && f.selectionEnd - f.selectionStart === f.value.length;
	}, target );
	check( selected, label + ': after a failure the key is focused and selected for manual copying' );
}

/** A-125: geometry of the entitlement checklist. */
async function entitlementGeometry( page, scopeSel, label, opts ) {
	const g = await page.evaluate( ( sel ) => {
		const fs = document.querySelector( sel + ' .wplc-entitlements' );
		if ( ! fs || null === fs.offsetParent ) {
			return null;
		}
		const box = ( el ) => {
			const r = el.getBoundingClientRect();
			return { l: Math.round( r.left ), r: Math.round( r.right ), t: Math.round( r.top ), b: Math.round( r.bottom ), w: Math.round( r.width ) };
		};
		const lg = fs.querySelector( 'legend' );
		const ul = fs.querySelector( '.wplc-entitlement-group:not([hidden]) ul' );
		const container = fs.closest( '.woocommerce_variation, .woocommerce_options_panel' ) || fs.parentElement;
		return { fieldset: box( fs ), legend: box( lg ), list: ul ? box( ul ) : null, container: box( container ), vw: document.documentElement.clientWidth };
	}, scopeSel );
	if ( ! g ) {
		fail( label + ': entitlement checklist not visible', scopeSel );
		return;
	}
	const inside = ( a, b ) => a.l >= b.l - 1 && a.r <= b.r + 1;
	check( inside( g.legend, g.container ) && inside( g.fieldset, g.container ), label + ': legend and checklist stay inside their panel', g );
	if ( ! g.list ) {
		fail( label + ': no visible entitlement group', g );
		return;
	}
	check( inside( g.list, g.container ), label + ': checkbox list stays inside its panel', g );
	if ( 'side' === opts.layout ) {
		const beside = opts.rtl ? g.legend.l >= g.list.r - 2 : g.legend.r <= g.list.l + 2;
		check( beside, label + ': legend in the label column ' + ( opts.rtl ? 'right of' : 'left of' ) + ' the list', g );
	} else {
		check( g.legend.b <= g.list.t + 2, label + ': legend above the list (stacked)', g );
	}
}

async function productEditor( page, issues, width, rtl, shimCss ) {
	const after = async ( p ) => {
		if ( shimCss ) {
			await p.addStyleTag( { content: shimCss } );
		}
	};
	const sfx = ' @' + width + ( rtl ? ' rtl' : '' );
	await visit( page, issues, { label: 'product editor (simple)', url: ORIGIN + '/wp-admin/post.php?post=' + FIX.simple_id + '&action=edit', ready: '.wplc-entitlements', root: '#woocommerce-product-data .wplc-license-fields', width, rtl, after } );
	await entitlementGeometry( page, '#general_product_data', 'A-125 product editor (simple)' + sfx, { layout: width > 500 ? 'side' : 'stacked', rtl } );

	await visit( page, issues, { label: 'product editor (variable)', url: ORIGIN + '/wp-admin/post.php?post=' + FIX.variable_id + '&action=edit', ready: '#woocommerce-product-data', width, rtl, after } );
	await page.click( '.variations_tab a, li.variations_options a' ).catch( () => {} );
	await page.waitForSelector( '.woocommerce_variation', { timeout: 15000 } ).catch( () => {} );
	const variation = await page.$( '.woocommerce_variation' );
	if ( ! variation ) {
		fail( 'A-125 product editor (variation)' + sfx + ': variations did not load (admin-ajax)' );
		return;
	}
	await page.evaluate( () => {
		const v = document.querySelector( '.woocommerce_variation' );
		v.classList.remove( 'closed' );
		v.classList.add( 'open' );
		const content = v.querySelector( '.woocommerce_variable_attributes' );
		if ( content ) {
			content.style.display = 'block';
		}
		document.body.dispatchEvent( new Event( 'woocommerce_variations_loaded' ) );
	} );
	await page.waitForTimeout( 300 );
	await entitlementGeometry( page, '.woocommerce_variation', 'A-125 product editor (variation)' + sfx, { layout: 'stacked', rtl } );
	if ( width <= 480 ) {
		const o = await overflow( page, '.woocommerce_variation .wplc-license-fields' );
		check( 0 === o.count, 'product editor (variation)' + sfx + ': no plugin element wider than the viewport', o );
	}
	await page.locator( '.woocommerce_variation' ).first().scrollIntoViewIfNeeded().catch( () => {} );
	await shot( page, 'product editor variation' + sfx );
}

// ---------------------------------------------------------------------------
// Main.
// ---------------------------------------------------------------------------
( async () => {
	fs.mkdirSync( OUT, { recursive: true } );
	for ( const f of fs.readdirSync( OUT ) ) {
		if ( f.endsWith( '.png' ) ) {
			fs.unlinkSync( path.join( OUT, f ) );
		}
	}
	const proxy = await startProxy();
	const browser = await chromium.launch( { args: [ '--host-resolver-rules=MAP shop.test 127.0.0.1:' + PROXY_PORT, '--proxy-server=direct://' ] } );
	const shimProbe = await new Promise( ( resolve ) => http.get( { host: '127.0.0.1', port: PORT, path: '/wp-content/plugins/woocommerce/assets/css/admin.css' }, ( r ) => {
		r.resume();
		resolve( r.statusCode );
	} ).on( 'error', () => resolve( 0 ) ) );
	const shimCss = 200 === shimProbe ? null : fs.readFileSync( path.join( __dirname, 'wc-admin-shim.css' ), 'utf8' );
	const frontProbe = await new Promise( ( resolve ) => http.get( { host: '127.0.0.1', port: PORT, path: '/wp-content/plugins/woocommerce/assets/css/woocommerce-smallscreen.css' }, ( r ) => {
		r.resume();
		resolve( r.statusCode );
	} ).on( 'error', () => resolve( 0 ) ) );
	if ( 200 !== frontProbe ) {
		FRONT_SHIM = fs.readFileSync( path.join( __dirname, 'wc-frontend-shim.css' ), 'utf8' );
		note( 'WooCommerce has no compiled frontend CSS (source build): responsive account tables use tests/browser/wc-frontend-shim.css' );
	}
	if ( shimCss ) {
		note( 'WooCommerce has no compiled admin.css (source build): the product panel layout uses tests/browser/wc-admin-shim.css' );
	}

	try {
		// ---- Customer. ----
		for ( const width of [ 1280, 375 ] ) {
			const ctx = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width, height: 900 }, permissions: [ 'clipboard-read', 'clipboard-write' ] } );
			if ( ! ( await login( ctx, FIX.customer.login, FIX.customer.password ) ) ) {
				await ctx.close();
				continue;
			}
			const page = await ctx.newPage();
			const issues = watch( page );
			await classicCheckout( page, issues, width );
			await visit( page, issues, { label: 'account licenses', url: FIX.licenses_url, ready: '.wplc-license', root: '.woocommerce-MyAccount-content', css: 'link#wplc-account-css', width } );
			await copyButton( page, width );
			await visit( page, issues, { label: 'account sites', url: FIX.sites_url, ready: '.wplc-sites, .wplc-sites-summary', root: '.woocommerce-MyAccount-content', css: 'link#wplc-account-css', width } );
			await visit( page, issues, { label: 'account dashboard', url: FIX.account_url, ready: '.woocommerce-MyAccount-navigation', root: '.woocommerce-MyAccount-navigation', width } );
			if ( FIX.blocks_built ) {
				await visit( page, issues, { label: 'block checkout', url: FIX.checkout_url, ready: '.wc-block-checkout', root: '.wc-block-checkout', width } );
				await page.waitForSelector( '.wc-block-components-checkbox input[id*="wplc"], input[id*="withdrawal-consent"]', { timeout: 15000 } ).then( () => pass( 'block checkout @' + width + ': withdrawal consent field rendered' ) ).catch( () => fail( 'block checkout @' + width + ': withdrawal consent field not rendered' ) );
			} else if ( 1280 === width ) {
				note( 'block checkout: not checkable here, WooCommerce Blocks scripts are not built (source build); runs automatically with the release ZIP' );
			}
			if ( issues.foreign.length ) {
				note( 'customer @' + width + ': ' + issues.foreign.length + ' console error(s) from other code', [ ...new Set( issues.foreign ) ].slice( 0, 5 ) );
			}
			await ctx.close();
		}

		// ---- Customer, right-to-left. ----
		{
			const ctx = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 375, height: 900 }, permissions: [ 'clipboard-read', 'clipboard-write' ] } );
			await ctx.addCookies( [ { name: 'wplc_browser_rtl', value: '1', domain: 'shop.test', path: '/', secure: true } ] );
			if ( await login( ctx, FIX.customer.login, FIX.customer.password ) ) {
				const page = await ctx.newPage();
				const issues = watch( page );
				await visit( page, issues, { label: 'account licenses', url: FIX.licenses_url, ready: '.wplc-license', root: '.woocommerce-MyAccount-content', css: 'link#wplc-account-css', width: 375, rtl: true } );
				await visit( page, issues, { label: 'account sites', url: FIX.sites_url, ready: '.wplc-sites, .wplc-sites-summary', root: '.woocommerce-MyAccount-content', css: 'link#wplc-account-css', width: 375, rtl: true } );
			}
			await ctx.close();
		}

		// ---- Admin. ----
		const adminPages = [
			[ 'admin license list', '/wp-admin/admin.php?page=wplc-licenses', '.wp-list-table.wplc-licenses' ],
			[ 'admin license detail', '/wp-admin/admin.php?page=wplc-licenses&license=' + FIX.license_id, '.wplc-license-detail' ],
			[ 'admin add license', '/wp-admin/admin.php?page=wplc-licenses&view=add', '#wpbody-content form' ],
			[ 'admin settings', '/wp-admin/admin.php?page=wplc-settings', '#wpbody-content form' ],
			[ 'admin releases', '/wp-admin/admin.php?page=wplc-releases', '#wpbody-content .wrap' ],
			[ 'admin renewal review', '/wp-admin/admin.php?page=wplc-renewal-review', '#wpbody-content .wrap' ],
			[ 'admin audit log', '/wp-admin/admin.php?page=wplc-audit-log', '#wpbody-content .wrap' ],
			[ 'admin diagnostics', '/wp-admin/admin.php?page=wplc-diagnostics', '#wpbody-content .wrap' ],
			[ 'admin catalog CSV', '/wp-admin/admin.php?page=wplc-catalog-csv', '#wpbody-content .wrap' ],
		];
		const variants = [ [ 1280, false ], [ 375, false ], [ 1280, true ], [ 375, true ] ];
		for ( const [ width, rtl ] of variants ) {
			const ctx = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width, height: 900 } } );
			if ( rtl ) {
				await ctx.addCookies( [ { name: 'wplc_browser_rtl', value: '1', domain: 'shop.test', path: '/', secure: true } ] );
			}
			if ( ! ( await login( ctx, FIX.admin.login, FIX.admin.password ) ) ) {
				await ctx.close();
				continue;
			}
			const page = await ctx.newPage();
			const issues = watch( page );
			for ( const [ label, url, ready ] of adminPages ) {
				await visit( page, issues, { label, url: ORIGIN + url, ready, root: '#wpbody-content .wrap', css: /catalog-csv|view=add/.test( url ) ? null : 'link#wplc-admin-css', width, rtl } );
			}
			if ( FIX.review_id ) {
				check( null !== ( await ( async () => {
					await page.goto( ORIGIN + '/wp-admin/admin.php?page=wplc-renewal-review' );
					return page.$( '#wplc-review-' + FIX.review_id );
				} )() ), 'admin renewal review @' + width + ( rtl ? ' rtl' : '' ) + ': open case #' + FIX.review_id + ' listed' );
			}
			await productEditor( page, issues, width, rtl, shimCss );
			if ( issues.foreign.length ) {
				note( 'admin @' + width + ( rtl ? ' rtl' : '' ) + ': ' + issues.foreign.length + ' console error(s) from other code', [ ...new Set( issues.foreign ) ].slice( 0, 5 ) );
			}
			await ctx.close();
		}
	} catch ( e ) {
		fail( 'acceptance script aborted', String( e.stack || e ) );
	} finally {
		await browser.close();
		proxy.server.close();
		fs.rmSync( proxy.dir, { recursive: true, force: true } );
	}

	fs.writeFileSync( path.join( OUT, 'report.json' ), JSON.stringify( results, null, 2 ) );
	const failed = results.filter( ( r ) => 'FAIL' === r.status ).length;
	const passed = results.filter( ( r ) => 'PASS' === r.status ).length;
	console.log( '\nBROWSER RESULT: ' + ( failed ? 'FAIL' : 'PASS' ) + ' (' + passed + ' passed, ' + failed + ' failed, ' + results.filter( ( r ) => 'NOTE' === r.status ).length + ' notes)' );
	process.exit( failed ? 1 : 0 );
} )();
