<?php
/**
 * Tâches planifiées : détection des paniers abandonnés, relances en séquence,
 * commandes en attente et nettoyage. Action Scheduler, ou WP-Cron en secours.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Planificateur.
 */
class OLI_ACR_Scheduler {

	const HOOK_PROCESS = 'oli_acr_process';
	const HOOK_DAILY   = 'oli_acr_daily_cleanup';
	const GROUP        = 'oli-abandoned-cart-recovery';
	const LOCK_OPTION  = 'oli_acr_process_lock';

	/**
	 * Jeton et échéance du verrou détenu par ce processus.
	 *
	 * @var array{token: string, expires: int}|null
	 */
	private static $lock = null;

	/**
	 * Début du passage en cours (budget de temps).
	 *
	 * @var int
	 */
	private static $started = 0;

	/**
	 * Accroches.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK_PROCESS, array( __CLASS__, 'process' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'daily_cleanup' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		// Sans HPOS, wc_get_orders() ignore « meta_query » : la condition est ajoutée à la requête WP_Query.
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( __CLASS__, 'cpt_orders_query' ), 10, 2 );
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
	 * @param array<mixed> $schedules Intervalles.
	 * @return array<mixed>
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
	 *
	 * @return void
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
		update_option( 'oli_acr_schedule_signature', $signature, true );
	}

	/**
	 * Retire toutes les tâches planifiées.
	 *
	 * @return void
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
	 * Durée de vie du verrou en secondes : un passage bloqué (processus tué) le libère après ce délai.
	 *
	 * @return int
	 */
	public static function lock_ttl() {
		/**
		 * Filtre la durée de vie du verrou du traitement récurrent (10 minutes par défaut).
		 *
		 * @param int $ttl Secondes.
		 */
		return max( 60, (int) apply_filters( 'oli_acr_lock_ttl', 10 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Prend le verrou (atomique : INSERT IGNORE, puis comparer-et-remplacer s'il est expiré).
	 *
	 * @return bool
	 */
	public static function acquire_lock() {
		global $wpdb;
		$token   = wp_generate_password( 20, false, false );
		$expires = time() + self::lock_ttl();
		$value   = $token . '|' . $expires;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verrou atomique : le cache d'options ne doit pas intervenir.
		$inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $value ) );
		if ( ! $inserted ) {
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
			$parts   = explode( '|', $current );
			if ( isset( $parts[1] ) && (int) $parts[1] > time() ) {
				// phpcs:enable
				return false;
			}
			// Verrou expiré : un seul processus peut le remplacer.
			$inserted = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, $current ) );
		}
		// phpcs:enable
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		if ( ! $inserted ) {
			return false;
		}
		self::$lock = array(
			'token'   => $token,
			'expires' => $expires,
		);
		return true;
	}

