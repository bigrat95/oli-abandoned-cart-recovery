<?php
/**
 * Tableau d'admin (WP_List_Table) : liste des paniers suivis.
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Liste des paniers suivis.
 */
class OLI_ACR_Carts_Table extends WP_List_Table {

	/**
	 * Constructeur.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'oli_acr_cart',
				'plural'   => 'oli_acr_carts',
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
			'cb'          => '<input type="checkbox" />',
			'email'       => __( 'Customer', 'oli-abandoned-cart-recovery' ),
			'status'      => __( 'Status', 'oli-abandoned-cart-recovery' ),
			'items'       => __( 'Products', 'oli-abandoned-cart-recovery' ),
			'cart_total'  => __( 'Total', 'oli-abandoned-cart-recovery' ),
			'emails_sent' => __( 'Emails sent', 'oli-abandoned-cart-recovery' ),
			'updated_at'  => __( 'Last activity', 'oli-abandoned-cart-recovery' ),
			'order_id'    => __( 'Order', 'oli-abandoned-cart-recovery' ),
		);
	}

	/**
	 * Colonnes triables.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'email'      => array( 'email', false ),
			'cart_total' => array( 'cart_total', false ),
			'updated_at' => array( 'updated_at', true ),
		);
	}

	/**
	 * Actions groupées.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'oli-abandoned-cart-recovery' ) );
	}

	/**
	 * Vues par statut.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = OLI_ACR_Carts::count_by_status();
		$current = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base    = admin_url( 'admin.php?page=oli-acr&tab=carts' );
		$views   = array(
			'all' => sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $base ), '' === $current ? ' class="current"' : '', esc_html__( 'All', 'oli-abandoned-cart-recovery' ), array_sum( $counts ) ),
		);
		foreach ( oli_acr_statuses() as $key => $label ) {
			$views[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'status', $key, $base ) ), $current === $key ? ' class="current"' : '', esc_html( $label ), (int) $counts[ $key ] );
		}
		return $views;
	}

	/**
	 * Prépare les lignes.
	 */
	public function prepare_items() {
		global $wpdb;
		$table    = oli_acr_table( 'carts' );
		$per_page = 20;
		$paged    = $this->get_pagenum();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'email', 'cart_total', 'updated_at' ), true ) ? sanitize_key( $_GET['orderby'] ) : 'updated_at';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'ASC' : 'DESC';
		// phpcs:enable

		$where  = '1=1';
		$params = array();
		if ( $status && array_key_exists( $status, oli_acr_statuses() ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$where .= ' AND (email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$params = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		$params_count = $params;
		$params[]     = $per_page;
		$params[]     = ( $paged - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where ne contient que des marqueurs %s construits ci-dessus.
		$total       = (int) ( $params_count ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params_count ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $params ) );
		// phpcs:enable

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Case à cocher.
	 *
	 * @param object $item Ligne.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="cart_ids[]" value="' . esc_attr( $item->id ) . '" />';
	}

	/**
	 * Colonne client.
	 *
	 * @param object $item Ligne.
	 * @return string
	 */
	protected function column_email( $item ) {
		$out  = '<strong>' . esc_html( $item->email ) . '</strong>';
		$name = trim( $item->first_name . ' ' . $item->last_name );
		if ( $name ) {
			$out .= '<br>' . esc_html( $name );
		}
		if ( $item->phone ) {
			$out .= '<br>' . esc_html( $item->phone );
		}
		if ( $item->user_id ) {
			$out .= '<br><em>' . esc_html__( 'Registered customer', 'oli-abandoned-cart-recovery' ) . '</em>';
		}
		$actions = array();
		if ( in_array( $item->status, array( 'abandoned', 'reminded', 'open' ), true ) ) {
			$actions['send'] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oli_acr_cart_action&do=send&cart=' . $item->id ), 'oli_acr_cart_action' ) ) . '">' . esc_html__( 'Send next reminder now', 'oli-abandoned-cart-recovery' ) . '</a>';
		}
		$actions['delete'] = '<a class="submitdelete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oli_acr_cart_action&do=delete&cart=' . $item->id ), 'oli_acr_cart_action' ) ) . '">' . esc_html__( 'Delete', 'oli-abandoned-cart-recovery' ) . '</a>';
		return $out . $this->row_actions( $actions );
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
			case 'status':
				$labels = oli_acr_statuses();
				$label  = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;
				$extra  = ( 'recovered' === $item->status && 'direct' === $item->recovered_via ) ? '<br><small>' . esc_html__( '(ordered without reminder)', 'oli-abandoned-cart-recovery' ) . '</small>' : '';
				return '<mark class="oli-acr-status oli-acr-status-' . esc_attr( $item->status ) . '">' . esc_html( $label ) . '</mark>' . $extra;
			case 'items':
				$lines = array();
				foreach ( OLI_ACR_Carts::items( $item ) as $line ) {
					$lines[] = esc_html( $line['name'] . ' × ' . $line['quantity'] );
				}
				return implode( '<br>', $lines );
			case 'cart_total':
				return wp_kses_post( wc_price( (float) $item->cart_total, array( 'currency' => $item->currency ) ) );
			case 'emails_sent':
				return esc_html( (string) (int) $item->emails_sent ) . ( $item->last_email_at ? '<br><small>' . oli_acr_admin_date( $item->last_email_at ) . '</small>' : '' );
			case 'updated_at':
				return oli_acr_admin_date( $item->updated_at );
			case 'order_id':
				if ( ! $item->order_id ) {
					return '—';
				}
				$order = wc_get_order( $item->order_id );
				return $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . esc_html( (string) $item->order_id );
		}
		return '';
	}

	/**
	 * Message vide.
	 */
	public function no_items() {
		esc_html_e( 'No carts captured yet.', 'oli-abandoned-cart-recovery' );
	}
}
