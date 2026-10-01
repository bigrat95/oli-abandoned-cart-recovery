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
		// Versions minimales (WordPress les vérifie déjà avec les en-têtes Requires at least / Requires PHP) :
		// message clair au lieu d'une erreur fatale si l'activation passe quand même.
		if ( ! oli_acr_requirements_met() ) {
			deactivate_plugins( OLI_ACR_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
						__( 'Oli Abandoned Cart Recovery requires PHP %1$s and WordPress %2$s or later. The plugin is inactive until the site is updated.', 'oli-abandoned-cart-recovery' ),
						OLI_ACR_MIN_PHP,
						OLI_ACR_MIN_WP
					)
				),
				esc_html__( 'Plugin could not be activated', 'oli-abandoned-cart-recovery' ),
				array( 'back_link' => true )
			);
		}
		// Sans WooCommerce (WordPress 6.4 n'applique pas l'en-tête Requires Plugins), l'activation reste
		// sans erreur : rien ici n'utilise WooCommerce, le plugin attend WooCommerce et affiche un avis
		// sur l'écran des extensions. Aucune requête HTTP externe et aucune sortie à l'activation.
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
		$legacy   = '' === $from || version_compare( $from, '1.1.0', '<' );
		if ( $legacy ) {
			self::reset_legacy_consent();
		}
		if ( is_array( $settings ) ) {
			// R2 (Loi 25, RGPD) : une installation 1.0.x en mode « toujours » repasse au consentement (interrupteur activé).
			if ( $legacy && isset( $settings['guest_tracking'] ) && 'always' === $settings['guest_tracking'] ) {
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
		self::set_autoload(
			array(
				'oli_acr_settings',
				'oli_acr_db_version',
				'oli_acr_version',
				'oli_acr_languages_signature',
				'oli_acr_schedule_signature',
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
	 * Met des options en autoload, quelle que soit la version de WordPress.
	 *
	 * La fonction wp_set_option_autoload_values() n'existe pas dans toutes les versions : on l'utilise seulement si
	 * elle est disponible, sinon wp_set_option_autoload(), sinon une mise à jour directe de la colonne autoload.
	 *
	 * @param string[] $names Noms des options.
	 * @return void
	 */
	public static function set_autoload( $names ) {
		$names = array_values( array_filter( array_map( 'strval', (array) $names ) ) );
		if ( array() === $names ) {
			return;
		}
		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array_fill_keys( $names, true ) );
			return;
		}
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			foreach ( $names as $name ) {
				wp_set_option_autoload( $name, true );
			}
			return;
		}
		global $wpdb;
		foreach ( $names as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repli pour les anciennes versions ; caches vidés ci-dessous.
			$wpdb->update( $wpdb->options, array( 'autoload' => 'yes' ), array( 'option_name' => $name ) );
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * B1 et N1 (Loi 25) : la 1.0.x enregistrait consent=1 sans case cochée pour les invités en mode « toujours »
	 * et pour tous les clients connectés, et ne gardait pas la source du consentement. Un site passé du mode
	 * « toujours » au mode consentement AVANT la mise à jour garde donc des paniers invités sans consentement
	 * explicite, impossibles à distinguer des autres. Par prudence, tous les paniers 1.0.x (invités et clients
	 * connectés) passent à consent=0 : ils ne sont plus relancés tant que le client n'a pas coché la case
	 * dans la 1.1.0. Les relances de commandes en attente ne dépendent pas de cette colonne.
	 *
	 * @return void
	 */
	public static function reset_legacy_consent() {
		global $wpdb;
		$table = oli_acr_table( 'carts' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table(), jamais une saisie ; migration ponctuelle.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}
		$wpdb->query( "UPDATE {$table} SET consent = 0 WHERE consent = 1" );
		// phpcs:enable
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
