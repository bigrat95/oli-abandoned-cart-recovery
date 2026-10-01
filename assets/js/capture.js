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

	// Envoie une requête de capture ; si le nonce de la page est périmé (403), en demande un frais et réessaie une fois.
	function post( fields, retried ) {
		var body = new window.FormData();
		body.append( 'nonce', cfg.nonce );
		body.append( 'oli_acr_hp', val( '#oli_acr_hp' ) );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return window.fetch( cfg.endpoint, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			if ( 403 === response.status && ! retried && cfg.nonceUrl ) {
				return window.fetch( cfg.nonceUrl, { credentials: 'same-origin', cache: 'no-store' } ).then( function ( r ) {
					return r.json();
				} ).then( function ( json ) {
					if ( json && json.success && json.data && json.data.nonce ) {
						cfg.nonce = json.data.nonce;
						return post( fields, true );
					}
					return response;
				} );
			}
			return response;
		} );
	}

	// Checkout en blocs : le libellé du consentement est du texte ; on affiche la version avec liens (HTML filtré par le serveur).
	function enhanceConsentLabel() {
		if ( ! cfg.consentHtml || cfg.consentHtml.indexOf( '<' ) === -1 ) {
			return;
		}
		var input = document.querySelector( 'input[type="checkbox"][id*="oli-acr"]' );
		if ( ! input ) {
			return;
		}
		var wrap = input.closest( 'label' ) || input.parentNode;
		var label = wrap ? wrap.querySelector( '.wc-block-components-checkbox__label' ) : null;
		// Remis à chaque rendu qui le remplace par du texte (aucune boucle : on ne réécrit que s'il n'y a plus de balise).
		if ( label && ! label.querySelector( 'a, strong, em' ) ) {
			label.innerHTML = cfg.consentHtml;
		}
		if ( label && label.getAttribute( 'data-oli-acr' ) !== '1' ) {
			label.setAttribute( 'data-oli-acr', '1' );
			// Un clic sur un lien du libellé ouvre le lien sans cocher la case.
			label.addEventListener( 'click', function ( event ) {
				if ( event.target && event.target.closest && event.target.closest( 'a' ) ) {
					event.stopPropagation();
				}
			} );
		}
	}

	function send() {
		var email = val( SELECTORS.email );
		if ( ! EMAIL_RE.test( email ) ) {
			return;
		}
		// Loi 25 : sans consentement, aucune donnée personnelle n'est transmise (seulement le retrait).
		// wp_localize_script transmet les valeurs en chaînes (« 0 » ou « 1 »).
		var needConsent = String( cfg.consent ) === '1';
		if ( needConsent && ! consentGiven() ) {
			if ( lastPayload === 'no-consent' ) {
				return;
			}
			var wasSent = lastPayload !== '';
			lastPayload = 'no-consent';
			if ( wasSent ) {
				post( { consent: '0' }, false );
			}
			return;
		}
		var data = {
			email: email,
			phone: val( SELECTORS.phone ),
			first_name: val( SELECTORS.first ),
			last_name: val( SELECTORS.last ),
			consent: needConsent ? ( consentGiven() ? '1' : '0' ) : '1',
			lang: cfg.lang || document.documentElement.lang || ''
		};
		var payload = JSON.stringify( data );
		if ( payload === lastPayload ) {
			return;
		}
		lastPayload = payload;

		post( data, false ).then( function ( response ) {
			// Échec (sauf limite de débit) : on pourra réessayer au prochain changement.
			if ( ! response.ok && 429 !== response.status ) {
				lastPayload = '';
			}
		} ).catch( function () {
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

	if ( cfg.consentHtml && window.MutationObserver ) {
		new window.MutationObserver( enhanceConsentLabel ).observe( document.documentElement, { childList: true, subtree: true } );
	}

	// Courriel déjà prérempli (client connecté ou retour de relance).
	window.addEventListener( 'load', function () {
		enhanceConsentLabel();
		window.setTimeout( send, 1500 );
	} );
}() );
