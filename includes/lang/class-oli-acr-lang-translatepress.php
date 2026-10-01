<?php
/**
 * Adaptateur de langue : TranslatePress.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * TranslatePress (les langues sont déjà des locales WordPress).
 */
class OLI_ACR_Lang_TranslatePress extends OLI_ACR_Lang_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'translatepress';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'TranslatePress';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'TRP_Translate_Press' );
	}

	/**
	 * Réglages TranslatePress.
	 *
	 * @return array<string, mixed>
	 */
	private function settings() {
		$settings = get_option( 'trp_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function languages() {
		$settings = $this->settings();
		$list     = isset( $settings['translation-languages'] ) ? (array) $settings['translation-languages'] : array();
		return array_values( array_filter( array_map( 'strval', $list ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function default_language() {
		$settings = $this->settings();
		return ! empty( $settings['default-language'] ) ? (string) $settings['default-language'] : get_locale();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current_language() {
		global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Variable globale de TranslatePress.
		return is_string( $TRP_LANGUAGE ) ? $TRP_LANGUAGE : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	}

	/**
	 * Composant de conversion d'URL de TranslatePress.
	 *
	 * @return object|null
	 */
	private function url_converter() {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return null;
		}
		$trp = TRP_Translate_Press::get_trp_instance();
		if ( ! is_object( $trp ) || ! method_exists( $trp, 'get_component' ) ) {
			return null;
		}
		$converter = $trp->get_component( 'url_converter' );
		return is_object( $converter ) ? $converter : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url    URL.
	 * @param string $locale Locale.
	 * @return string
	 */
	public function translate_url( $url, $locale ) {
		$converter = $this->url_converter();
		if ( ! $converter || ! method_exists( $converter, 'get_url_for_language' ) ) {
			return $url;
		}
		$translated = $converter->get_url_for_language( $locale, $url, '' );
		return is_string( $translated ) && '' !== $translated ? $translated : $url;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $locale Locale.
	 * @return mixed
	 */
	public function switch_language( $locale ) {
		global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$previous     = array( 'lang' => $TRP_LANGUAGE ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$TRP_LANGUAGE = $locale; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variable globale de TranslatePress : langue pendant l'envoi.
		return $previous;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $previous État précédent.
	 * @return void
	 */
	public function restore_language( $previous ) {
		if ( is_array( $previous ) && array_key_exists( 'lang', $previous ) ) {
			$GLOBALS['TRP_LANGUAGE'] = $previous['lang']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variable globale de TranslatePress.
		}
	}
}
