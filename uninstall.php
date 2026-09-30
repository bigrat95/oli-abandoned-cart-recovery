<?php
/**
 * Désinstallation : supprime toutes les données du plugin, sauf si l'option
 * « Garder les données à la désinstallation » est cochée.
 *
 * Supprimé : tables (paniers et journal des courriels), options, capacité, tâches et journaux
 * Action Scheduler (actions, journaux et groupe), événements WP-Cron, fichiers de log WooCommerce
 * du plugin, métadonnées de commandes, d'utilisateurs et de coupons, clés de session WooCommerce
 * (y compris la case de consentement « _wc_other/oli-acr/consent » des sessions), chaînes WPML
 * et coupons générés jamais utilisés.
 *
 * Conservé volontairement : les coupons générés déjà utilisés, car ils sont liés à des commandes
 * (historique comptable). Seul leur marqueur interne est retiré.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$oli_acr_hooks = array( 'oli_acr_process', 'oli_acr_daily_cleanup' );
$oli_acr_group = 'oli-abandoned-cart-recovery';

// Tâches planifiées : retirées dans tous les cas (le code du plugin disparaît).
foreach ( $oli_acr_hooks as $oli_acr_hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $oli_acr_hook );
	}
	wp_clear_scheduled_hook( $oli_acr_hook );
}

$oli_acr_settings = get_option( 'oli_acr_settings', array() );
if ( is_array( $oli_acr_settings ) && isset( $oli_acr_settings['keep_data'] ) && 'yes' === $oli_acr_settings['keep_data'] ) {
	return;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Désinstallation : requêtes directes ponctuelles sur des tables connues, sans cache.

if ( ! function_exists( 'oli_acr_uninstall_table_exists' ) ) {
	/**
	 * Indique si une table existe.
	 *
	 * @param string $table Nom complet.
	 * @return bool
	 */
	function oli_acr_uninstall_table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}

// Action Scheduler : journaux, actions, puis groupe (AS n'est pas toujours chargé ici).
$oli_acr_as_actions = $wpdb->prefix . 'actionscheduler_actions';
$oli_acr_as_logs    = $wpdb->prefix . 'actionscheduler_logs';
$oli_acr_as_groups  = $wpdb->prefix . 'actionscheduler_groups';
if ( oli_acr_uninstall_table_exists( $oli_acr_as_actions ) ) {
	$oli_acr_group_id = oli_acr_uninstall_table_exists( $oli_acr_as_groups ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $oli_acr_as_groups, $oli_acr_group ) ) : 0;
	if ( oli_acr_uninstall_table_exists( $oli_acr_as_logs ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l INNER JOIN %i a ON a.action_id = l.action_id WHERE a.hook IN (%s, %s) OR ( %d > 0 AND a.group_id = %d )', $oli_acr_as_logs, $oli_acr_as_actions, $oli_acr_hooks[0], $oli_acr_hooks[1], $oli_acr_group_id, $oli_acr_group_id ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook IN (%s, %s) OR ( %d > 0 AND group_id = %d )', $oli_acr_as_actions, $oli_acr_hooks[0], $oli_acr_hooks[1], $oli_acr_group_id, $oli_acr_group_id ) );
	if ( $oli_acr_group_id ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $oli_acr_as_groups, $oli_acr_group_id ) );
	}
}

// Tables maison (paniers et journal des courriels).
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'oli_acr_carts' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'oli_acr_log' ) );

// Coupons générés : ceux jamais utilisés sont supprimés ; ceux utilisés restent (liés à des commandes).
$oli_acr_coupons = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_oli_acr_coupon' ) );
foreach ( array_map( 'intval', $oli_acr_coupons ) as $oli_acr_coupon_id ) {
	if ( (int) get_post_meta( $oli_acr_coupon_id, 'usage_count', true ) < 1 ) {
		wp_delete_post( $oli_acr_coupon_id, true );
	}
}

