<?php
/**
 * Adaptateur de langue : Polylang.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Polylang (et Polylang Pro).
 */
class OLI_ACR_Lang_Polylang extends OLI_ACR_Lang_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'polylang';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Polylang';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return function_exists( 'pll_languages_list' ) && function_exists( 'PLL' );
	}

	/**
	 * Langues Polylang (objets avec slug et locale).
	 *
	 * @return array<int, object>
	 */
	private function objects() {
		$pll = PLL();
		if ( ! is_object( $pll ) || ! isset( $pll->model ) || ! is_object( $pll->model ) || ! is_callable( array( $pll->model, 'get_languages_list' ) ) ) {
			return array();
		}
		return (array) $pll->model->get_languages_list();
	}

	/**
	 * Slug Polylang d'une locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private function slug( $locale ) {
		foreach ( $this->objects() as $lang ) {
			if ( isset( $lang->locale, $lang->slug ) && $lang->locale === $locale ) {
				return (string) $lang->slug;
			}
		}
		return $this->short_code( $locale );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function languages() {
		return array_values( array_filter( array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'locale' ) ) ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function default_language() {
		$locale = function_exists( 'pll_default_language' ) ? pll_default_language( 'locale' ) : '';
		return is_string( $locale ) && '' !== $locale ? $locale : get_locale();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current_language() {
		$locale = function_exists( 'pll_current_language' ) ? pll_current_language( 'locale' ) : '';
		return is_string( $locale ) ? $locale : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url    URL.
	 * @param string $locale Locale.
	 * @return string
	 */
	public function translate_url( $url, $locale ) {
		$pll = PLL();
		if ( ! isset( $pll->links_model, $pll->model ) || ! is_callable( array( $pll->model, 'get_language' ) ) ) {
			return $url;
		}
		$slug = $this->slug( $locale );
		$lang = $pll->model->get_language( $slug );
		if ( ! $lang ) {
			return $url;
		}
		// Accueil : URL d'accueil de la langue (tient compte de « cacher la langue par défaut »).
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( function_exists( 'pll_home_url' ) && isset( $parts['path'], $home['path'] ) && untrailingslashit( $parts['path'] ) === untrailingslashit( $home['path'] ) ) {
			$base = pll_home_url( $slug );
			return empty( $parts['query'] ) ? $base : $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . $parts['query'];
		}
		if ( is_callable( array( $pll->links_model, 'switch_language_in_link' ) ) ) {
			return (string) $pll->links_model->switch_language_in_link( $url, $lang );
		}
		$url = $pll->links_model->remove_language_from_link( $url );
		return (string) $pll->links_model->add_language_to_link( $url, $lang );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $page_id ID de la page.
	 * @param string $locale  Locale.
	 * @return string
	 */
	public function page_url( $page_id, $locale ) {
		if ( function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $page_id, $this->slug( $locale ) );
			if ( $translated ) {
				$url = get_permalink( (int) $translated );
				if ( $url ) {
					return $url;
				}
			}
		}
		return parent::page_url( $page_id, $locale );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $locale Locale.
	 * @return mixed
	 */
	public function switch_language( $locale ) {
		$pll = PLL();
		if ( ! isset( $pll->model ) || ! is_callable( array( $pll->model, 'get_language' ) ) ) {
			return null;
		}
		$previous = isset( $pll->curlang ) ? $pll->curlang : null;
		$lang     = $pll->model->get_language( $this->slug( $locale ) );
		if ( $lang ) {
			$pll->curlang = $lang;
		}
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
			PLL()->curlang = $previous['lang'];
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
		if ( function_exists( 'pll_register_string' ) && '' !== $value ) {
			pll_register_string( $name, $value, 'Oli Abandoned Cart Recovery', $multiline );
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
		if ( ! function_exists( 'pll_translate_string' ) || '' === $value ) {
			return null;
		}
		$translated = (string) pll_translate_string( $value, $this->slug( $locale ) );
		return ( '' !== $translated && $translated !== $value ) ? $translated : null;
	}
}
