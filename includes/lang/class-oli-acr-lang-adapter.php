<?php
/**
 * Adaptateur de langue : classe de base.
 *
 * Un adaptateur fait le lien entre le plugin et une extension multilingue (ou le cœur de WordPress).
 * Toutes les langues sont échangées sous forme de locales WordPress (fr_CA, en_US…).
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe de base des adaptateurs de langue.
 *
 * Pour ajouter une extension, étendre cette classe et l'ajouter avec le filtre « oli_acr_lang_adapters ».
 */
abstract class OLI_ACR_Lang_Adapter {

	/**
	 * Identifiant court (core, wpml, polylang, translatepress, weglot…).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Nom affiché.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * L'extension est-elle active ?
	 *
	 * @return bool
	 */
	abstract public function is_active();

	/**
	 * Langues actives du site, en locales.
	 *
	 * @return array<int, string>
	 */
	abstract public function languages();

	/**
	 * Langue par défaut du site, en locale.
	 *
	 * @return string
	 */
	abstract public function default_language();

	/**
	 * Langue de la requête courante, en locale (vide si inconnue).
	 *
	 * @return string
	 */
	abstract public function current_language();

	/**
	 * Convertit une URL du site vers la même URL dans une autre langue.
	 *
	 * @param string $url    URL.
	 * @param string $locale Locale.
	 * @return string
	 */
	public function translate_url( $url, $locale ) {
		unset( $locale );
		return $url;
	}

	/**
	 * URL d'une page (par ID) dans une langue : page traduite si l'extension en gère une.
	 *
	 * @param int    $page_id ID de la page.
	 * @param string $locale  Locale.
	 * @return string
	 */
	public function page_url( $page_id, $locale ) {
		$url = get_permalink( $page_id );
		return $this->translate_url( $url ? $url : home_url( '/' ), $locale );
	}

	/**
	 * Bascule l'extension dans une langue (pendant un envoi en tâche planifiée).
	 *
	 * @param string $locale Locale.
	 * @return mixed État précédent, à redonner à restore_language().
	 */
	public function switch_language( $locale ) {
		unset( $locale );
		return null;
	}

	/**
	 * Remet l'état précédent.
	 *
	 * @param mixed $previous Valeur rendue par switch_language().
	 * @return void
	 */
	public function restore_language( $previous ) {
		unset( $previous );
	}

	/**
	 * Enregistre une chaîne auprès du module de traduction de chaînes de l'extension.
	 *
	 * @param string $name      Nom unique.
	 * @param string $value     Texte dans la langue de repli.
	 * @param bool   $multiline Texte long (HTML).
	 * @return void
	 */
	public function register_string( $name, $value, $multiline = false ) {
		unset( $name, $value, $multiline );
	}

	/**
	 * Traduction d'une chaîne enregistrée, ou null s'il n'y en a pas.
	 *
	 * @param string $name   Nom unique.
	 * @param string $value  Texte d'origine.
	 * @param string $locale Locale voulue.
	 * @return string|null
	 */
	public function translate_string( $name, $value, $locale ) {
		unset( $name, $value, $locale );
		return null;
	}

	/**
	 * Code court (fr, en) d'une locale, utilisé par plusieurs extensions.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	protected function short_code( $locale ) {
		return strtolower( substr( (string) $locale, 0, 2 ) );
	}
}
