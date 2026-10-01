<?php
/**
 * Fonctions utilitaires partagées.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs par défaut des réglages.
 *
 * @return array<mixed>
 */
function oli_acr_default_settings() {
	return array(
		'enabled'               => 'yes',
		'abandon_after'         => array(
			'value' => 60,
			'unit'  => 'minutes',
		),
		// Loi 25 (Québec) et RGPD : rien n'est capté pour un visiteur sans son consentement explicite.
		'guest_tracking'        => 'consent',
		'consent_text'          => '',
		// Textes de consentement par langue (locale => texte) et langue de repli (vide = langue par défaut du site).
		'consent_texts'         => array(),
		'fallback_language'     => '',
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
		'keep_data'             => 'no',
	);
}

/**
 * Retourne tous les réglages fusionnés avec les valeurs par défaut.
 *
 * @return array<mixed>
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
 * Texte de consentement par défaut, traduit dans la langue courante.
 *
 * @return string
 */
function oli_acr_default_consent_text() {
	return __( 'Save my email and cart so the store can remind me about my order.', 'oli-abandoned-cart-recovery' );
}

/**
 * Texte de consentement personnalisé de la langue de repli (vide = texte par défaut).
 *
 * @param string|null $fallback Langue de repli.
 * @return string
 */
function oli_acr_consent_base_text( $fallback = null ) {
	$fallback = null === $fallback ? OLI_ACR_Lang::fallback_language() : $fallback;
	$texts    = (array) oli_acr_get_setting( 'consent_texts' );
	$text     = isset( $texts[ $fallback ] ) ? (string) $texts[ $fallback ] : '';
	if ( '' === trim( $text ) ) {
		// Réglage de la 1.0.x (une seule langue).
		$text = (string) oli_acr_get_setting( 'consent_text' );
	}
	return ( '' === trim( $text ) || oli_acr_is_default_consent_text( $text ) ) ? '' : $text;
}

/**
 * Texte de consentement dans une langue, dans cet ordre de priorité :
 * texte saisi pour cette langue, traduction WPML / Polylang du texte de repli, texte par défaut
 * traduit (si le texte de repli n'est pas personnalisé), puis texte de repli.
 *
 * @param string $locale Langue (vide = langue de la requête).
 * @return string
 */
function oli_acr_consent_text( $locale = '' ) {
	$locale   = '' !== $locale ? OLI_ACR_Lang::resolve( $locale ) : OLI_ACR_Lang::current_language();
	$fallback = OLI_ACR_Lang::fallback_language();
	$texts    = (array) oli_acr_get_setting( 'consent_texts' );
	$own      = isset( $texts[ $locale ] ) ? trim( (string) $texts[ $locale ] ) : '';
	if ( '' !== $own && ! oli_acr_is_default_consent_text( $own ) ) {
		return $own;
	}
	$base = oli_acr_consent_base_text( $fallback );
	if ( '' === $base ) {
		return oli_acr_in_locale( $locale, 'oli_acr_default_consent_text' );
	}
	if ( $locale !== $fallback ) {
		$translated = OLI_ACR_Lang::adapter()->translate_string( OLI_ACR_Lang::string_name( 'consent_text' ), $base, $locale );
		if ( null !== $translated ) {
			return $translated;
		}
	}
	return $base;
}

/**
 * Balises permises dans le texte de consentement : liens (href, target, rel), gras et italique.
 *
 * @return array<string, array<string, bool>>
 */
function oli_acr_consent_allowed_html() {
	return array(
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
		'strong' => array(),
		'em'     => array(),
	);
}

/**
 * Nettoie un texte de consentement saisi (éditeur) : liens, gras et italique seulement, sur une ligne.
 *
 * @param string $text Texte.
 * @return string
 */
