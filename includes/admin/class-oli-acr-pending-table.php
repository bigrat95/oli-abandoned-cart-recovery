<?php
/**
 * Tableau d'admin (WP_List_Table) : liste des commandes en attente suivies.
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Liste des commandes en attente suivies.
 */
class OLI_ACR_Pending_Table extends WP_List_Table {

	/**
	 * Constructeur.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'oli_acr_order',
				'plural'   => 'oli_acr_orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Colonnes.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'order'  => __( 'Order', 'oli-abandoned-cart-recovery' ),
			'email'  => __( 'Customer', 'oli-abandoned-cart-recovery' ),
			'total'  => __( 'Total', 'oli-abandoned-cart-recovery' ),
			'date'   => __( 'Created', 'oli-abandoned-cart-recovery' ),
			'emails' => __( 'Emails sent', 'oli-abandoned-cart-recovery' ),
		);
	}

	/**
	 * Prépare les lignes.
	 */
	public function prepare_items() {
		$per_page              = 20;
		$result                = wc_get_orders(
			array(
				'status'   => 'pending',
				'limit'    => $per_page,
				'page'     => $this->get_pagenum(),
				'paginate' => true,
				'orderby'  => 'date',
				'order'    => 'DESC',
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
				$actions = array(
					'send' => '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oli_acr_order_action&order=' . $order->get_id() ), 'oli_acr_order_action' ) ) . '">' . esc_html__( 'Send next reminder now', 'oli-abandoned-cart-recovery' ) . '</a>',
				);
				return '<a href="' . esc_url( $order->get_edit_order_url() ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></a>' . $this->row_actions( $actions );
			case 'email':
				return esc_html( $order->get_billing_email() ) . '<br>' . esc_html( $order->get_formatted_billing_full_name() );
			case 'total':
				return wp_kses_post( $order->get_formatted_order_total() );
			case 'date':
				return $order->get_date_created() ? esc_html( wc_format_datetime( $order->get_date_created(), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '—';
			case 'emails':
				$sent = array_filter( (array) $order->get_meta( '_oli_acr_sent' ) );
				return esc_html( (string) count( $sent ) );
		}
		return '';
	}

	/**
	 * Message vide.
	 */
	public function no_items() {
		esc_html_e( 'No pending orders.', 'oli-abandoned-cart-recovery' );
	}
}
