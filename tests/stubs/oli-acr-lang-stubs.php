<?php
/**
 * MU plugin de TEST seulement (jamais en production) : simule WPML ou Weglot pour les tests E2E
 * d'Oli Abandoned Cart Recovery, et relie les pages WooCommerce traduites pour Polylang.
 *
 * Mode choisi par l'option « oli_acr_test_lang_stub » : wpml, weglot, polylang (liaison des pages) ou vide.
 * - WPML : langue en paramètre (?lang=en), langue par défaut fr, String Translation simulée (option).
 * - Weglot : sous-répertoire /en/, langue d'origine fr.
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'ABSPATH' ) || exit;

$oli_acr_stub = get_option( 'oli_acr_test_lang_stub', '' );

/**
 * Langue « en » ou « fr » de la requête simulée.
 *
 * @return string
 */
function oli_acr_stub_lang() {
	return isset( $GLOBALS['oli_acr_stub_lang'] ) ? $GLOBALS['oli_acr_stub_lang'] : 'fr';
}

if ( 'wpml' === $oli_acr_stub ) {
	define( 'ICL_SITEPRESS_VERSION', '4.7.0-stub' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$GLOBALS['oli_acr_stub_lang'] = ( isset( $_GET['lang'] ) && 'en' === $_GET['lang'] ) ? 'en' : ( ( isset( $_COOKIE['stub_wpml_lang'] ) && 'en' === $_COOKIE['stub_wpml_lang'] && isset( $_GET['wc-ajax'] ) ) ? 'en' : 'fr' );
	add_filter( 'wpml_active_languages', static function () {
		return array(
			'fr' => array( 'code' => 'fr', 'default_locale' => 'fr_CA' ),
			'en' => array( 'code' => 'en', 'default_locale' => 'en_US' ),
		);
	}, 10, 0 );
	add_filter( 'wpml_default_language', static function () {
		return 'fr';
	}, 10, 0 );
	add_filter( 'wpml_current_language', static function () {
		return oli_acr_stub_lang();
	}, 10, 0 );
	add_action( 'wpml_switch_language', static function ( $code ) {
		$GLOBALS['oli_acr_stub_lang'] = in_array( $code, array( 'en', 'fr' ), true ) ? $code : 'fr';
	} );
	add_filter( 'wpml_permalink', static function ( $url, $code ) {
		$url = remove_query_arg( 'lang', $url );
		return 'en' === $code ? add_query_arg( 'lang', 'en', $url ) : $url;
	}, 10, 2 );
	add_filter( 'wpml_object_id', static function ( $id ) {
		return $id;
	}, 10, 1 );
	add_action( 'wpml_register_single_string', static function ( $context, $name, $value ) {
		$reg                    = (array) get_option( 'oli_acr_stub_wpml_registered', array() );
		$reg[ $context ][ $name ] = $value;
		update_option( 'oli_acr_stub_wpml_registered', $reg, false );
	}, 10, 3 );
	add_filter( 'wpml_translate_single_string', static function ( $value, $context, $name, $code = null ) {
		$code = $code ? $code : oli_acr_stub_lang();
		$tr   = (array) get_option( 'oli_acr_stub_wpml_strings', array() );
		return isset( $tr[ $context ][ $name ][ $code ] ) ? $tr[ $context ][ $name ][ $code ] : $value;
	}, 10, 4 );
	// Langue de l'interface publique, comme WPML.
	add_filter( 'locale', static function ( $locale ) {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return $locale;
		}
		return 'en' === oli_acr_stub_lang() ? 'en_US' : $locale;
	} );
}

if ( 'weglot' === $oli_acr_stub ) {
	$GLOBALS['oli_acr_stub_lang'] = 'fr';
	if ( isset( $_SERVER['REQUEST_URI'] ) && preg_match( '#^/en(/|\?|$)#', (string) $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore
		$GLOBALS['oli_acr_stub_lang'] = 'en';
		$_SERVER['REQUEST_URI']       = (string) preg_replace( '#^/en#', '', (string) $_SERVER['REQUEST_URI'] ); // phpcs:ignore
		if ( '' === $_SERVER['REQUEST_URI'] || '?' === $_SERVER['REQUEST_URI'][0] ) {
			$_SERVER['REQUEST_URI'] = '/' . $_SERVER['REQUEST_URI'];
		}
		// Le serveur PHP intégré remplit PATH_INFO, que WP::parse_request() préfère à REQUEST_URI.
		if ( isset( $_SERVER['PATH_INFO'] ) ) {
			$_SERVER['PATH_INFO'] = (string) preg_replace( '#^/en(?=/|$)#', '', (string) $_SERVER['PATH_INFO'] ); // phpcs:ignore
			if ( '' === $_SERVER['PATH_INFO'] ) {
				$_SERVER['PATH_INFO'] = '/';
			}
		}
	}
	/**
	 * Langue d'origine.
	 *
	 * @return string
	 */
	function weglot_get_original_language() {
		return 'fr';
	}
	/**
	 * Langue courante.
	 *
	 * @return string
	 */
	function weglot_get_current_language() {
		return oli_acr_stub_lang();
	}
	/**
	 * Langues de destination.
	 *
	 * @return array
	 */
	function weglot_get_destination_languages() {
		return array( array( 'language_to' => 'en' ) );
	}
	add_filter( 'locale', static function ( $locale ) {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return $locale;
		}
		return 'en' === oli_acr_stub_lang() ? 'en_US' : $locale;
	} );
	// Weglot garde la langue dans les liens de la page : on préfixe les URL du site (redirections WooCommerce).
	add_filter( 'woocommerce_get_checkout_url', static function ( $url ) {
		return 'en' === oli_acr_stub_lang() ? str_replace( home_url( '/' ), home_url( '/en/' ), $url ) : $url;
	} );
}

if ( 'polylang' === $oli_acr_stub ) {
	// Ce que fait « Polylang for WooCommerce » : pages WooCommerce traduites reconnues comme panier et paiement.
	foreach ( array( 'checkout', 'cart' ) as $oli_acr_page ) {
		add_filter( 'woocommerce_get_' . $oli_acr_page . '_page_id', static function ( $id ) {
			return function_exists( 'pll_get_post' ) && pll_get_post( $id ) ? pll_get_post( $id ) : $id;
		} );
	}
}
