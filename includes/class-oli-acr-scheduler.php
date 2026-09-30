<?php
/**
 * Tâches planifiées : détection des paniers abandonnés, relances en séquence,
 * commandes en attente et nettoyage. Action Scheduler, ou WP-Cron en secours.
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Planificateur.
 */
class OLI_ACR_Scheduler {

	const HOOK_PROCESS = 'oli_acr_process';
	const HOOK_DAILY   = 'oli_acr_daily_cleanup';
	const GROUP        = 'oli-abandoned-cart-recovery';

	/**
	 * Accroches.
	 */
	public static function init() {
		add_action( self::HOOK_PROCESS, array( __CLASS__, 'process' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'daily_cleanup' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
	}

	/**
	 * Intervalle du traitement en secondes (minimum 1 minute).
	 *
	 * @return int
	 */
	public static function interval() {
		return max( MINUTE_IN_SECONDS, oli_acr_duration_to_seconds( oli_acr_get_setting( 'cron_interval' ) ) );
	}

	/**
	 * Intervalle WP-Cron maison (secours).
	 *
	 * @param array $schedules Intervalles.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['oli_acr_interval'] = array(
			'interval' => self::interval(),
			'display'  => __( 'Oli Abandoned Cart Recovery interval', 'oli-abandoned-cart-recovery' ),
		);
		return $schedules;
	}

	/**
	 * Planifie les tâches si la signature (intervalle + moteur) a changé. Aucun coût au front sinon.
	 */
	public static function maybe_schedule() {
		$engine    = function_exists( 'as_schedule_recurring_action' ) ? 'as' : 'wpcron';
		$signature = $engine . ':' . self::interval();
		if ( get_option( 'oli_acr_schedule_signature' ) === $signature ) {
			return;
		}
		self::unschedule_all();
		if ( 'as' === $engine ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, self::interval(), self::HOOK_PROCESS, array(), self::GROUP );
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_DAILY, array(), self::GROUP );
		} else {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'oli_acr_interval', self::HOOK_PROCESS );
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
		}
		update_option( 'oli_acr_schedule_signature', $signature );
	}

	/**
	 * Retire toutes les tâches planifiées.
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PROCESS, array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK_DAILY, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK_PROCESS );
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	/**
	 * Taille des lots.
	 *
	 * @return int
	 */
	public static function batch_size() {
		/**
		 * Filtre la taille des lots traités à chaque passage.
		 *
		 * @param int $size Taille.
		 */
		return max( 1, (int) apply_filters( 'oli_acr_batch_size', 50 ) );
	}

	/**
	 * Traitement récurrent.
	 */
	public static function process() {
		if ( get_transient( 'oli_acr_lock' ) ) {
			return;
		}
		set_transient( 'oli_acr_lock', 1, 5 * MINUTE_IN_SECONDS );

		if ( 'yes' === oli_acr_get_setting( 'enabled' ) ) {
			self::mark_abandoned();
			self::send_due_carts();
		}
		if ( 'yes' === oli_acr_get_setting( 'pending_enabled' ) ) {
			self::send_due_orders();
		}

		delete_transient( 'oli_acr_lock' );
	}

	/**
	 * Passe les paniers actifs inactifs depuis le délai en « abandonné » (une requête indexée).
	 *
	 * @return int Nombre de paniers.
	 */
	public static function mark_abandoned() {
		global $wpdb;
		$table  = oli_acr_table( 'carts' );
		$cutoff = oli_acr_now( -1 * oli_acr_duration_to_seconds( oli_acr_get_setting( 'abandon_after' ) ) );
		$now    = oli_acr_now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'abandoned', abandoned_at = %s, next_send_at = %s WHERE status = 'open' AND updated_at <= %s AND item_count > 0 AND order_id = 0 AND email <> '' LIMIT 500", $now, $now, $cutoff ) );
	}

	/**
	 * Envoie les relances de panier dues, par lots.
	 *
	 * @return int Nombre de courriels envoyés.
	 */
	public static function send_due_carts() {
		global $wpdb;
		$templates = OLI_ACR_Templates::active( 'cart' );
		if ( empty( $templates ) ) {
			return 0;
		}
		$table = oli_acr_table( 'carts' );
		$now   = oli_acr_now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('abandoned','reminded') AND next_send_at IS NOT NULL AND next_send_at <= %s ORDER BY next_send_at ASC LIMIT %d", $now, self::batch_size() ) );
		$sent = 0;

		foreach ( (array) $rows as $cart ) {
			if ( ! self::cart_owner_tracked( $cart ) || oli_acr_is_unsubscribed( $cart->email ) ) {
				OLI_ACR_Carts::update( $cart->id, array( 'next_send_at' => null ) );
				continue;
			}
			$done = array_filter( explode( ',', (string) $cart->sent_templates ) );
			$base = strtotime( $cart->abandoned_at . ' UTC' );
			$next = self::next_template( $templates, $done );
			if ( ! $next ) {
				OLI_ACR_Carts::update( $cart->id, array( 'next_send_at' => null ) );
				continue;
			}
			$due = $base + oli_acr_duration_to_seconds( $next['delay'] );
			if ( $due > time() ) {
				OLI_ACR_Carts::update( $cart->id, array( 'next_send_at' => gmdate( 'Y-m-d H:i:s', $due ) ) );
				continue;
			}
			if ( OLI_ACR_Mailer::send_cart_email( $cart, $next['id'], $next ) ) {
				++$sent;
				$done[]    = $next['id'];
				$following = self::next_template( $templates, $done );
				$next_at   = $following ? gmdate( 'Y-m-d H:i:s', max( time() + 60, $base + oli_acr_duration_to_seconds( $following['delay'] ) ) ) : null;
			} else {
				$next_at = null;
			}
			OLI_ACR_Carts::update( $cart->id, array( 'next_send_at' => $next_at ) );
		}
		return $sent;
	}

	/**
	 * Premier modèle non encore envoyé.
	 *
	 * @param array $templates Modèles triés.
	 * @param array $done      IDs déjà envoyés.
	 * @return array|null
	 */
	private static function next_template( $templates, $done ) {
		foreach ( $templates as $id => $tpl ) {
			if ( ! in_array( $id, $done, true ) ) {
				$tpl['id'] = $id;
				return $tpl;
			}
		}
		return null;
	}

	/**
	 * Vérifie le rôle du propriétaire d'un panier.
	 *
	 * @param object $cart Panier.
	 * @return bool
	 */
	private static function cart_owner_tracked( $cart ) {
		return oli_acr_user_is_tracked( (int) $cart->user_id );
	}

	/**
	 * Relance les commandes en attente de paiement.
	 *
	 * @return int
	 */
	public static function send_due_orders() {
		$templates = OLI_ACR_Templates::active( 'order' );
		if ( empty( $templates ) ) {
			return 0;
		}
		$base_delay = oli_acr_duration_to_seconds( oli_acr_get_setting( 'pending_after' ) );
		$orders     = wc_get_orders(
			array(
				'status'       => 'pending',
				'limit'        => self::batch_size(),
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '<' . ( time() - $base_delay ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Requête bornée par statut, date et limite.
					array(
						'key'     => '_oli_acr_done',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$sent       = 0;
		foreach ( $orders as $order ) {
			/**
			 * Filtre les canaux de création de commande suivis.
			 *
			 * @param string[] $channels Canaux.
			 */
			$channels = apply_filters( 'oli_acr_pending_order_channels', array( 'checkout', 'store-api' ) );
			$email    = $order->get_billing_email();
			if ( ! in_array( $order->get_created_via(), $channels, true ) || ! is_email( $email ) || oli_acr_is_unsubscribed( $email ) || ! oli_acr_user_is_tracked( (int) $order->get_customer_id() ) ) {
				$order->update_meta_data( '_oli_acr_done', 1 );
				$order->save();
				continue;
			}
			$done = array_filter( (array) $order->get_meta( '_oli_acr_sent' ) );
			$next = self::next_template( $templates, $done );
			if ( ! $next ) {
				$order->update_meta_data( '_oli_acr_done', 1 );
				$order->save();
				continue;
			}
			$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
			$due     = $created + $base_delay + oli_acr_duration_to_seconds( $next['delay'] );
			if ( $due > time() ) {
				continue;
			}
			// Une seule relance par commande et par passage, avec au moins un intervalle entre deux.
			$last = (int) $order->get_meta( '_oli_acr_last_sent' );
			if ( $last && ( time() - $last ) < self::interval() - 5 ) {
				continue;
			}
			if ( OLI_ACR_Mailer::send_order_email( $order, $next['id'], $next ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Nettoyage quotidien : vieux paniers, commandes en attente suivies, coupons, conservation.
	 */
	public static function daily_cleanup() {
		self::cleanup_carts();
		self::cleanup_pending_orders();
		self::cleanup_coupons();
	}

	/**
	 * Supprime les vieux paniers non récupérés et applique la durée de conservation.
	 *
	 * @return int
	 */
	public static function cleanup_carts() {
		global $wpdb;
		$carts   = oli_acr_table( 'carts' );
		$log     = oli_acr_table( 'log' );
		$deleted = 0;

		$after = oli_acr_duration_to_seconds( oli_acr_get_setting( 'delete_carts_after' ) );
		if ( $after > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$carts} WHERE status IN ('open','abandoned','reminded') AND updated_at <= %s LIMIT 1000", oli_acr_now( -1 * $after ) ) );
			foreach ( $ids as $id ) {
				OLI_ACR_Carts::delete( $id );
				++$deleted;
			}
		}

		$days = absint( oli_acr_get_setting( 'retention_days' ) );
		if ( $days > 0 ) {
			$limit = oli_acr_now( -1 * $days * DAY_IN_SECONDS );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$carts} WHERE updated_at <= %s LIMIT 1000", $limit ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE sent_at <= %s LIMIT 5000", $limit ) );
			// phpcs:enable
		}
		return $deleted;
	}

	/**
	 * Annule (ou met à la corbeille) les commandes en attente relancées trop vieilles.
	 *
	 * @return int
	 */
	public static function cleanup_pending_orders() {
		$after = oli_acr_duration_to_seconds( oli_acr_get_setting( 'pending_cancel_after' ) );
		if ( $after <= 0 ) {
			return 0;
		}
		$orders = wc_get_orders(
			array(
				'status'       => 'pending',
				'limit'        => 100,
				'date_created' => '<' . ( time() - $after ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Requête bornée par statut, date et limite.
					array(
						'key'     => '_oli_acr_sent',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $orders as $order ) {
			$order->update_status( 'cancelled', __( 'Pending order cancelled automatically by Oli Abandoned Cart Recovery (too old).', 'oli-abandoned-cart-recovery' ) );
		}
		return count( $orders );
	}

	/**
	 * Supprime (corbeille) les coupons générés utilisés ou expirés.
	 *
	 * @return int
	 */
	public static function cleanup_coupons() {
		$used    = 'yes' === oli_acr_get_setting( 'coupon_delete_used' );
		$expired = 'yes' === oli_acr_get_setting( 'coupon_delete_expired' );
		if ( ! $used && ! $expired ) {
			return 0;
		}
		$ids   = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => '_oli_acr_coupon', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Tâche quotidienne, bornée.
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Tâche quotidienne, bornée.
			)
		);
		$count = 0;
		foreach ( $ids as $id ) {
			$coupon  = new WC_Coupon( $id );
			$expires = $coupon->get_date_expires();
			if ( ( $used && $coupon->get_usage_count() > 0 ) || ( $expired && $expires && $expires->getTimestamp() < time() ) ) {
				wp_trash_post( $id );
				++$count;
			}
		}
		return $count;
	}
}
