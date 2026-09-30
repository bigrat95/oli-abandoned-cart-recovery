<?php
/**
 * Tableau d'admin (WP_List_Table) : liste des commandes récupérées.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Liste des commandes récupérées.
 */
class OLI_ACR_Recovered_Table extends WP_List_Table {

	/**
	 * Constructeur.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'oli_acr_recovered',
				'plural'   => 'oli_acr_recovered',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Colonnes.
	 *
	 * @return array<mixed>
	 */
	public function get_columns() {
		return array(
			'order'  => __( 'Order', 'oli-abandoned-cart-recovery' ),
			'type'   => __( 'Recovered from', 'oli-abandoned-cart-recovery' ),
			'email'  => __( 'Customer', 'oli-abandoned-cart-recovery' ),
			'total'  => __( 'Total', 'oli-abandoned-cart-recovery' ),
			'status' => __( 'Order status', 'oli-abandoned-cart-recovery' ),
			'date'   => __( 'Date', 'oli-abandoned-cart-recovery' ),
		);
	}

	/**
	 * Prépare les lignes.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page              = 20;
		$result                = wc_get_orders(
			array(
				'limit'      => $per_page,
				'page'       => $this->get_pagenum(),
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Liste paginée d'admin.
					array(
						'key'     => '_oli_acr_recovered',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$this->items           = $result->orders;
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $result->total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Colonnes par défaut.
	 *
	 * @param WC_Order $order       Commande.
	 * @param string   $column_name Colonne.
	 * @return string
	 */
	protected function column_default( $order, $column_name ) {
		switch ( $column_name ) {
			case 'order':
				return '<a href="' . esc_url( $order->get_edit_order_url() ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></a>';
			case 'type':
				return 'order' === $order->get_meta( '_oli_acr_recovered' ) ? esc_html__( 'Pending order', 'oli-abandoned-cart-recovery' ) : esc_html__( 'Abandoned cart', 'oli-abandoned-cart-recovery' );
			case 'email':
				return '<span class="oli-acr-email">' . esc_html( $order->get_billing_email() ) . '</span>';
			case 'total':
				return wp_kses_post( $order->get_formatted_order_total() );
			case 'status':
				return esc_html( wc_get_order_status_name( $order->get_status() ) );
			case 'date':
				return $order->get_date_created() ? esc_html( wc_format_datetime( $order->get_date_created(), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '—';
		}
		return '';
	}

	/**
	 * Message vide.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No recovered orders yet.', 'oli-abandoned-cart-recovery' );
	}
}
