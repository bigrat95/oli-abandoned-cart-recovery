/**
 * Oli Abandoned Cart Recovery : capture du courriel au checkout.
 * Fonctionne avec le checkout classique et le checkout en blocs (écoute déléguée sur le document).
 */
( function () {
	'use strict';

	var cfg = window.oliAcrCapture;
	if ( ! cfg || ! window.fetch ) {
		return;
	}

	var SELECTORS = {
		email: '#billing_email, #email, .wc-block-checkout input[type="email"]',
		phone: '#billing_phone, #billing-phone, #shipping-phone',
		first: '#billing_first_name, #billing-first_name, #shipping-first_name',
		last: '#billing_last_name, #billing-last_name, #shipping-last_name',
		consent: '#oli_acr_consent, input[type="checkbox"][id*="oli-acr"]'
	};
	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
	var timer = null;
	var lastPayload = '';

	function val( selector ) {
		var el = document.querySelector( selector );
		return el && el.value ? String( el.value ).trim() : '';
	}

	function consentGiven() {
		var el = document.querySelector( SELECTORS.consent );
		return el ? el.checked : false;
	}

	function send() {
		var email = val( SELECTORS.email );
		if ( ! EMAIL_RE.test( email ) ) {
			return;
		}
		var data = {
			email: email,
			phone: val( SELECTORS.phone ),
			first_name: val( SELECTORS.first ),
			last_name: val( SELECTORS.last ),
			consent: cfg.consent ? ( consentGiven() ? '1' : '0' ) : '1'
		};
		var payload = JSON.stringify( data );
		if ( payload === lastPayload ) {
			return;
		}
		lastPayload = payload;

		var body = new window.FormData();
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		window.fetch( cfg.endpoint, { method: 'POST', credentials: 'same-origin', body: body } ).catch( function () {
			lastPayload = '';
		} );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( send, cfg.delay || 800 );
	}

	function matches( el ) {
		if ( ! el || ! el.matches ) {
			return false;
		}
		return el.matches( SELECTORS.email ) || el.matches( SELECTORS.phone ) || el.matches( SELECTORS.first ) || el.matches( SELECTORS.last ) || el.matches( SELECTORS.consent );
	}

	[ 'change', 'focusout', 'input' ].forEach( function ( type ) {
		document.addEventListener( type, function ( event ) {
			if ( matches( event.target ) ) {
				schedule();
			}
		}, true );
	} );

	// Courriel déjà prérempli (client connecté ou retour de relance).
	window.addEventListener( 'load', function () {
		window.setTimeout( send, 1500 );
	} );
}() );
