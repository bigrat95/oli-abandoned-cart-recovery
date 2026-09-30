<?php
/**
 * Adaptateur de langue : cœur de WordPress (sans extension multilingue).
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Langues du cœur : langue du site, en_US et langues installées.
 */
class OLI_ACR_Lang_Core extends OLI_ACR_Lang_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'core';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'WordPress';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function languages() {
		return oli_acr_known_locales();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function default_language() {
		return get_locale();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current_language() {
		return determine_locale();
	}
}
