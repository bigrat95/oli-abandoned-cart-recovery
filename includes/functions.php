<?php
/**
 * Fonctions utilitaires partagées.
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs par défaut des réglages.
 *
 * @return array
 */
function oli_acr_default_settings() {
	return array(
		'enabled'               => 'yes',
		'abandon_after'         => array(
			'value' => 60,
			'unit'  => 'minutes',
		),
		'guest_tracking'        => 'always',
		'consent_text'          => '',
		'roles_mode'            => 'all',
		'roles'                 => array(),
		'pending_enabled'       => 'no',
		'pending_after'         => array(
			'value' => 1,
			'unit'  => 'hours',
		),
		'pending_cancel_after'  => array(
			'value' => 0,
			'unit'  => 'days',
		),
		'cron_interval'         => array(
			'value' => 10,
			'unit'  => 'minutes',
		),
		'delete_carts_after'    => array(
			'value' => 30,
			'unit'  => 'days',
		),
		'retention_days'        => 365,
		'sender_name'           => '',
		'sender_email'          => '',
		'reply_to'              => '',
		'admin_notify'          => 'yes',
		'admin_recipient'       => '',
		'coupon_prefix'         => 'OLI',
		'coupon_delete_used'    => 'yes',
		'coupon_delete_expired' => 'yes',
		'shop_manager_access'   => 'no',
	);
}

/**
 * Retourne tous les réglages fusionnés avec les valeurs par défaut.
 *
 * @return array
 */
function oli_acr_settings() {
	$saved = get_option( 'oli_acr_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, oli_acr_default_settings() );
}

/**
 * Retourne un réglage.
 *
 * @param string $key Clé du réglage.
 * @return mixed
 */
function oli_acr_get_setting( $key ) {
	$settings = oli_acr_settings();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * Texte de consentement (réglage, sinon texte par défaut traduit).
 *
 * @return string
 */
function oli_acr_consent_text() {
	$text = (string) oli_acr_get_setting( 'consent_text' );
	return '' !== trim( $text ) ? $text : __( 'Save my email and cart so the store can remind me about my order.', 'oli-abandoned-cart-recovery' );
}

/**
 * Unités de durée permises.
 *
 * @return array
 */
function oli_acr_duration_units() {
	return array(
		'minutes' => __( 'Minutes', 'oli-abandoned-cart-recovery' ),
		'hours'   => __( 'Hours', 'oli-abandoned-cart-recovery' ),
		'days'    => __( 'Days', 'oli-abandoned-cart-recovery' ),
	);
}

/**
 * Convertit une durée (valeur + unité) en secondes.
 *
 * @param array|int $duration Durée.
 * @return int
 */
function oli_acr_duration_to_seconds( $duration ) {
	if ( ! is_array( $duration ) ) {
		return absint( $duration ) * MINUTE_IN_SECONDS;
	}
	$value = isset( $duration['value'] ) ? absint( $duration['value'] ) : 0;
	$unit  = isset( $duration['unit'] ) ? $duration['unit'] : 'minutes';
	switch ( $unit ) {
		case 'days':
			return $value * DAY_IN_SECONDS;
		case 'hours':
			return $value * HOUR_IN_SECONDS;
		default:
			return $value * MINUTE_IN_SECONDS;
	}
}

/**
 * Nettoie une durée soumise par formulaire.
 *
 * @param mixed $raw Valeur brute.
 * @param int   $min Minimum permis.
 * @return array
 */
function oli_acr_sanitize_duration( $raw, $min = 0 ) {
	$raw   = is_array( $raw ) ? $raw : array();
	$value = isset( $raw['value'] ) ? absint( $raw['value'] ) : 0;
	$unit  = isset( $raw['unit'] ) ? sanitize_key( $raw['unit'] ) : 'minutes';
	if ( ! array_key_exists( $unit, oli_acr_duration_units() ) ) {
		$unit = 'minutes';
	}
	return array(
		'value' => max( $min, $value ),
		'unit'  => $unit,
	);
}

/**
 * Date et heure courantes en UTC au format MySQL.
 *
 * @param int $offset Décalage en secondes.
 * @return string
 */
function oli_acr_now( $offset = 0 ) {
	return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
}

/**
 * Libellés des statuts de panier.
 *
 * @return array
 */
function oli_acr_statuses() {
	return array(
		'open'         => _x( 'Active', 'cart status', 'oli-abandoned-cart-recovery' ),
		'abandoned'    => _x( 'Abandoned', 'cart status', 'oli-abandoned-cart-recovery' ),
		'reminded'     => _x( 'Reminded', 'cart status', 'oli-abandoned-cart-recovery' ),
		'recovered'    => _x( 'Recovered', 'cart status', 'oli-abandoned-cart-recovery' ),
		'unsubscribed' => _x( 'Unsubscribed', 'cart status', 'oli-abandoned-cart-recovery' ),
	);
}

/**
 * Liste d'exclusion (courriels désabonnés), en minuscules.
 *
 * @return array
 */
function oli_acr_get_blocklist() {
	$list = get_option( 'oli_acr_blocklist', array() );
	return is_array( $list ) ? $list : array();
}

/**
 * Indique si un courriel est désabonné ou exclu.
 *
 * @param string $email Courriel.
 * @return bool
 */
function oli_acr_is_unsubscribed( $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return false;
	}
	return in_array( $email, oli_acr_get_blocklist(), true );
}

/**
 * Ajoute un courriel à la liste d'exclusion et arrête ses relances.
 *
 * @param string $email Courriel.
 * @return bool
 */
function oli_acr_unsubscribe( $email ) {
	$email = strtolower( sanitize_email( $email ) );
	if ( ! is_email( $email ) ) {
		return false;
	}
	$list = oli_acr_get_blocklist();
	if ( ! in_array( $email, $list, true ) ) {
		$list[] = $email;
		update_option( 'oli_acr_blocklist', array_values( $list ), false );
	}
	OLI_ACR_Carts::mark_unsubscribed( $email );
	/**
	 * Déclenché après un désabonnement.
	 *
	 * @param string $email Courriel désabonné.
	 */
	do_action( 'oli_acr_unsubscribed', $email );
	return true;
}

/**
 * Signature HMAC d'un courriel pour le lien de désabonnement.
 *
 * @param string $email Courriel.
 * @return string
 */
function oli_acr_email_signature( $email ) {
	return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) . 'oli_acr_unsub' );
}