function oli_acr_sanitize_consent_html( $text ) {
	$text = str_replace( array( '<b>', '</b>', '<i>', '</i>' ), array( '<strong>', '</strong>', '<em>', '</em>' ), (string) $text );
	$text = (string) preg_replace( '#</p>\s*<p[^>]*>|<br\s*/?>#i', ' ', $text );
	$text = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $text );
	$text = wp_kses( $text, oli_acr_consent_allowed_html(), array( 'http', 'https', 'mailto' ) );
	// Lien sans adresse web valable (ex. « javascript: » retiré par wp_kses) : on garde seulement le texte.
	$text = (string) preg_replace_callback(
		'#<a\b([^>]*)>(.*?)</a>#is',
		static function ( $m ) {
			return preg_match( '#\bhref=(["\'])(https?://|mailto:|/|\#)#i', $m[1] ) ? $m[0] : $m[2];
		},
		$text
	);
	// Lien qui ouvre un nouvel onglet : rel="noopener" s'il n'est pas précisé.
	$text = (string) preg_replace( '#<a\b(?![^>]*\brel=)([^>]*\btarget=(["\'])_blank\2[^>]*)>#i', '<a$1 rel="noopener noreferrer">', $text );
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Texte de consentement prêt à afficher (HTML permis seulement).
 *
 * @param string $locale Langue (vide = langue de la requête).
 * @return string
 */
function oli_acr_consent_html( $locale = '' ) {
	return wp_kses( oli_acr_consent_text( $locale ), oli_acr_consent_allowed_html(), array( 'http', 'https', 'mailto' ) );
}

/**
 * Indique si un texte correspond au texte de consentement par défaut dans une des langues connues.
 *
 * @param string $text Texte.
 * @return bool
 */
function oli_acr_is_default_consent_text( $text ) {
	static $defaults = null;
	if ( null === $defaults ) {
		$defaults = array();
		foreach ( oli_acr_known_locales() as $locale ) {
			$defaults[] = oli_acr_normalize_text( oli_acr_in_locale( $locale, 'oli_acr_default_consent_text' ) );
		}
	}
	return in_array( oli_acr_normalize_text( $text ), $defaults, true );
}

/**
 * Normalise un texte pour comparer deux versions (espaces et balises de saut de ligne).
 *
 * @param string $text Texte.
 * @return string
 */
function oli_acr_normalize_text( $text ) {
	$text = str_replace( array( '<br />', '<br/>' ), '<br>', (string) $text );
	$text = (string) preg_replace( '#</?p\b[^>]*>#i', '', $text );
	return (string) preg_replace( '/\s+/u', '', $text );
}

/**
 * Langues connues du site : en_US, la langue du site et les langues installées.
 *
 * @return array<int, string>
 */
function oli_acr_known_locales() {
	$locales = array_merge( array( 'en_US', get_locale() ), get_available_languages() );
	return array_values( array_unique( array_filter( $locales ) ) );
}

/**
 * Convertit une langue enregistrée (fr_CA, en_US, ou un code court WPML / Polylang comme « fr »)
 * en locale installée, ou chaîne vide si elle n'est pas disponible.
 *
 * @param string $language Langue enregistrée.
 * @return string
 */
function oli_acr_locale_from_language( $language ) {
	$language = str_replace( '-', '_', trim( (string) $language ) );
	if ( '' === $language ) {
		return '';
	}
	$locales = oli_acr_known_locales();
	if ( in_array( $language, $locales, true ) ) {
		return $language;
	}
	foreach ( $locales as $locale ) {
		if ( 0 === stripos( $locale, substr( $language, 0, 2 ) ) ) {
			return $locale;
		}
	}
	return '';
}

/**
 * Exécute une fonction dans une autre langue (switch_to_locale, puis restore_previous_locale).
 *
 * @param string   $locale   Locale voulue (vide = langue courante).
 * @param callable $callback Fonction.
 * @param mixed    ...$args  Arguments.
 * @return mixed
 */
function oli_acr_in_locale( $locale, $callback, ...$args ) {
	$locale   = oli_acr_locale_from_language( $locale );
	$switched = '' !== $locale && determine_locale() !== $locale && switch_to_locale( $locale );
	try {
		return call_user_func_array( $callback, $args );
	} finally {
		if ( $switched ) {
			restore_previous_locale();
		}
	}
}

/**
 * Unités de durée permises.
 *
 * @return array<mixed>
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
 * @param array<mixed>|int $duration Durée.
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
 * @return array<mixed>
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
 * @return array<mixed>
 */
