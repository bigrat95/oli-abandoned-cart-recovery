<?php
/**
 * Vie privée : exporteur, effaceur et texte suggéré pour la politique de confidentialité.
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vie privée.
 */
class OLI_ACR_Privacy {

	/**
	 * Accroches.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
	}

	/**
	 * Enregistre l'exporteur.
	 *
	 * @param array $exporters Exporteurs.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['oli-abandoned-cart-recovery'] = array(
			'exporter_friendly_name' => __( 'Abandoned carts', 'oli-abandoned-cart-recovery' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Enregistre l'effaceur.
	 *
	 * @param array $erasers Effaceurs.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['oli-abandoned-cart-recovery'] = array(
			'eraser_friendly_name' => __( 'Abandoned carts', 'oli-abandoned-cart-recovery' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporte les paniers et le journal d'un courriel.
	 *
	 * @param string $email Courriel.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$email  = strtolower( $email );
		$table  = oli_acr_table( 'carts' );
		$log    = oli_acr_table( 'log' );
		$items  = array();
		$limit  = 50;
		$offset = ( max( 1, (int) $page ) - 1 ) * $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, $limit, $offset ) );
		foreach ( (array) $rows as $row ) {
			$products = array();
			foreach ( OLI_ACR_Carts::items( $row ) as $item ) {
				$products[] = $item['name'] . ' × ' . $item['quantity'];
			}
			$items[] = array(
				'group_id'    => 'oli-acr-carts',
				'group_label' => __( 'Abandoned carts', 'oli-abandoned-cart-recovery' ),
				'item_id'     => 'oli-acr-cart-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Email', 'oli-abandoned-cart-recovery' ),
						'value' => $row->email,
					),
					array(
						'name'  => __( 'Phone', 'oli-abandoned-cart-recovery' ),
						'value' => $row->phone,
					),
					array(
						'name'  => __( 'Name', 'oli-abandoned-cart-recovery' ),
						'value' => trim( $row->first_name . ' ' . $row->last_name ),
					),
					array(
						'name'  => __( 'Products', 'oli-abandoned-cart-recovery' ),
						'value' => implode( ', ', $products ),
					),
					array(
						'name'  => __( 'Total', 'oli-abandoned-cart-recovery' ),
						'value' => $row->cart_total . ' ' . $row->currency,
					),
					array(
						'name'  => __( 'Status', 'oli-abandoned-cart-recovery' ),
						'value' => $row->status,
					),
					array(
						'name'  => __( 'Date', 'oli-abandoned-cart-recovery' ),
						'value' => $row->created_at . ' UTC',
					),
				),
			);
		}

		if ( 1 === (int) $page ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$log} WHERE email = %s ORDER BY id ASC LIMIT 500", $email ) );
			foreach ( (array) $logs as $row ) {
				$items[] = array(
					'group_id'    => 'oli-acr-emails',
					'group_label' => __( 'Cart reminder emails', 'oli-abandoned-cart-recovery' ),
					'item_id'     => 'oli-acr-log-' . $row->id,
					'data'        => array(
						array(
							'name'  => __( 'Sent', 'oli-abandoned-cart-recovery' ),
							'value' => $row->sent_at . ' UTC',
						),
						array(
							'name'  => __( 'Clicked', 'oli-abandoned-cart-recovery' ),
							'value' => $row->clicked_at ? $row->clicked_at . ' UTC' : '—',
						),
						array(
							'name'  => __( 'Coupon', 'oli-abandoned-cart-recovery' ),
							'value' => $row->coupon_code,
						),
					),
				);
			}
			if ( oli_acr_is_unsubscribed( $email ) ) {
				$items[] = array(
					'group_id'    => 'oli-acr-emails',
					'group_label' => __( 'Cart reminder emails', 'oli-abandoned-cart-recovery' ),
					'item_id'     => 'oli-acr-unsub',
					'data'        => array(
						array(
							'name'  => __( 'Unsubscribed', 'oli-abandoned-cart-recovery' ),
							'value' => __( 'Yes', 'oli-abandoned-cart-recovery' ),
						),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => count( (array) $rows ) < $limit,
		);
	}

	/**
	 * Efface les paniers et le journal d'un courriel. La liste d'exclusion est conservée
	 * (sous forme de courriel) pour respecter le refus de recevoir des relances.
	 *
	 * @param string $email Courriel.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature imposée par WordPress.
		global $wpdb;
		$email = strtolower( $email );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$carts = (int) $wpdb->delete( oli_acr_table( 'carts' ), array( 'email' => $email ) );
		$logs  = (int) $wpdb->delete( oli_acr_table( 'log' ), array( 'email' => $email ) );
		// phpcs:enable
		$messages = array();
		if ( oli_acr_is_unsubscribed( $email ) ) {
			$messages[] = __( 'The email address was kept in the unsubscribe list so no reminder is ever sent again.', 'oli-abandoned-cart-recovery' );
		}
		return array(
			'items_removed'  => ( $carts + $logs ) > 0,
			'items_retained' => oli_acr_is_unsubscribed( $email ),
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Texte suggéré pour la politique de confidentialité.
	 */
	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<h2>' . esc_html__( 'Abandoned cart reminders', 'oli-abandoned-cart-recovery' ) . '</h2>';
		$content .= '<p>' . esc_html__( 'When you type your email address (and optionally your phone number and name) on our checkout page, we save it with the content of your cart, even if you do not complete the order. We use this information only to send you a limited number of reminder emails about your cart or unpaid order, sometimes with a single-use discount code.', 'oli-abandoned-cart-recovery' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Every reminder contains an unsubscribe link. Carts are deleted automatically after the retention period set by the store, and reminders stop as soon as an order is placed. You can ask us to export or erase this data at any time.', 'oli-abandoned-cart-recovery' ) . '</p>';
		wp_add_privacy_policy_content( 'Oli Abandoned Cart Recovery', wp_kses_post( $content ) );
	}
}