// Métadonnées de commandes (HPOS et tables classiques), d'utilisateurs et de coupons.
$oli_acr_like_private = $wpdb->esc_like( '_oli_acr_' ) . '%';
$oli_acr_like_consent = '%' . $wpdb->esc_like( 'oli-acr/' ) . '%';
$oli_acr_hpos_meta    = $wpdb->prefix . 'wc_orders_meta';
if ( oli_acr_uninstall_table_exists( $oli_acr_hpos_meta ) ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s', $oli_acr_hpos_meta, $oli_acr_like_private, $oli_acr_like_consent ) );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $oli_acr_like_private, $oli_acr_like_consent ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $oli_acr_like_private, $oli_acr_like_consent ) );

// Sessions WooCommerce : retire les clés oli_acr_* des sessions en cours, et la case de consentement du
// checkout en blocs (champ additionnel « oli-acr/consent »), rangée par WooCommerce dans les métadonnées
// du client de la session : session_value['customer'] (sérialisé à part) => meta_data[] => key « _wc_other/oli-acr/consent ».
if ( ! function_exists( 'oli_acr_uninstall_clean_session' ) ) {
	/**
	 * Nettoie une valeur de session WooCommerce (tableau de valeurs sérialisées une à une).
	 *
	 * @param array<string, mixed> $value Session désérialisée.
	 * @return array<string, mixed>
	 */
	function oli_acr_uninstall_clean_session( $value ) {
		foreach ( array_keys( $value ) as $key ) {
			if ( 0 === strpos( (string) $key, 'oli_acr_' ) ) {
				unset( $value[ $key ] );
			}
		}
		foreach ( $value as $key => $item ) {
			$was_serialized = is_string( $item ) && is_serialized( $item );
			$data           = $was_serialized ? maybe_unserialize( $item ) : $item;
			if ( ! is_array( $data ) ) {
				continue;
			}
			$changed = false;
			// Métadonnées du client (champs additionnels du checkout en blocs).
			if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
				foreach ( $data['meta_data'] as $index => $meta ) {
					$meta_key = is_array( $meta ) && isset( $meta['key'] ) ? (string) $meta['key'] : ( is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '' );
					if ( false !== strpos( $meta_key, 'oli-acr/' ) || 0 === strpos( $meta_key, '_oli_acr_' ) ) {
						unset( $data['meta_data'][ $index ] );
						$changed = true;
					}
				}
				if ( $changed ) {
					$data['meta_data'] = array_values( $data['meta_data'] );
				}
			}
			// Valeurs de champs additionnels gardées à plat (autres versions de WooCommerce).
			foreach ( array_keys( $data ) as $data_key ) {
				if ( false !== strpos( (string) $data_key, 'oli-acr/' ) ) {
					unset( $data[ $data_key ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				$value[ $key ] = $was_serialized ? maybe_serialize( $data ) : $data;
			}
		}
		return $value;
	}
}
$oli_acr_sessions = $wpdb->prefix . 'woocommerce_sessions';
if ( oli_acr_uninstall_table_exists( $oli_acr_sessions ) ) {
	$oli_acr_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT session_id, session_value FROM %i WHERE session_value LIKE %s OR session_value LIKE %s', $oli_acr_sessions, '%' . $wpdb->esc_like( 'oli_acr_' ) . '%', '%' . $wpdb->esc_like( 'oli-acr/' ) . '%' ) );
	foreach ( (array) $oli_acr_rows as $oli_acr_row ) {
		$oli_acr_value = maybe_unserialize( $oli_acr_row->session_value );
		if ( ! is_array( $oli_acr_value ) ) {
			continue;
		}
		$oli_acr_clean = oli_acr_uninstall_clean_session( $oli_acr_value );
		if ( $oli_acr_clean !== $oli_acr_value ) {
			$wpdb->update( $oli_acr_sessions, array( 'session_value' => maybe_serialize( $oli_acr_clean ) ), array( 'session_id' => (int) $oli_acr_row->session_id ) );
		}
	}
}

// Chaînes enregistrées dans WPML String Translation (modèles et consentement).
if ( function_exists( 'icl_unregister_string' ) ) {
	$oli_acr_templates = get_option( 'oli_acr_templates', array() );
	foreach ( array_keys( is_array( $oli_acr_templates ) ? $oli_acr_templates : array() ) as $oli_acr_tpl_id ) {
		foreach ( array( 'name', 'subject', 'heading', 'content', 'button_label' ) as $oli_acr_field ) {
			icl_unregister_string( 'oli-abandoned-cart-recovery', 'oli_acr_' . $oli_acr_tpl_id . '_' . $oli_acr_field );
		}
	}
	icl_unregister_string( 'oli-abandoned-cart-recovery', 'oli_acr_consent_text' );
}

// Journaux WooCommerce du plugin (fichiers wc-logs et gestionnaire en base).
$oli_acr_log_dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'wc-logs/';
foreach ( (array) glob( trailingslashit( $oli_acr_log_dir ) . 'oli-abandoned-cart-recovery-*.log' ) as $oli_acr_file ) {
	if ( is_string( $oli_acr_file ) && is_file( $oli_acr_file ) ) {
		wp_delete_file( $oli_acr_file );
	}
}
$oli_acr_wc_log = $wpdb->prefix . 'woocommerce_log';
if ( oli_acr_uninstall_table_exists( $oli_acr_wc_log ) ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s', $oli_acr_wc_log, 'oli-abandoned-cart-recovery' ) );
}
// phpcs:enable

// Options et transitoires.
foreach ( array( 'oli_acr_settings', 'oli_acr_templates', 'oli_acr_blocklist', 'oli_acr_db_version', 'oli_acr_schedule_signature', 'oli_acr_version', 'oli_acr_languages_signature', 'woocommerce_oli_acr_admin_recovered_settings' ) as $oli_acr_option ) {
	delete_option( $oli_acr_option );
}
delete_transient( 'oli_acr_lock' );

// Capacité dédiée.
foreach ( array( 'administrator', 'shop_manager' ) as $oli_acr_role_name ) {
	$oli_acr_role = get_role( $oli_acr_role_name );
	if ( $oli_acr_role ) {
		$oli_acr_role->remove_cap( 'oli_acr_manage' );
	}
}
