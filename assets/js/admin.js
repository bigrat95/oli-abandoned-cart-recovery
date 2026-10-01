/**
 * Oli Abandoned Cart Recovery : script d'administration (écrans du plugin seulement).
 * Confirmation avant les liens de suppression marqués .oli-acr-confirm (remplace les attributs onclick).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( 'a.oli-acr-confirm' ) : null;
		if ( ! link ) {
			return;
		}
		var cfg     = window.oliAcrAdmin || {};
		var message = link.getAttribute( 'data-confirm' ) || cfg.confirmDeleteTemplate || '';
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
}() );
