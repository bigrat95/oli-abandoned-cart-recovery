<?php
/**
 * Adaptateur de langue : WPML (API par filtres et actions).
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Crochets publics de l'API de WPML (wpml_*), appelés et non définis par ce plugin.

/**
 * WPML, avec WPML String Translation pour les textes personnalisés.
 */
class OLI_ACR_Lang_WPML extends OLI_ACR_Lang_Adapter {

	/**
	 * Contexte (domaine) des chaînes dans WPML String Translation.
	 */
	const CONTEXT = 'oli-abandoned-cart-recovery';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'wpml';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'WPML';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' );
	}

	/**
	 * Langues WPML, code => locale.
	 *
	 * @return array<string, string>
	 */
	private function map() {
		$list = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$map  = array();
		foreach ( (array) $list as $code => $lang ) {
			$lang                  = (array) $lang;
			$locale                = ! empty( $lang['default_locale'] ) ? (string) $lang['default_locale'] : (string) $code;
			$map[ (string) $code ] = $locale;
		}
		return $map;
	}

	/**
	 * Code WPML d'une locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private function code( $locale ) {
		$code = array_search( $locale, $this->map(), true );
		return false !== $code ? (string) $code : $this->short_code( $locale );
	}

	/**
	 * Locale d'un code WPML.
	 *
	 * @param mixed $code Code.
	 * @return string
	 */
	private function locale( $code ) {
		$map = $this->map();
		return is_string( $code ) && isset( $map[ $code ] ) ? $map[ $code ] : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function languages() {
		return array_values( $this->map() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function default_language() {
		$locale = $this->locale( apply_filters( 'wpml_default_language', null ) );
		return '' !== $locale ? $locale : get_locale();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current_language() {
		return $this->locale( apply_filters( 'wpml_current_language', null ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url    URL.
	 * @param string $locale Locale.
	 * @return string
	 */
	public function translate_url( $url, $locale ) {
		return (string) apply_filters( 'wpml_permalink', $url, $this->code( $locale ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $page_id ID de la page.
	 * @param string $locale  Locale.
	 * @return string
	 */
	public function page_url( $page_id, $locale ) {
		$translated = (int) apply_filters( 'wpml_object_id', $page_id, 'page', true, $this->code( $locale ) );
		$url        = get_permalink( $translated ? $translated : $page_id );
		return $this->translate_url( $url ? $url : home_url( '/' ), $locale );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $locale Locale.
	 * @return mixed
	 */
	public function switch_language( $locale ) {
		$previous = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', $this->code( $locale ) );
		return array( 'lang' => $previous );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $previous État précédent.
	 * @return void
	 */
	public function restore_language( $previous ) {
		if ( is_array( $previous ) && array_key_exists( 'lang', $previous ) ) {
			do_action( 'wpml_switch_language', $previous['lang'] );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name      Nom.
	 * @param string $value     Texte.
	 * @param bool   $multiline Long.
	 * @return void
	 */
	public function register_string( $name, $value, $multiline = false ) {
		unset( $multiline );
		if ( '' !== $value ) {
			do_action( 'wpml_register_single_string', self::CONTEXT, $name, $value );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name   Nom.
	 * @param string $value  Texte.
	 * @param string $locale Locale.
	 * @return string|null
	 */
	public function translate_string( $name, $value, $locale ) {
		if ( '' === $value ) {
			return null;
		}
		$translated = (string) apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $name, $this->code( $locale ) );
		return ( '' !== $translated && $translated !== $value ) ? $translated : null;
	}
}
