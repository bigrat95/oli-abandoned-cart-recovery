<?php
/**
 * Modèles de courriels de relance (plusieurs, en séquence).
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestion des modèles, stockés dans l'option oli_acr_templates.
 */
class OLI_ACR_Templates {

	/**
	 * Tous les modèles.
	 *
	 * @return array
	 */
	public static function all() {
		$templates = get_option( 'oli_acr_templates', array() );
		if ( ! is_array( $templates ) ) {
			return array();
		}
		foreach ( $templates as $id => $tpl ) {
			$templates[ $id ] = wp_parse_args( $tpl, self::blank() );
		}
		return $templates;
	}

	/**
	 * Un modèle.
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Modèles actifs d'un type, triés par délai croissant.
	 *
	 * @param string $type cart ou order.
	 * @return array
	 */
	public static function active( $type = 'cart' ) {
		$list = array();
		foreach ( self::all() as $id => $tpl ) {
			if ( 'yes' === $tpl['active'] && $type === $tpl['type'] ) {
				$list[ $id ] = $tpl;
			}
		}
		uasort(
			$list,
			static function ( $a, $b ) {
				return oli_acr_duration_to_seconds( $a['delay'] ) <=> oli_acr_duration_to_seconds( $b['delay'] );
			}
		);
		return $list;
	}

	/**
	 * Modèle vide.
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'id'              => '',
			'name'            => '',
			'type'            => 'cart',
			'active'          => 'no',
			'delay'           => array(
				'value' => 1,
				'unit'  => 'hours',
			),
			'subject'         => '',
			'heading'         => '',
			'reply_to'        => '',
			'content'         => '',
			'button_label'    => __( 'Complete my order', 'oli-abandoned-cart-recovery' ),
			'coupon_enabled'  => 'no',
			'coupon_type'     => 'percent',
			'coupon_amount'   => 10,
			'coupon_validity' => 7,
		);
	}

	/**
	 * Enregistre un modèle (création si ID vide).
	 *
	 * @param array $tpl Modèle nettoyé.
	 * @return string ID.
	 */
	public static function save( $tpl ) {
		$all = self::all();
		if ( empty( $tpl['id'] ) ) {
			$tpl['id'] = 'tpl_' . strtolower( wp_generate_password( 8, false, false ) );
		}
		$all[ $tpl['id'] ] = $tpl;
		update_option( 'oli_acr_templates', $all, false );
		self::reset_schedule();
		return $tpl['id'];
	}