function oli_acr_statuses() {
	return array(
		'open'         => _x( 'Active', 'cart status', 'oli-abandoned-cart-recovery' ),
		'abandoned'    => _x( 'Abandoned', 'cart status', 'oli-abandoned-cart-recovery' ),
		'reminded'     => _x( 'Reminded', 'cart status', 'oli-abandoned-cart-recovery' ),
		'recovered'    => _x( 'Recovered', 'cart status', 'oli-abandoned-cart-recovery' ),
		'unsubscribed' => _x( 'Unsubscribed', 'cart status', 'oli-abandoned-cart-recovery' ),
		'failed'       => _x( 'Send failed', 'cart status', 'oli-abandoned-cart-recovery' ),
	);
}

/**
 * Liste d'exclusion (courriels désabonnés), en minuscules.
 *
 * @return array<mixed>
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
 * URL de désabonnement signée, dans la langue du destinataire.
 *
 * @param string $email  Courriel.
 * @param string $locale Langue (vide = langue de repli).
 * @return string
 */
function oli_acr_unsubscribe_url( $email, $locale = '' ) {
	$locale = OLI_ACR_Lang::resolve( $locale );
	return add_query_arg(
		array(
			'oli_acr_unsub' => rawurlencode( $email ),
			'oli_acr_sig'   => oli_acr_email_signature( $email ),
			'oli_acr_lang'  => $locale,
		),
		OLI_ACR_Lang::home_url( $locale )
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
 * Langue courante du visiteur, en locale (WPML, Polylang, TranslatePress, Weglot ou cœur).
 *
 * @return string
 */
function oli_acr_current_language() {
	return OLI_ACR_Lang::current_language();
}

/**
 * Journalise un message dans les journaux WooCommerce.
 *
 * @param string $message Message.
 * @param string $level   Niveau.
 * @return void
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
 * Interrupteur du consentement (activé par défaut) : invités et clients connectés doivent cocher la case.
 *
 * @return bool
 */
function oli_acr_consent_required() {
	return 'always' !== oli_acr_get_setting( 'guest_tracking' );
}

/**
 * Le client connecté a-t-il déjà consenti (case cochée, mémorisée dans sa fiche) ?
 *
 * @param int $user_id Utilisateur.
 * @return bool
 */
function oli_acr_user_has_consent( $user_id ) {
	return $user_id > 0 && (bool) get_user_meta( (int) $user_id, OLI_ACR_Carts::USER_CONSENT_META, true );
}

/**
 * Journalise une erreur dans les journaux WooCommerce, même sans WP_DEBUG (WooCommerce > État > Journaux).
 *
 * @param string $message Message.
 * @return void
 */
function oli_acr_log_error( $message ) {
	/**
	 * Active la journalisation des erreurs d'envoi (activée par défaut, même sans WP_DEBUG).
	 *
	 * @param bool $enabled Actif ou non.
	 */
	if ( ! apply_filters( 'oli_acr_log_errors', true ) || ! function_exists( 'wc_get_logger' ) ) {
		return;
	}
	wc_get_logger()->error( $message, array( 'source' => 'oli-abandoned-cart-recovery' ) );
}

/**
 * Mémorise le dernier échec d'envoi pour l'avis de l'administration (jusqu'à ce qu'il soit fermé).
 *
 * @param string $error Cause.
 * @return void
 */
function oli_acr_record_failure( $error ) {
	$failure = get_option( 'oli_acr_mail_failure', array() );
	$count   = is_array( $failure ) && isset( $failure['count'] ) ? (int) $failure['count'] : 0;
	update_option(
		'oli_acr_mail_failure',
		array(
			'count' => $count + 1,
			'time'  => time(),
			'error' => substr( (string) $error, 0, 500 ),
		),
		false
	);
}

/**
 * Adresse IP du client (REMOTE_ADDR ; filtrable derrière un mandataire de confiance).
 *
 * @return string
 */
function oli_acr_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	/**
	 * Filtre l'adresse IP utilisée pour la limite de débit de la capture (ex. en-tête d'un mandataire de confiance).
	 *
	 * @param string $ip Adresse IP.
	 */
	$ip = (string) apply_filters( 'oli_acr_client_ip', $ip );
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
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
