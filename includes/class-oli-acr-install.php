<?php
/**
 * Installation : tables, capacités, réglages et modèles par défaut.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe d'installation.
 */
class OLI_ACR_Install {

	/**
	 * Activation du plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		// Les textes par défaut des modèles sont créés dans chaque langue : il faut les traductions du plugin.
		oli_acr_load_textdomain();
		self::create_tables();
		self::add_caps();
		if ( false === get_option( 'oli_acr_settings' ) ) {
			add_option( 'oli_acr_settings', oli_acr_default_settings(), '', true );
		}
		if ( false === get_option( 'oli_acr_templates' ) ) {
			add_option( 'oli_acr_templates', OLI_ACR_Templates::default_templates(), '', false );
		}
		if ( false === get_option( 'oli_acr_blocklist' ) ) {
			add_option( 'oli_acr_blocklist', array(), '', false );
		}
		self::migrate();
		// La planification est (re)créée au prochain chargement.
		delete_option( 'oli_acr_schedule_signature' );
	}

	/**
	 * Désactivation : on retire les tâches planifiées, on garde les données.
	 *
	 * @return void
	 */
	public static function deactivate() {
		OLI_ACR_Scheduler::unschedule_all();
		delete_option( 'oli_acr_schedule_signature' );
	}

	/**
	 * Mise à jour du schéma si la version a changé.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'oli_acr_db_version' ) !== OLI_ACR_DB_VERSION ) {
			self::create_tables();
			self::add_caps();
		}
		if ( get_option( 'oli_acr_version' ) !== OLI_ACR_VERSION ) {
			self::migrate();
		}
		// Une langue ajoutée au site reçoit les textes par défaut des modèles non modifiés.
		$signature = md5( implode( ',', OLI_ACR_Lang::languages() ) . '|' . OLI_ACR_Lang::adapter()->id() );
		if ( get_option( 'oli_acr_languages_signature' ) !== $signature ) {
			OLI_ACR_Templates::sync_languages();
			update_option( 'oli_acr_languages_signature', $signature, true );
		}
	}

	/**
	 * Migration vers les textes par langue (1.1.0).
	 *
	 * - Modèles : les textes de la 1.0.x deviennent ceux de la langue de repli ; un modèle par défaut
	 *   non modifié reçoit ses textes dans chaque langue active (sync_languages()).
	 * - Consentement : un texte personnalisé devient celui de la langue de repli.
	 * Idempotente : peut être relancée sans effet de bord.
	 *
	 * @return void
	 */
	public static function migrate() {
		$from     = (string) get_option( 'oli_acr_version', '' );
		$settings = get_option( 'oli_acr_settings' );
		if ( is_array( $settings ) ) {
			// R2 (Loi 25, RGPD) : une installation 1.0.x en mode « toujours » repasse au consentement (interrupteur activé).
			if ( ( '' === $from || version_compare( $from, '1.1.0', '<' ) ) && isset( $settings['guest_tracking'] ) && 'always' === $settings['guest_tracking'] ) {
				$settings['guest_tracking'] = 'consent';
				update_option( 'oli_acr_notice_consent_migrated', time(), true );
			}
			$texts    = isset( $settings['consent_texts'] ) && is_array( $settings['consent_texts'] ) ? $settings['consent_texts'] : array();
			$fallback = OLI_ACR_Lang::fallback_language();
			$legacy   = isset( $settings['consent_text'] ) ? (string) $settings['consent_text'] : '';
			if ( '' !== trim( $legacy ) && ! oli_acr_is_default_consent_text( $legacy ) && empty( $texts[ $fallback ] ) ) {
				$texts[ $fallback ] = $legacy;
			}
			$settings['consent_texts'] = $texts;
			if ( ! isset( $settings['fallback_language'] ) ) {
				$settings['fallback_language'] = '';
			}
			update_option( 'oli_acr_settings', $settings, true );
		}
		OLI_ACR_Templates::sync_languages();
		update_option( 'oli_acr_version', OLI_ACR_VERSION, true );
		// R8 : options lues à chaque page, chargées d'avance (les installations 1.0.x les avaient en autoload=off).
		wp_set_option_autoload_values(
			array(
				'oli_acr_settings'            => true,
				'oli_acr_db_version'          => true,
				'oli_acr_version'             => true,
				'oli_acr_languages_signature' => true,
				'oli_acr_schedule_signature'  => true,
			)
		);
		// Réglages de l'avis « vente récupérée » (WC_Email) : WooCommerce les lit sur les pages qui chargent les courriels.
		if ( false === get_option( 'woocommerce_oli_acr_admin_recovered_settings' ) ) {
			add_option( 'woocommerce_oli_acr_admin_recovered_settings', array(), '', true );
		}
		// Ancien verrou (transient) remplacé par le verrou atomique « oli_acr_process_lock ».
		delete_transient( 'oli_acr_lock' );
	}

	/**
	 * Crée ou met à jour les tables avec dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$carts   = oli_acr_table( 'carts' );
		$log     = oli_acr_table( 'log' );

		$sql = "CREATE TABLE {$carts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token char(32) NOT NULL,
  session_key varchar(100) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  cart_contents longtext NULL,
  cart_hash char(32) NOT NULL DEFAULT '',
  item_count int(11) unsigned NOT NULL DEFAULT 0,
  cart_total decimal(19,4) NOT NULL DEFAULT 0,
  currency varchar(10) NOT NULL DEFAULT '',
  language varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  consent tinyint(1) NOT NULL DEFAULT 0,
  emails_sent smallint(5) unsigned NOT NULL DEFAULT 0,
  fail_count smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error text NULL,
  sent_templates varchar(255) NOT NULL DEFAULT '',
  next_send_at datetime NULL DEFAULT NULL,
  last_email_at datetime NULL DEFAULT NULL,
  clicked_at datetime NULL DEFAULT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  recovered_via varchar(20) NOT NULL DEFAULT '',
  recovered_total decimal(19,4) NOT NULL DEFAULT 0,
  recovered_at datetime NULL DEFAULT NULL,
  abandoned_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY session_key (session_key),
  KEY email (email),
  KEY user_id (user_id),
  KEY status_updated (status,updated_at),
  KEY status_next (status,next_send_at)
) {$collate};
CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(10) NOT NULL DEFAULT 'cart',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  template_id varchar(40) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  coupon_code varchar(100) NOT NULL DEFAULT '',
  sent_at datetime NOT NULL,
  clicked_at datetime NULL DEFAULT NULL,
  recovered_at datetime NULL DEFAULT NULL,
  recovered_total decimal(19,4) NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY object (object_type,object_id),
  KEY template_id (template_id),
  KEY email (email),
  KEY sent_at (sent_at)
) {$collate};";

		dbDelta( $sql );
		update_option( 'oli_acr_db_version', OLI_ACR_DB_VERSION, true );
	}

	/**
	 * Ajoute la capacité dédiée aux administrateurs (et au shop_manager si permis).
	 *
	 * @return void
	 */
	public static function add_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( OLI_ACR_CAP );
		}
		self::sync_shop_manager_cap();
	}

	/**
	 * Synchronise la capacité du rôle shop_manager avec le réglage.
	 *
	 * @return void
	 */
	public static function sync_shop_manager_cap() {
		$role = get_role( 'shop_manager' );
		if ( ! $role ) {
			return;
		}
		if ( 'yes' === oli_acr_get_setting( 'shop_manager_access' ) ) {
			$role->add_cap( OLI_ACR_CAP );
		} else {
			$role->remove_cap( OLI_ACR_CAP );
		}
	}
}
