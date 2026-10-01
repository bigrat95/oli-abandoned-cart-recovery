<?php
/**
 * Accès aux données des paniers suivis (table maison).
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dépôt des paniers.
 */
class OLI_ACR_Carts {

	/**
	 * Méta utilisateur : consentement donné par un client connecté (horodatage).
	 *
	 * @var string
	 */
	const USER_CONSENT_META = '_oli_acr_consent';

	/**
	 * Statuts d'un panier encore suivi.
	 *
	 * @var string[]
	 */
	const LIVE_STATUSES = array( 'open', 'abandoned', 'reminded', 'failed' );

	/**
	 * Clé de session WooCommerce qui garde l'ID du panier suivi.
	 */
	const SESSION_KEY = 'oli_acr_cart_id';

	/**
	 * Retourne un panier par ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = oli_acr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Retourne un panier par jeton.
	 *
	 * @param string $token Jeton.
	 * @return object|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( 32 !== strlen( $token ) ) {
			return null;
		}
		$table = oli_acr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ) );
	}

	/**
	 * Dernier panier vivant pour un courriel.
	 *
	 * @param string $email Courriel.
	 * @return object|null
	 */
	public static function find_live_by_email( $email ) {
		global $wpdb;
		$table = oli_acr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s AND status IN ('open','abandoned','reminded','failed') ORDER BY id DESC LIMIT 1", strtolower( $email ) ) );
	}

	/**
	 * Dernier panier vivant pour une session WooCommerce.
	 *
	 * @param string $session_key Clé de session.
	 * @return object|null
	 */
	public static function find_live_by_session( $session_key ) {
		global $wpdb;
		if ( '' === (string) $session_key ) {
			return null;
		}
		$table = oli_acr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s AND status IN ('open','abandoned','reminded','failed') ORDER BY id DESC LIMIT 1", (string) $session_key ) );
	}