	/**
	 * Supprime un modèle.
	 *
	 * @param string $id ID.
	 */
	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( 'oli_acr_templates', $all, false );
		self::reset_schedule();
	}

	/**
	 * Après un changement de modèles, les paniers en attente sont réévalués au prochain passage.
	 */
	public static function reset_schedule() {
		global $wpdb;
		$table = oli_acr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET next_send_at = abandoned_at WHERE status IN ('abandoned','reminded') AND abandoned_at IS NOT NULL" );
	}

	/**
	 * Nettoie un modèle soumis par formulaire.
	 *
	 * @param array $raw Données brutes (déjà wp_unslash).
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$tpl                    = self::blank();
		$tpl['id']              = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
		$tpl['name']            = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
		$tpl['type']            = ( isset( $raw['type'] ) && 'order' === $raw['type'] ) ? 'order' : 'cart';
		$tpl['active']          = ! empty( $raw['active'] ) ? 'yes' : 'no';
		$tpl['delay']           = oli_acr_sanitize_duration( isset( $raw['delay'] ) ? $raw['delay'] : array() );
		$tpl['subject']         = isset( $raw['subject'] ) ? sanitize_text_field( $raw['subject'] ) : '';
		$tpl['heading']         = isset( $raw['heading'] ) ? sanitize_text_field( $raw['heading'] ) : '';
		$tpl['reply_to']        = isset( $raw['reply_to'] ) ? sanitize_email( $raw['reply_to'] ) : '';
		$tpl['content']         = isset( $raw['content'] ) ? wp_kses_post( $raw['content'] ) : '';
		$tpl['button_label']    = isset( $raw['button_label'] ) ? sanitize_text_field( $raw['button_label'] ) : '';
		$tpl['coupon_enabled']  = ! empty( $raw['coupon_enabled'] ) ? 'yes' : 'no';
		$tpl['coupon_type']     = ( isset( $raw['coupon_type'] ) && 'fixed_cart' === $raw['coupon_type'] ) ? 'fixed_cart' : 'percent';
		$tpl['coupon_amount']   = isset( $raw['coupon_amount'] ) ? (float) wc_format_decimal( $raw['coupon_amount'] ) : 0;
		$tpl['coupon_validity'] = isset( $raw['coupon_validity'] ) ? absint( $raw['coupon_validity'] ) : 0;
		return $tpl;
	}

	/**
	 * Modèles par défaut : 2 relances de panier et 1 relance de commande en attente.
	 *
	 * @return array
	 */
	public static function default_templates() {
		$first  = array_merge(
			self::blank(),
			array(
				'id'      => 'tpl_cart_1',
				'name'    => __( 'Cart reminder #1', 'oli-abandoned-cart-recovery' ),
				'type'    => 'cart',
				'active'  => 'yes',
				'delay'   => array(
					'value' => 1,
					'unit'  => 'hours',
				),
				'subject' => __( 'You left something in your cart', 'oli-abandoned-cart-recovery' ),
				'heading' => __( 'Your cart is waiting for you', 'oli-abandoned-cart-recovery' ),
				'content' => '<p>' . __( 'Hi {first_name},', 'oli-abandoned-cart-recovery' ) . '</p><p>' . __( 'Looks like you got interrupted. We saved the items in your cart:', 'oli-abandoned-cart-recovery' ) . '</p>{cart_items}<p style="text-align:center">{recovery_button}</p><p>' . __( 'Thank you,', 'oli-abandoned-cart-recovery' ) . '<br>{site_name}</p>',
			)
		);
		$second = array_merge(
			self::blank(),
			array(
				'id'             => 'tpl_cart_2',
				'name'           => __( 'Cart reminder #2 (coupon)', 'oli-abandoned-cart-recovery' ),
				'type'           => 'cart',
				'active'         => 'no',
				'delay'          => array(
					'value' => 1,
					'unit'  => 'days',
				),
				'subject'        => __( 'A little something to complete your order', 'oli-abandoned-cart-recovery' ),
				'heading'        => __( 'Here is 10% off your cart', 'oli-abandoned-cart-recovery' ),
				'content'        => '<p>' . __( 'Hi {first_name},', 'oli-abandoned-cart-recovery' ) . '</p><p>' . __( 'Your items are still available. Use this single-use code at checkout:', 'oli-abandoned-cart-recovery' ) . '</p>{coupon}{cart_items}<p style="text-align:center">{recovery_button}</p>',
				'coupon_enabled' => 'yes',
			)
		);
		$order  = array_merge(
			self::blank(),
			array(
				'id'           => 'tpl_order_1',
				'name'         => __( 'Pending order reminder', 'oli-abandoned-cart-recovery' ),
				'type'         => 'order',
				'active'       => 'no',
				'delay'        => array(
					'value' => 0,
					'unit'  => 'minutes',
				),
				'subject'      => __( 'Your order #{order_number} is awaiting payment', 'oli-abandoned-cart-recovery' ),
				'heading'      => __( 'Complete your payment', 'oli-abandoned-cart-recovery' ),
				'content'      => '<p>' . __( 'Hi {first_name},', 'oli-abandoned-cart-recovery' ) . '</p><p>' . __( 'Your order #{order_number} was not paid yet. You can complete the payment here:', 'oli-abandoned-cart-recovery' ) . '</p>{cart_items}<p style="text-align:center">{recovery_button}</p>',
				'button_label' => __( 'Pay for my order', 'oli-abandoned-cart-recovery' ),
			)
		);
		return array(
			$first['id']  => $first,
			$second['id'] => $second,
			$order['id']  => $order,
		);
	}
}
