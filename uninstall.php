<?php
/**
 * Désinstallation : supprime les tables, les options, les capacités, les tâches planifiées
 * et les métadonnées de commande et de coupon ajoutées par le plugin.
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Tâches planifiées (Action Scheduler et WP-Cron).
foreach ( array( 'oli_acr_process', 'oli_acr_daily_cleanup' ) as $oli_acr_hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $oli_acr_hook );
	}
	wp_clear_scheduled_hook( $oli_acr_hook );
}
// Action Scheduler peut ne pas être chargé pendant la désinstallation : nettoyage direct de ses tables.
$oli_acr_as_table = $wpdb->prefix . 'actionscheduler_actions';
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $oli_acr_as_table ) ) === $oli_acr_as_table ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$oli_acr_as_table} WHERE hook IN (%s, %s)", 'oli_acr_process', 'oli_acr_daily_cleanup' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Tables maison.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}oli_acr_carts" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}oli_acr_log" );

// Métadonnées de commandes (HPOS et tables classiques).
$oli_acr_meta_keys = array( '_oli_acr_cart_id', '_oli_acr_sent', '_oli_acr_last_sent', '_oli_acr_done', '_oli_acr_clicked_log', '_oli_acr_recovered' );
$oli_acr_in        = implode( ',', array_fill( 0, count( $oli_acr_meta_keys ), '%s' ) );
$oli_acr_hpos_meta = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $oli_acr_hpos_meta ) ) === $oli_acr_hpos_meta ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$oli_acr_hpos_meta} WHERE meta_key IN ({$oli_acr_in})", $oli_acr_meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$oli_acr_in})", $oli_acr_meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// Les coupons générés restent (ils peuvent être en cours d'utilisation), seul le marqueur est retiré.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_oli_acr_coupon' ) );
// phpcs:enable

// Options.
foreach ( array( 'oli_acr_settings', 'oli_acr_templates', 'oli_acr_blocklist', 'oli_acr_db_version', 'oli_acr_schedule_signature', 'woocommerce_oli_acr_admin_recovered_settings' ) as $oli_acr_option ) {
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