/**
 * URL de désabonnement signée.
 *
 * @param string $email Courriel.
 * @return string
 */
function oli_acr_unsubscribe_url( $email ) {
	return add_query_arg(
		array(
			'oli_acr_unsub' => rawurlencode( $email ),
			'oli_acr_sig'   => oli_acr_email_signature( $email ),
		),
		home_url( '/' )
	);
}

/**
 * Indique si un utilisateur connecté doit être suivi selon les rôles choisis.
 *
 * @param int $user_id ID utilisateur.
 * @return bool
 */
function oli_acr_user_is_tracked( $user_id ) {
	if ( ! $user_id ) {
		return 'never' !== oli_acr_get_setting( 'guest_tracking' );
	}
	if ( 'selected' !== oli_acr_get_setting( 'roles_mode' ) ) {
		return true;
	}
	$user  = get_userdata( $user_id );
	$roles = (array) oli_acr_get_setting( 'roles' );
	if ( ! $user || empty( $roles ) ) {
		return false;
	}
	return (bool) array_intersect( (array) $user->roles, $roles );
}

/**
 * Langue courante du visiteur (compatible WPML / Polylang / TranslatePress).
 *
 * @return string
 */
function oli_acr_current_language() {
	if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
		return (string) ICL_LANGUAGE_CODE;
	}
	if ( function_exists( 'pll_current_language' ) ) {
		$lang = pll_current_language();
		if ( $lang ) {
			return (string) $lang;
		}
	}
	return determine_locale();
}

/**
 * Journalise un message dans les journaux WooCommerce.
 *
 * @param string $message Message.
 * @param string $level   Niveau.
 */
function oli_acr_log( $message, $level = 'info' ) {
	/**
	 * Active la journalisation détaillée.
	 *
	 * @param bool $enabled Actif ou non.
	 */
	if ( ! apply_filters( 'oli_acr_enable_logging', defined( 'WP_DEBUG' ) && WP_DEBUG ) || ! function_exists( 'wc_get_logger' ) ) {
		return;
	}
	wc_get_logger()->log( $level, $message, array( 'source' => 'oli-abandoned-cart-recovery' ) );
}

/**
 * Nom des tables maison.
 *
 * @param string $name carts ou log.
 * @return string
 */
function oli_acr_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'oli_acr_' . $name;
}
/**
 * Formate une date UTC stockée pour l'affichage local.
 *
 * @param string|null $date Date UTC.
 * @return string
 */
function oli_acr_admin_date( $date ) {
	if ( empty( $date ) ) {
		return '—';
	}
	return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $date . ' UTC' ) ) );
}