	/**
	 * Met à jour un panier.
	 *
	 * @param int          $id   ID.
	 * @param array<mixed> $data Colonnes.
	 * @return bool
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( oli_acr_table( 'carts' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Supprime un panier et son journal.
	 *
	 * @param int $id ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( oli_acr_table( 'carts' ), array( 'id' => (int) $id ) );
		$wpdb->delete(
			oli_acr_table( 'log' ),
			array(
				'object_type' => 'cart',
				'object_id'   => (int) $id,
			)
		);
		// phpcs:enable
	}

	/**
	 * Photo du panier WooCommerce courant.
	 *
	 * @return array{items: array<int, array<string, mixed>>, count: int, total: float, hash: string}
	 */
	public static function snapshot() {
		$items = array();
		$count = 0;
		$cart  = WC()->cart;
		if ( $cart ) {
			foreach ( $cart->get_cart() as $item ) {
				$product = isset( $item['data'] ) ? $item['data'] : null;
				$items[] = array(
					'product_id'   => (int) $item['product_id'],
					'variation_id' => (int) $item['variation_id'],
					'variation'    => isset( $item['variation'] ) ? (array) $item['variation'] : array(),
					'quantity'     => (int) $item['quantity'],
					'name'         => $product ? $product->get_name() : '',
					'line_total'   => isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] + (float) $item['line_subtotal_tax'] : 0,
				);
				$count  += (int) $item['quantity'];
			}
		}
		$total = $cart ? (float) $cart->get_total( 'edit' ) : 0;
		if ( $total <= 0 && $cart && $count > 0 ) {
			$cart->calculate_totals();
			$total = (float) $cart->get_total( 'edit' );
		}
		return array(
			'items' => $items,
			'count' => $count,
			'total' => $total,
			'hash'  => md5( wp_json_encode( $items ) ),
		);
	}

	/**
	 * Clé de session WooCommerce courante.
	 *
	 * @return string
	 */
	public static function current_session_key() {
		if ( WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			return (string) WC()->session->get_customer_id();
		}
		return '';
	}

	/**
	 * Crée ou met à jour le panier suivi du visiteur courant.
	 *
	 * @param array<mixed> $contact email, phone, first_name, last_name, consent, user_id.
	 * @return int ID du panier ou 0.
	 */
	public static function upsert_current( $contact ) {
		global $wpdb;

		$email = isset( $contact['email'] ) ? strtolower( sanitize_email( $contact['email'] ) ) : '';
		if ( ! is_email( $email ) || oli_acr_is_unsubscribed( $email ) ) {
			return 0;
		}

		$snap        = self::snapshot();
		$session_key = self::current_session_key();
		$now         = oli_acr_now();
		$existing    = null;

		// 1. Panier déjà lié à la session, 2. même session, 3. même courriel.
		if ( WC()->session ) {
			$sid = absint( WC()->session->get( self::SESSION_KEY ) );
			if ( $sid ) {
				$row = self::get( $sid );
				// Panier d'un autre client connecté : jamais repris par cette session.
				if ( $row && in_array( $row->status, self::LIVE_STATUSES, true ) && in_array( (int) $row->user_id, array( 0, get_current_user_id() ), true ) ) {
					$existing = $row;
				}
			}
		}
		if ( ! $existing ) {
			$existing = self::find_live_by_session( $session_key );
		}
		if ( ! $existing ) {
			$existing = self::find_live_by_email( $email );
		}

		$data = array(
			'session_key'   => $session_key,
			'user_id'       => isset( $contact['user_id'] ) ? absint( $contact['user_id'] ) : get_current_user_id(),
			'email'         => $email,
			'cart_contents' => wp_json_encode( $snap['items'] ),
			'cart_hash'     => $snap['hash'],
			'item_count'    => $snap['count'],
			'cart_total'    => $snap['total'],
			'currency'      => get_woocommerce_currency(),
			'language'      => substr( self::language_for( $contact, $existing ), 0, 20 ),
			'updated_at'    => $now,
		);
		foreach ( array( 'phone', 'first_name', 'last_name' ) as $field ) {
			if ( ! empty( $contact[ $field ] ) ) {
				$data[ $field ] = substr( sanitize_text_field( $contact[ $field ] ), 0, 'phone' === $field ? 40 : 100 );
			}
		}
		if ( isset( $contact['consent'] ) ) {
			$data['consent'] = $contact['consent'] ? 1 : 0;
		}

		if ( $existing ) {
			// Le client revient : le panier redevient actif et la relance est suspendue.
			$data['status']       = 'open';
			$data['next_send_at'] = null;
			self::update( $existing->id, $data );
			$id = (int) $existing->id;
		} else {
			$data['token']      = wp_generate_password( 32, false, false );
			$data['status']     = 'open';
			$data['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( oli_acr_table( 'carts' ), $data );
			$id = (int) $wpdb->insert_id;
		}

		if ( $id && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $id );
			WC()->session->set( 'oli_acr_cart_hash', $snap['hash'] );
		}

		/**
		 * Déclenché après la capture ou la mise à jour d'un panier.
		 *
		 * @param int   $id      ID du panier.
		 * @param array $contact Données de contact.
		 */
		do_action( 'oli_acr_cart_captured', $id, $contact );

		return $id;
	}

	/**
	 * Langue à enregistrer : celle transmise par la page, sinon celle de la page courante (hors appels AJAX),
	 * sinon celle déjà enregistrée, sinon la langue courante.
	 *
	 * @param array<mixed> $contact  Données de contact.
	 * @param object|null  $existing Panier existant.
	 * @return string
	 */
	private static function language_for( $contact, $existing ) {
		if ( ! empty( $contact['language'] ) ) {
			return (string) $contact['language'];
		}
		$ajax = wp_doing_ajax() || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) || isset( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture seule.
		if ( ! $ajax || ! $existing || '' === (string) $existing->language ) {
			return OLI_ACR_Lang::current_language();
		}
		return (string) $existing->language;
	}

	/**
	 * Marque comme désabonnés les paniers vivants d'un courriel.
	 *
	 * @param string $email Courriel.
	 * @return void
	 */
	public static function mark_unsubscribed( $email ) {
		global $wpdb;
		$table = oli_acr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'unsubscribed', next_send_at = NULL WHERE email = %s AND status IN ('open','abandoned','reminded','failed')", strtolower( $email ) ) );
	}

	/**
	 * Nombre de paniers par statut.
	 *
	 * @return array<mixed>
	 */
	public static function count_by_status() {
		global $wpdb;
		$table = oli_acr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );
		$out  = array_fill_keys( array_keys( oli_acr_statuses() ), 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row->status ] = (int) $row->total;
		}
		return $out;
	}

	/**
	 * Articles décodés d'un panier.
	 *
	 * @param object $cart Ligne.
	 * @return array<mixed>
	 */
	public static function items( $cart ) {
		$items = json_decode( (string) $cart->cart_contents, true );
		return is_array( $items ) ? $items : array();
	}
}
