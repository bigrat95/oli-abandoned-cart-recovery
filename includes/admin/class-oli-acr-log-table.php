<?php
/**
 * Tableau d'admin (WP_List_Table) : journal des courriels.
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
 * Journal des courriels.
 */
class OLI_ACR_Log_Table extends WP_List_Table {

	/**
	 * Constructeur.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'oli_acr_log',
				'plural'   => 'oli_acr_logs',
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
			'sent_at'      => __( 'Sent', 'oli-abandoned-cart-recovery' ),
			'email'        => __( 'Email', 'oli-abandoned-cart-recovery' ),
			'template_id'  => __( 'Template', 'oli-abandoned-cart-recovery' ),
			'object_type'  => __( 'Type', 'oli-abandoned-cart-recovery' ),
			'coupon_code'  => __( 'Coupon', 'oli-abandoned-cart-recovery' ),
			'clicked_at'   => __( 'Clicked', 'oli-abandoned-cart-recovery' ),
			'recovered_at' => __( 'Recovered', 'oli-abandoned-cart-recovery' ),
		);
	}

	/**
	 * Prépare les lignes.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;
		$table    = oli_acr_table( 'log' );
		$per_page = 30;
		$paged    = $this->get_pagenum();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Noms de table fixes (oli_acr_table()), valeurs passées par prepare().
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ) );
		// phpcs:enable
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Colonnes par défaut.
	 *
	 * @param object $item        Ligne.
	 * @param string $column_name Colonne.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'sent_at':
			case 'clicked_at':
				return oli_acr_admin_date( $item->$column_name );
			case 'recovered_at':
				if ( empty( $item->recovered_at ) ) {
					return '—';
				}
				return oli_acr_admin_date( $item->recovered_at ) . '<br>' . wp_kses_post( wc_price( (float) $item->recovered_total ) );
			case 'email':
				return esc_html( $item->email );
			case 'template_id':
				$tpl = OLI_ACR_Templates::get( $item->template_id );
				return esc_html( $tpl ? $tpl['name'] : $item->template_id );
			case 'object_type':
				return 'order' === $item->object_type ? esc_html__( 'Pending order', 'oli-abandoned-cart-recovery' ) : esc_html__( 'Abandoned cart', 'oli-abandoned-cart-recovery' );
			case 'coupon_code':
				return $item->coupon_code ? '<code>' . esc_html( $item->coupon_code ) . '</code>' : '—';
		}
		return '';
	}

	/**
	 * Message vide.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No email sent yet.', 'oli-abandoned-cart-recovery' );
	}
}
