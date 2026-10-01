<?php
/**
 * Adaptateur de langue : Weglot.
 *
 * Weglot traduit le HTML des pages à la volée ; il n'a pas de module de chaînes.
 * Les textes par langue du plugin (onglets) sont donc la seule source pour les courriels.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Weglot (codes courts : en, fr…).
 */
class OLI_ACR_Lang_Weglot extends OLI_ACR_Lang_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'weglot';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Weglot';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return function_exists( 'weglot_get_original_language' ) && function_exists( 'weglot_get_current_language' );
	}

	/**
	 * Codes Weglot : langue d'origine puis langues de destination.
	 *
	 * @return array<int, string>
	 */
	private function codes() {
		$codes = array( (string) weglot_get_original_language() );
		if ( function_exists( 'weglot_get_destination_languages' ) ) {
			foreach ( (array) weglot_get_destination_languages() as $dest ) {
				if ( is_array( $dest ) && isset( $dest['language_to'] ) ) {
					$codes[] = (string) $dest['language_to'];
				} elseif ( is_string( $dest ) ) {
					$codes[] = $dest;
				}
			}
		}
		return array_values( array_unique( array_filter( $codes ) ) );
	}

	/**
	 * Locale WordPress d'un code Weglot (une locale installée si possible).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private function to_locale( $code ) {
		$code = (string) $code;
		if ( '' === $code ) {
			return '';
		}
		foreach ( array_merge( array( get_locale() ), oli_acr_known_locales() ) as $locale ) {
			if ( 0 === stripos( $locale, $code ) ) {
				return $locale;
			}
		}
		return 'en' === $code ? 'en_US' : strtolower( $code ) . '_' . strtoupper( $code );
	}

	/**
	 * Code Weglot d'une locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private function to_code( $locale ) {
		foreach ( $this->codes() as $code ) {
			if ( $this->to_locale( $code ) === $locale ) {
				return $code;
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
		return array_values( array_unique( array_map( array( $this, 'to_locale' ), $this->codes() ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function default_language() {
		return $this->to_locale( (string) weglot_get_original_language() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current_language() {
		return $this->to_locale( (string) weglot_get_current_language() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Weglot utilise des sous-répertoires (/fr/…) : la langue d'origine n'a pas de préfixe.
	 *
	 * @param string $url    URL.
	 * @param string $locale Locale.
	 * @return string
	 */
	public function translate_url( $url, $locale ) {
		$code     = $this->to_code( $locale );
		$original = (string) weglot_get_original_language();
		$home     = untrailingslashit( home_url() );
		if ( 0 !== strpos( $url, $home ) ) {
			return $url;
		}
		$path = substr( $url, strlen( $home ) );
		// Retire un préfixe de langue existant.
		foreach ( $this->codes() as $known ) {
			if ( $known !== $original && ( 0 === strpos( $path, '/' . $known . '/' ) || '/' . $known === $path ) ) {
				$path = substr( $path, strlen( $known ) + 1 );
				break;
			}
		}
		if ( '' === $path || '?' === $path[0] ) {
			$path = '/' . $path;
		}
		if ( $code === $original ) {
			return $home . $path;
		}
		return $home . '/' . $code . $path;
	}
}