	/**
	 * Renouvelle le verrou s'il a consommé la moitié de sa durée de vie.
	 *
	 * @return bool Faux si le verrou a été perdu.
	 */
	public static function renew_lock() {
		global $wpdb;
		if ( ! self::$lock ) {
			return false;
		}
		if ( self::$lock['expires'] - time() > self::lock_ttl() / 2 ) {
			return true;
		}
		$old     = self::$lock['token'] . '|' . self::$lock['expires'];
		$expires = time() + self::lock_ttl();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verrou atomique.
		$ok = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", self::$lock['token'] . '|' . $expires, self::LOCK_OPTION, $old ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		if ( $ok ) {
			self::$lock['expires'] = $expires;
			return true;
		}
		self::$lock = null;
		return false;
	}

	/**
	 * Libère le verrou s'il nous appartient encore.
	 *
	 * @return void
	 */
	public static function release_lock() {
		global $wpdb;
		if ( ! self::$lock ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verrou atomique.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, self::$lock['token'] . '|' . self::$lock['expires'] ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		self::$lock = null;
	}

	/**
	 * Vrai tant que le passage peut continuer : verrou encore détenu et budget de temps non épuisé.
	 *
	 * @return bool
	 */
	public static function can_continue() {
		if ( ! self::$lock ) {
			// Appel direct hors du traitement récurrent (tests, outils) : pas de verrou à surveiller.
			return true;
		}
		/**
		 * Filtre le budget de temps d'un passage, en secondes (par défaut : la moitié de la durée du verrou).
		 *
		 * @param int $budget Secondes.
		 */
		$budget = (int) apply_filters( 'oli_acr_time_budget', (int) floor( self::lock_ttl() / 2 ) );
		if ( self::$started && time() - self::$started >= $budget ) {
			return false;
		}
		return self::renew_lock();
	}

	/**
	 * Traitement récurrent, protégé contre les passages simultanés.
	 *
	 * @return void
	 */
	public static function process() {
		if ( ! self::acquire_lock() ) {
			return;
		}
		self::$started = time();
		try {
			if ( 'yes' === oli_acr_get_setting( 'enabled' ) ) {
				self::mark_abandoned();
				self::send_due_carts();
			}
			if ( 'yes' === oli_acr_get_setting( 'pending_enabled' ) && self::can_continue() ) {
				self::send_due_orders();
			}
		} finally {
			self::release_lock();
			self::$started = 0;
		}
	}

	/**
	 * Délais entre les nouveaux essais après un échec d'envoi (secondes). Un essai par délai.
	 *
	 * @return int[]
	 */
	public static function retry_delays() {
		/**
		 * Filtre les délais des nouveaux essais après un échec d'envoi (5 min, 30 min, 2 h par défaut).
		 *
		 * @param int[] $delays Secondes.
		 */
		$delays = (array) apply_filters( 'oli_acr_retry_delays', array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS ) );
		return array_values( array_filter( array_map( 'absint', $delays ) ) );
	}

	/**
	 * Échec d'envoi d'une relance de panier : statut « failed », nouvel essai plus tard ou abandon définitif.
	 *
	 * @param object $cart   Panier.
	 * @param string $tpl_id Modèle.
	 * @return string|null Prochain essai (UTC) ou null.
	 */
	public static function cart_failed( $cart, $tpl_id ) {
		$count  = (int) ( isset( $cart->fail_count ) ? $cart->fail_count : 0 ) + 1;
		$delays = self::retry_delays();
		$next   = isset( $delays[ $count - 1 ] ) ? gmdate( 'Y-m-d H:i:s', time() + $delays[ $count - 1 ] ) : null;
		$error  = OLI_ACR_Mailer::last_error();
		OLI_ACR_Carts::update(
			$cart->id,
			array(
				'status'       => 'failed',
				'fail_count'   => $count,
				'last_error'   => substr( $error, 0, 1000 ),
				'next_send_at' => $next,
			)
		);
		oli_acr_log_error(
			sprintf(
				/* translators: 1: cart ID, 2: template ID, 3: attempt number, 4: error message, 5: next attempt or "none". */
				__( 'Reminder for cart #%1$d (template %2$s) could not be sent, attempt %3$d: %4$s. Next attempt: %5$s.', 'oli-abandoned-cart-recovery' ),
				(int) $cart->id,
				$tpl_id,
				$count,
				$error,
				$next ? $next . ' UTC' : __( 'none (gave up)', 'oli-abandoned-cart-recovery' )
			)
		);
		oli_acr_record_failure( $error );
		return $next;
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('abandoned','reminded','failed') AND next_send_at IS NOT NULL AND next_send_at <= %s ORDER BY next_send_at ASC LIMIT %d", $now, self::batch_size() ) );
		$sent = 0;

		foreach ( (array) $rows as $cart ) {
			if ( ! self::can_continue() ) {
				break;
			}
			// Réclame le panier : un autre passage ne peut plus le relire pendant l'envoi (R4).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table(), jamais une saisie.
			$claimed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_send_at = %s WHERE id = %d AND next_send_at = %s", oli_acr_now( self::lock_ttl() ), (int) $cart->id, (string) $cart->next_send_at ) );
			if ( ! $claimed ) {
				continue;
			}
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
				OLI_ACR_Carts::update( $cart->id, array( 'next_send_at' => $next_at ) );
			} else {
				// R1 : nouvel essai avec un délai croissant, puis abandon définitif (statut « failed »).
				self::cart_failed( $cart, $next['id'] );
			}
		}
		return $sent;
	}

	/**
	 * Premier modèle non encore envoyé.
	 *
	 * @param array<mixed> $templates Modèles triés.
	 * @param array<mixed> $done      IDs déjà envoyés.
	 * @return array<mixed>|null
	 */
	public static function next_template( $templates, $done ) {
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
	public static function cart_owner_tracked( $cart ) {
		$user_id = (int) $cart->user_id;
		if ( ! oli_acr_user_is_tracked( $user_id ) ) {
			return false;
		}
		// Consentement requis : invité, case cochée (colonne consent) ; client connecté, consentement mémorisé (R10).
		// Les paniers de clients connectés captés sans case avant la 1.1.0 ne sont donc plus relancés.
		if ( oli_acr_consent_required() ) {
			return $user_id ? oli_acr_user_has_consent( $user_id ) && (int) $cart->consent : (bool) (int) $cart->consent;
		}
		return true;
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
			self::orders_query(
				array(
					'status'       => 'pending',
					'limit'        => self::batch_size(),
					'orderby'      => 'date',
					'order'        => 'ASC',
					'date_created' => '<' . ( time() - $base_delay ),
				),
				'_oli_acr_done',
				'NOT EXISTS'
			)
		);
		$sent       = 0;
		foreach ( $orders as $order ) {
			if ( ! self::can_continue() ) {
				break;
			}
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
			// Nouvel essai après un échec : on attend la fin du délai.
			$retry_at = (int) $order->get_meta( '_oli_acr_retry_at' );
			if ( $retry_at && $retry_at > time() ) {
				continue;
			}
			if ( OLI_ACR_Mailer::send_order_email( $order, $next['id'], $next ) ) {
				++$sent;
				if ( $order->get_meta( '_oli_acr_fail_count' ) ) {
					$order->delete_meta_data( '_oli_acr_fail_count' );
					$order->delete_meta_data( '_oli_acr_retry_at' );
					$order->save();
				}
			} else {
				self::order_failed( $order, $next['id'] );
			}
		}
		return $sent;
	}

	/**
	 * Échec d'envoi d'une relance de commande : nouvel essai plus tard, puis abandon (« _oli_acr_done »).
	 *
	 * @param WC_Order $order  Commande.
	 * @param string   $tpl_id Modèle.
	 * @return void
	 */
	public static function order_failed( $order, $tpl_id ) {
		$count  = (int) $order->get_meta( '_oli_acr_fail_count' ) + 1;
		$delays = self::retry_delays();
		$error  = OLI_ACR_Mailer::last_error();
		$order->update_meta_data( '_oli_acr_fail_count', $count );
		if ( isset( $delays[ $count - 1 ] ) ) {
			$next = time() + $delays[ $count - 1 ];
			$order->update_meta_data( '_oli_acr_retry_at', $next );
		} else {
			$next = 0;
			$order->delete_meta_data( '_oli_acr_retry_at' );
			$order->update_meta_data( '_oli_acr_done', 1 );
		}
		$order->save();
		oli_acr_log_error(
			sprintf(
				/* translators: 1: order ID, 2: template ID, 3: attempt number, 4: error message, 5: next attempt or "none". */
				__( 'Reminder for pending order #%1$d (template %2$s) could not be sent, attempt %3$d: %4$s. Next attempt: %5$s.', 'oli-abandoned-cart-recovery' ),
				$order->get_id(),
				$tpl_id,
				$count,
				$error,
				$next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : __( 'none (gave up)', 'oli-abandoned-cart-recovery' )
			)
		);
		oli_acr_record_failure( $error );
	}

	/**
	 * Arguments de wc_get_orders() avec une condition sur une méta, compatibles HPOS et stockage par articles.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param string               $key     Clé de méta.
	 * @param string               $compare EXISTS ou NOT EXISTS.
	 * @return array<string, mixed>
	 */
	public static function orders_query( $args, $key, $compare ) {
		$clause = array(
			'key'     => $key,
			'compare' => $compare,
		);
		if ( self::hpos_enabled() ) {
			$args['meta_query'] = array( $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Requête bornée par statut, date et limite.
		} else {
			// Variable maison, traduite en meta_query WP_Query par cpt_orders_query() (R3).
			$args['oli_acr_meta'] = $clause;
		}
		return $args;
	}

	/**
	 * Stockage des commandes HPOS actif ?
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Sans HPOS : ajoute la condition « oli_acr_meta » à la requête WP_Query des commandes.
	 *
	 * @param array<string, mixed> $query      Arguments WP_Query.
	 * @param array<string, mixed> $query_vars Variables de wc_get_orders().
	 * @return array<string, mixed>
	 */
	public static function cpt_orders_query( $query, $query_vars ) {
		if ( ! empty( $query_vars['oli_acr_meta'] ) && is_array( $query_vars['oli_acr_meta'] ) ) {
			$meta                = isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ? $query['meta_query'] : array();
			$meta[]              = $query_vars['oli_acr_meta'];
			$query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Requête bornée par statut, date et limite.
		}
		return $query;
	}

	/**
	 * Nettoyage quotidien : vieux paniers, commandes en attente suivies, coupons, conservation.
	 *
	 * @return void
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$carts} WHERE status IN ('open','abandoned','reminded','failed') AND updated_at <= %s LIMIT 1000", oli_acr_now( -1 * $after ) ) );
			foreach ( $ids as $id ) {
				OLI_ACR_Carts::delete( $id );
				++$deleted;
			}
		}

		$days = absint( oli_acr_get_setting( 'retention_days' ) );
		if ( $days > 0 ) {
			$limit = oli_acr_now( -1 * $days * DAY_IN_SECONDS );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Noms de table fixes (oli_acr_table()), valeurs passées par prepare().
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
			self::orders_query(
				array(
					'status'       => 'pending',
					'limit'        => 100,
					'date_created' => '<' . ( time() - $after ),
				),
				'_oli_acr_sent',
				'EXISTS'
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
