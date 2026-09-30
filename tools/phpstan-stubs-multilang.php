<?php
/**
 * Stubs minimaux des extensions multilingues (PHPStan seulement, jamais chargé par WordPress).
 * Signatures reprises des API publiques de Polylang, TranslatePress, Weglot et WPML.
 *
 * @package OliAbandonedCartRecovery
 */

// phpcs:disable

/** @param array<string, mixed> $args @return array<int, mixed> */
function pll_languages_list( $args = array() ) { return array(); }
/** @param string $field @return string|false */
function pll_default_language( $field = 'slug' ) { return ''; }
/** @param string $field @return string|false */
function pll_current_language( $field = 'slug' ) { return ''; }
/** @param int $post_id @param string $lang @return int|false|null */
function pll_get_post( $post_id, $lang = '' ) { return 0; }
/** @param int $post_id @param string $field @return string|false */
function pll_get_post_language( $post_id, $field = 'slug' ) { return ''; }
/** @param string $name @param string $string @param string $context @param bool $multiline @return void */
function pll_register_string( $name, $string, $context = 'Polylang', $multiline = false ) {}
/** @param string $string @param string $lang @return string */
function pll_translate_string( $string, $lang ) { return $string; }
/** @param string $lang @return string */
function pll_home_url( $lang = '' ) { return ''; }
/** @return object */
function PLL() { return new stdClass(); }

class TRP_Translate_Press {
	/** @return TRP_Translate_Press|null */
	public static function get_trp_instance() { return null; }
	/** @param string $component @return object|null */
	public function get_component( $component ) { return null; }
}

/** @return string */
function weglot_get_original_language() { return ''; }
/** @return string */
function weglot_get_current_language() { return ''; }
/** @return array<int, mixed> */
function weglot_get_destination_languages() { return array(); }

/** @param string $context @param string $name @return void */
function icl_unregister_string( $context, $name ) {}
