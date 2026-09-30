<?php
/**
 * Interface d'administration sous le menu WooCommerce.
 *
 * @package OliAbandonedCartRecovery
 */

defined( 'ABSPATH' ) || exit;

require_once OLI_ACR_DIR . 'includes/admin/class-oli-acr-carts-table.php';
require_once OLI_ACR_DIR . 'includes/admin/class-oli-acr-pending-table.php';
require_once OLI_ACR_DIR . 'includes/admin/class-oli-acr-recovered-table.php';
require_once OLI_ACR_DIR . 'includes/admin/class-oli-acr-log-table.php';

/**
 * Admin.
 */
class OLI_ACR_Admin {

	const SLUG = 'oli-acr';

	/**
	 * Accroches.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_oli_acr_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_oli_acr_save_template', array( __CLASS__, 'save_template' ) );
		add_action( 'admin_post_oli_acr_delete_template', array( __CLASS__, 'delete_template' ) );
		add_action( 'admin_post_oli_acr_test_email', array( __CLASS__, 'test_email' ) );
		add_action( 'admin_post_oli_acr_cart_action', array( __CLASS__, 'cart_action' ) );
		add_action( 'admin_post_oli_acr_order_action', array( __CLASS__, 'order_action' ) );
		add_filter( 'plugin_action_links_' . OLI_ACR_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Lien « Réglages » dans la liste des extensions.
	 *
	 * @param array $links Liens.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'oli-abandoned-cart-recovery' ) . '</a>' );
		return $links;
	}

	/**
	 * URL d'un onglet.
	 *
	 * @param string $tab  Onglet.
	 * @param array  $args Arguments.
	 * @return string
	 */
	public static function url( $tab = 'dashboard', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Sous-menu WooCommerce.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Abandoned Carts', 'oli-abandoned-cart-recovery' ), __( 'Abandoned Carts', 'oli-abandoned-cart-recovery' ), OLI_ACR_CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Styles d'admin.
	 *
	 * @param string $hook Écran.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'oli-acr-admin', OLI_ACR_URL . 'assets/css/admin.css', array(), OLI_ACR_VERSION );
	}

	/**
	 * Onglets.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'dashboard' => __( 'Dashboard & reports', 'oli-abandoned-cart-recovery' ),
			'carts'     => __( 'Abandoned carts', 'oli-abandoned-cart-recovery' ),
			'pending'   => __( 'Pending orders', 'oli-abandoned-cart-recovery' ),
			'recovered' => __( 'Recovered', 'oli-abandoned-cart-recovery' ),
			'log'       => __( 'Email log', 'oli-abandoned-cart-recovery' ),
			'templates' => __( 'Email templates', 'oli-abandoned-cart-recovery' ),
			'settings'  => __( 'Settings', 'oli-abandoned-cart-recovery' ),
		);
	}

	/**
	 * Vérifie la capacité.
	 */
	private static function check_cap() {
		if ( ! current_user_can( OLI_ACR_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'oli-abandoned-cart-recovery' ), 403 );
		}
	}

	/**
	 * Redirection avec message.
	 *
	 * @param string $tab  Onglet.
	 * @param string $msg  Code du message.
	 * @param array  $args Arguments.
	 */
	private static function redirect( $tab, $msg, $args = array() ) {
		wp_safe_redirect( self::url( $tab, array_merge( $args, array( 'oli_acr_msg' => $msg ) ) ) );
		exit;
	}

	/**
	 * Affiche la page.
	 */
	public static function render() {
		self::check_cap();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		if ( ! array_key_exists( $tab, self::tabs() ) ) {
			$tab = 'dashboard';
		}
		$msg = isset( $_GET['oli_acr_msg'] ) ? sanitize_key( $_GET['oli_acr_msg'] ) : '';
		// phpcs:enable

		// Actions groupées des paniers.
		if ( 'carts' === $tab ) {
			self::maybe_bulk_delete();
		}

		echo '<div class="wrap oli-acr-wrap">';
		echo '<h1 class="oli-acr-title"><img src="' . esc_url( OLI_ACR_URL . 'assets/images/icon.svg' ) . '" alt="" width="36" height="36"> ' . esc_html__( 'Oli Abandoned Cart Recovery', 'oli-abandoned-cart-recovery' ) . '</h1>';
		self::notice( $msg );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'carts':
				self::render_carts();
				break;
			case 'pending':
				self::render_pending();
				break;
			case 'recovered':
				$table = new OLI_ACR_Recovered_Table();
				$table->prepare_items();
				$table->display();
				break;
			case 'log':
				$table = new OLI_ACR_Log_Table();
				$table->prepare_items();
				$table->display();
				break;
			case 'templates':
				self::render_templates();
				break;
			case 'settings':
				self::render_settings();
				break;
			default:
				self::render_dashboard();
		}
		echo '</div>';
	}

	/**
	 * Messages.
	 *
	 * @param string $msg Code.
	 */
	private static function notice( $msg ) {
		$messages = array(
			'saved'       => array( 'success', __( 'Settings saved.', 'oli-abandoned-cart-recovery' ) ),
			'tpl_saved'   => array( 'success', __( 'Template saved.', 'oli-abandoned-cart-recovery' ) ),
			'tpl_deleted' => array( 'success', __( 'Template deleted.', 'oli-abandoned-cart-recovery' ) ),
			'test_sent'   => array( 'success', __( 'Test email sent.', 'oli-abandoned-cart-recovery' ) ),
			'test_failed' => array( 'error', __( 'The test email could not be sent. Check the address and your mail settings.', 'oli-abandoned-cart-recovery' ) ),
			'sent'        => array( 'success', __( 'Reminder sent.', 'oli-abandoned-cart-recovery' ) ),
			'not_sent'    => array( 'warning', __( 'No reminder was sent (no active template left, or the customer unsubscribed).', 'oli-abandoned-cart-recovery' ) ),
			'deleted'     => array( 'success', __( 'Cart(s) deleted.', 'oli-abandoned-cart-recovery' ) ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
		}
	}

	/**
	 * Suppression groupée.
	 */
	private static function maybe_bulk_delete() {
		$action = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] ) {
			$action = 'delete';
		} elseif ( isset( $_GET['action2'] ) && 'delete' === $_GET['action2'] ) {
			$action = 'delete';
		}
		// phpcs:enable
		if ( 'delete' !== $action || empty( $_GET['cart_ids'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-oli_acr_carts' );
		$ids = array_map( 'absint', (array) wp_unslash( $_GET['cart_ids'] ) );
		foreach ( $ids as $id ) {
			OLI_ACR_Carts::delete( $id );
		}
		echo '<script>window.history.replaceState(null, "", ' . wp_json_encode( self::url( 'carts' ) ) . ');</script>';
		self::notice( 'deleted' );
	}

	/**
	 * Onglet paniers.
	 */
	private static function render_carts() {
		$table = new OLI_ACR_Carts_Table();
		$table->prepare_items();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="carts">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['status'] ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( $_GET['status'] ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->views();
		$table->search_box( __( 'Search carts', 'oli-abandoned-cart-recovery' ), 'oli-acr-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Onglet commandes en attente.
	 */
	private static function render_pending() {
		if ( 'yes' !== oli_acr_get_setting( 'pending_enabled' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Pending order recovery is disabled. Enable it in the Settings tab to send reminders automatically.', 'oli-abandoned-cart-recovery' ) . '</p></div>';
		}
		$table = new OLI_ACR_Pending_Table();
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Statistiques pour une période.
	 *
	 * @param int $days Nombre de jours (0 = tout).
	 * @return array
	 */
	public static function stats( $days ) {
		global $wpdb;
		$carts = oli_acr_table( 'carts' );
		$log   = oli_acr_table( 'log' );
		$since = $days > 0 ? oli_acr_now( -1 * $days * DAY_IN_SECONDS ) : '1970-01-01 00:00:00';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$abandoned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$carts} WHERE abandoned_at >= %s", $since ) );
		$emails    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS sent, SUM(clicked_at IS NOT NULL) AS clicked FROM {$log} WHERE sent_at >= %s", $since ) );
		$pending   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT object_id) FROM {$log} WHERE object_type = 'order' AND sent_at >= %s", $since ) );
		$recovered = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, COUNT(DISTINCT order_id) AS total, SUM(recovered_total) AS amount FROM {$log} WHERE recovered_at >= %s GROUP BY object_type", $since ), OBJECT_K );
		// phpcs:enable

		$rec_carts  = isset( $recovered['cart'] ) ? (int) $recovered['cart']->total : 0;
		$rec_orders = isset( $recovered['order'] ) ? (int) $recovered['order']->total : 0;
		$amount     = ( isset( $recovered['cart'] ) ? (float) $recovered['cart']->amount : 0 ) + ( isset( $recovered['order'] ) ? (float) $recovered['order']->amount : 0 );
		$base       = $abandoned + $pending;

		return array(
			'abandoned'        => $abandoned,
			'pending'          => $pending,
			'sent'             => (int) $emails->sent,
			'clicked'          => (int) $emails->clicked,
			'recovered_carts'  => $rec_carts,
			'recovered_orders' => $rec_orders,
			'amount'           => $amount,
			'click_rate'       => $emails->sent ? round( 100 * $emails->clicked / $emails->sent, 1 ) : 0,
			'recovery_rate'    => $base ? round( 100 * ( $rec_carts + $rec_orders ) / $base, 1 ) : 0,
		);
	}

	/**
	 * Onglet tableau de bord et rapports.
	 */
	private static function render_dashboard() {
		global $wpdb;
		$periods = array(
			7   => __( 'Last 7 days', 'oli-abandoned-cart-recovery' ),
			30  => __( 'Last 30 days', 'oli-abandoned-cart-recovery' ),
			90  => __( 'Last 90 days', 'oli-abandoned-cart-recovery' ),
			365 => __( 'Last 12 months', 'oli-abandoned-cart-recovery' ),
			0   => __( 'All time', 'oli-abandoned-cart-recovery' ),
		);
		$days    = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $days, $periods ) ) {
			$days = 30;
		}
		$s      = self::stats( $days );
		$counts = OLI_ACR_Carts::count_by_status();

		echo '<ul class="subsubsub oli-acr-periods">';
		$links = array();
		foreach ( $periods as $value => $label ) {
			$links[] = sprintf( '<li><a href="%s"%s>%s</a>', esc_url( self::url( 'dashboard', array( 'period' => $value ) ) ), $value === $days ? ' class="current"' : '', esc_html( $label ) );
		}
		echo implode( ' | </li>', $links ) . '</li></ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Échappé ci-dessus.

		$cards = array(
			array( __( 'Abandoned carts', 'oli-abandoned-cart-recovery' ), number_format_i18n( $s['abandoned'] ) ),
			array( __( 'Pending orders reminded', 'oli-abandoned-cart-recovery' ), number_format_i18n( $s['pending'] ) ),
			array( __( 'Emails sent', 'oli-abandoned-cart-recovery' ), number_format_i18n( $s['sent'] ) ),
			array( __( 'Clicks', 'oli-abandoned-cart-recovery' ), number_format_i18n( $s['clicked'] ) . ' (' . $s['click_rate'] . ' %)' ),
			array( __( 'Recovered carts', 'oli-abandoned-cart-recovery' ), number_format_i18n( $s['recovered_carts'] ) ),
			array( __( 'Recovered pending orders', 'oli-abandoned-cart-recovery' ), number_format_i18n( $s['recovered_orders'] ) ),
			array( __( 'Recovery rate', 'oli-abandoned-cart-recovery' ), $s['recovery_rate'] . ' %' ),
			array( __( 'Recovered revenue', 'oli-abandoned-cart-recovery' ), wp_strip_all_tags( wc_price( $s['amount'] ) ) ),
		);
		echo '<div class="oli-acr-cards">';
		foreach ( $cards as $card ) {
			printf( '<div class="oli-acr-card"><span class="oli-acr-card-value">%s</span><span class="oli-acr-card-label">%s</span></div>', esc_html( $card[1] ), esc_html( $card[0] ) );
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Carts right now', 'oli-abandoned-cart-recovery' ) . '</h2><p>';
		$parts = array();
		foreach ( oli_acr_statuses() as $key => $label ) {
			$parts[] = '<a href="' . esc_url( self::url( 'carts', array( 'status' => $key ) ) ) . '">' . esc_html( $label ) . '</a> : <strong>' . esc_html( number_format_i18n( $counts[ $key ] ) ) . '</strong>';
		}
		echo implode( ' &nbsp;·&nbsp; ', $parts ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Échappé ci-dessus.

		// Rapport par jour (fuseau du site).
		$log    = oli_acr_table( 'log' );
		$offset = (int) wp_timezone()->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) );
		$since  = $days > 0 ? oli_acr_now( -1 * $days * DAY_IN_SECONDS ) : '1970-01-01 00:00:00';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(sent_at, INTERVAL %d SECOND)) AS day, COUNT(*) AS sent, SUM(clicked_at IS NOT NULL) AS clicked, SUM(recovered_at IS NOT NULL) AS recovered, SUM(recovered_total) AS amount FROM {$log} WHERE sent_at >= %s GROUP BY day ORDER BY day DESC LIMIT 400", $offset, $since ) );

		echo '<h2>' . esc_html__( 'Report by day', 'oli-abandoned-cart-recovery' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Day', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Emails sent', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Clicks', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Recovered', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Recovered revenue', 'oli-abandoned-cart-recovery' ) . '</th></tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No data for this period.', 'oli-abandoned-cart-recovery' ) . '</td></tr>';
		}
		foreach ( (array) $rows as $row ) {
			printf( '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->day ) ) ), (int) $row->sent, (int) $row->clicked, (int) $row->recovered, wp_kses_post( wc_price( (float) $row->amount ) ) );
		}
		echo '</tbody></table>';

		// Rapport par modèle.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_tpl = $wpdb->get_results( $wpdb->prepare( "SELECT template_id, COUNT(*) AS sent, SUM(clicked_at IS NOT NULL) AS clicked, SUM(recovered_at IS NOT NULL) AS recovered, SUM(recovered_total) AS amount FROM {$log} WHERE sent_at >= %s GROUP BY template_id", $since ) );
		echo '<h2>' . esc_html__( 'Report by template', 'oli-abandoned-cart-recovery' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Template', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Emails sent', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Clicks', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Recovered', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Recovered revenue', 'oli-abandoned-cart-recovery' ) . '</th></tr></thead><tbody>';
		if ( empty( $by_tpl ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No data for this period.', 'oli-abandoned-cart-recovery' ) . '</td></tr>';
		}
		foreach ( (array) $by_tpl as $row ) {
			$tpl = OLI_ACR_Templates::get( $row->template_id );
			printf( '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( $tpl ? $tpl['name'] : $row->template_id ), (int) $row->sent, (int) $row->clicked, (int) $row->recovered, wp_kses_post( wc_price( (float) $row->amount ) ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Champ de durée.
	 *
	 * @param string $name  Nom du champ.
	 * @param array  $value Valeur.
	 */
	private static function duration_field( $name, $value ) {
		$value = wp_parse_args(
			(array) $value,
			array(
				'value' => 0,
				'unit'  => 'minutes',
			)
		);
		printf( '<input type="number" min="0" class="small-text" name="%1$s[value]" value="%2$s"> ', esc_attr( $name ), esc_attr( $value['value'] ) );
		printf( '<select name="%s[unit]">', esc_attr( $name ) );
		foreach ( oli_acr_duration_units() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $value['unit'], $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Case oui/non.
	 *
	 * @param string $name    Nom.
	 * @param string $value   Valeur.
	 * @param string $label   Libellé.
	 */
	private static function checkbox( $name, $value, $label ) {
		printf( '<label><input type="checkbox" name="%s" value="yes"%s> %s</label>', esc_attr( $name ), checked( 'yes', $value, false ), esc_html( $label ) );
	}

	/**
	 * Onglet réglages.
	 */
	private static function render_settings() {
		$s = oli_acr_settings();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oli_acr_save_settings' );
		echo '<input type="hidden" name="action" value="oli_acr_save_settings">';

		echo '<h2>' . esc_html__( 'General', 'oli-abandoned-cart-recovery' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Enable cart recovery', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::checkbox( 's[enabled]', $s['enabled'], __( 'Capture carts and send abandoned cart reminders', 'oli-abandoned-cart-recovery' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Consider a cart abandoned after', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::duration_field( 's[abandon_after]', $s['abandon_after'] );
		echo '<p class="description">' . esc_html__( 'Time without activity before an active cart becomes abandoned. Template delays are counted from that moment.', 'oli-abandoned-cart-recovery' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Guest carts', 'oli-abandoned-cart-recovery' ) . '</th><td><select name="s[guest_tracking]">';
		$modes = array(
			'always'  => __( 'Always capture guest carts', 'oli-abandoned-cart-recovery' ),
			'consent' => __( 'Only when the guest checks the consent box', 'oli-abandoned-cart-recovery' ),
			'never'   => __( 'Never (registered customers only)', 'oli-abandoned-cart-recovery' ),
		);
		foreach ( $modes as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $s['guest_tracking'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Consent text', 'oli-abandoned-cart-recovery' ) . '</th><td><textarea name="s[consent_text]" rows="2" class="large-text">' . esc_textarea( oli_acr_consent_text() ) . '</textarea><p class="description">' . esc_html__( 'Label of the checkbox shown under the email field (consent mode).', 'oli-abandoned-cart-recovery' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Registered customers tracked', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		printf( '<label><input type="radio" name="s[roles_mode]" value="all"%s> %s</label><br>', checked( 'all', $s['roles_mode'], false ), esc_html__( 'All roles', 'oli-abandoned-cart-recovery' ) );
		printf( '<label><input type="radio" name="s[roles_mode]" value="selected"%s> %s</label><br>', checked( 'selected', $s['roles_mode'], false ), esc_html__( 'Only these roles:', 'oli-abandoned-cart-recovery' ) );
		foreach ( wp_roles()->get_names() as $role => $name ) {
			printf( '<label class="oli-acr-role"><input type="checkbox" name="s[roles][]" value="%s"%s> %s</label> ', esc_attr( $role ), checked( in_array( $role, (array) $s['roles'], true ), true, false ), esc_html( translate_user_role( $name ) ) );
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Run the recovery task every', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::duration_field( 's[cron_interval]', $s['cron_interval'] );
		echo '<p class="description">' . esc_html( function_exists( 'as_schedule_recurring_action' ) ? __( 'Runs with Action Scheduler (WooCommerce).', 'oli-abandoned-cart-recovery' ) : __( 'Runs with WP-Cron.', 'oli-abandoned-cart-recovery' ) ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Pending orders', 'oli-abandoned-cart-recovery' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Enable pending order recovery', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::checkbox( 's[pending_enabled]', $s['pending_enabled'], __( 'Send the "pending order" templates to unpaid orders', 'oli-abandoned-cart-recovery' ) );
		echo '</td></tr><tr><th>' . esc_html__( 'Start reminding pending orders after', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::duration_field( 's[pending_after]', $s['pending_after'] );
		echo '</td></tr><tr><th>' . esc_html__( 'Cancel reminded pending orders after', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::duration_field( 's[pending_cancel_after]', $s['pending_cancel_after'] );
		echo '<p class="description">' . esc_html__( '0 = never. Only pending orders that received a reminder are cancelled.', 'oli-abandoned-cart-recovery' ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'Sender', 'oli-abandoned-cart-recovery' ) . '</h2><table class="form-table" role="presentation">';
		printf( '<tr><th>%s</th><td><input type="text" class="regular-text" name="s[sender_name]" value="%s" placeholder="%s"></td></tr>', esc_html__( 'Sender name', 'oli-abandoned-cart-recovery' ), esc_attr( $s['sender_name'] ), esc_attr( get_option( 'woocommerce_email_from_name' ) ) );
		printf( '<tr><th>%s</th><td><input type="email" class="regular-text" name="s[sender_email]" value="%s" placeholder="%s"></td></tr>', esc_html__( 'Sender email', 'oli-abandoned-cart-recovery' ), esc_attr( $s['sender_email'] ), esc_attr( get_option( 'woocommerce_email_from_address' ) ) );
		printf( '<tr><th>%s</th><td><input type="email" class="regular-text" name="s[reply_to]" value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Default reply-to', 'oli-abandoned-cart-recovery' ), esc_attr( $s['reply_to'] ), esc_html__( 'Each template can override it.', 'oli-abandoned-cart-recovery' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Admin notification', 'oli-abandoned-cart-recovery' ) . '</h2><table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Notify on recovery', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::checkbox( 's[admin_notify]', $s['admin_notify'], __( 'Email the admin when a cart or pending order is recovered', 'oli-abandoned-cart-recovery' ) );
		printf( '</td></tr><tr><th>%s</th><td><input type="text" class="regular-text" name="s[admin_recipient]" value="%s" placeholder="%s"><p class="description"><a href="%s">%s</a></p></td></tr></table>', esc_html__( 'Recipient(s)', 'oli-abandoned-cart-recovery' ), esc_attr( $s['admin_recipient'] ), esc_attr( get_option( 'admin_email' ) ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=oli_acr_email_admin_recovered' ) ), esc_html__( 'Subject, heading and format are in WooCommerce > Settings > Emails.', 'oli-abandoned-cart-recovery' ) );

		echo '<h2>' . esc_html__( 'Coupons', 'oli-abandoned-cart-recovery' ) . '</h2><table class="form-table" role="presentation">';
		printf( '<tr><th>%s</th><td><input type="text" class="regular-text" name="s[coupon_prefix]" value="%s"></td></tr>', esc_html__( 'Coupon prefix', 'oli-abandoned-cart-recovery' ), esc_attr( $s['coupon_prefix'] ) );
		echo '<tr><th>' . esc_html__( 'Clean up coupons', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::checkbox( 's[coupon_delete_used]', $s['coupon_delete_used'], __( 'Trash generated coupons once used', 'oli-abandoned-cart-recovery' ) );
		echo '<br>';
		self::checkbox( 's[coupon_delete_expired]', $s['coupon_delete_expired'], __( 'Trash generated coupons once expired', 'oli-abandoned-cart-recovery' ) );
		echo '</td></tr></table>';

		echo '<h2>' . esc_html__( 'Data retention', 'oli-abandoned-cart-recovery' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Delete unrecovered carts after', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::duration_field( 's[delete_carts_after]', $s['delete_carts_after'] );
		echo '<p class="description">' . esc_html__( '0 = never. Active, abandoned and reminded carts older than this are deleted daily.', 'oli-abandoned-cart-recovery' ) . '</p></td></tr>';
		printf( '<tr><th>%s</th><td><input type="number" min="0" class="small-text" name="s[retention_days]" value="%d"> %s<p class="description">%s</p></td></tr>', esc_html__( 'Keep all data (including recovered carts and email log) for', 'oli-abandoned-cart-recovery' ), (int) $s['retention_days'], esc_html__( 'days', 'oli-abandoned-cart-recovery' ), esc_html__( '0 = forever.', 'oli-abandoned-cart-recovery' ) );
		echo '<tr><th>' . esc_html__( 'Unsubscribed / excluded emails', 'oli-abandoned-cart-recovery' ) . '</th><td><textarea name="blocklist" rows="5" class="large-text code">' . esc_textarea( implode( "\n", oli_acr_get_blocklist() ) ) . '</textarea><p class="description">' . esc_html__( 'One email per line. These addresses are never tracked or emailed.', 'oli-abandoned-cart-recovery' ) . '</p></td></tr>';
		echo '</table>';

		if ( current_user_can( 'manage_options' ) ) {
			echo '<h2>' . esc_html__( 'Access', 'oli-abandoned-cart-recovery' ) . '</h2><table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Shop managers', 'oli-abandoned-cart-recovery' ) . '</th><td>';
			self::checkbox( 's[shop_manager_access]', $s['shop_manager_access'], __( 'Allow the Shop manager role to use this plugin (capability oli_acr_manage)', 'oli-abandoned-cart-recovery' ) );
			echo '</td></tr></table>';
		}

		submit_button();
		echo '</form>';
	}

	/**
	 * Enregistre les réglages.
	 */
	public static function save_settings() {
		self::check_cap();
		check_admin_referer( 'oli_acr_save_settings' );
		$raw  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nettoyé champ par champ ci-dessous.
		$old  = oli_acr_settings();
		$new  = $old;
		$yesn = array( 'enabled', 'pending_enabled', 'admin_notify', 'coupon_delete_used', 'coupon_delete_expired' );
		foreach ( $yesn as $key ) {
			$new[ $key ] = ! empty( $raw[ $key ] ) ? 'yes' : 'no';
		}
		if ( current_user_can( 'manage_options' ) ) {
			$new['shop_manager_access'] = ! empty( $raw['shop_manager_access'] ) ? 'yes' : 'no';
		}
		$new['abandon_after']        = oli_acr_sanitize_duration( isset( $raw['abandon_after'] ) ? $raw['abandon_after'] : array(), 1 );
		$new['pending_after']        = oli_acr_sanitize_duration( isset( $raw['pending_after'] ) ? $raw['pending_after'] : array() );
		$new['pending_cancel_after'] = oli_acr_sanitize_duration( isset( $raw['pending_cancel_after'] ) ? $raw['pending_cancel_after'] : array() );
		$new['cron_interval']        = oli_acr_sanitize_duration( isset( $raw['cron_interval'] ) ? $raw['cron_interval'] : array(), 1 );
		$new['delete_carts_after']   = oli_acr_sanitize_duration( isset( $raw['delete_carts_after'] ) ? $raw['delete_carts_after'] : array() );
		$new['retention_days']       = isset( $raw['retention_days'] ) ? absint( $raw['retention_days'] ) : 0;
		$new['guest_tracking']       = isset( $raw['guest_tracking'] ) && in_array( $raw['guest_tracking'], array( 'always', 'consent', 'never' ), true ) ? $raw['guest_tracking'] : 'always';
		$new['consent_text']         = isset( $raw['consent_text'] ) ? sanitize_textarea_field( $raw['consent_text'] ) : '';
		$new['roles_mode']           = isset( $raw['roles_mode'] ) && 'selected' === $raw['roles_mode'] ? 'selected' : 'all';
		$new['roles']                = isset( $raw['roles'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $raw['roles'] ), array_keys( wp_roles()->get_names() ) ) ) : array();
		$new['sender_name']          = isset( $raw['sender_name'] ) ? sanitize_text_field( $raw['sender_name'] ) : '';
		$new['sender_email']         = isset( $raw['sender_email'] ) ? sanitize_email( $raw['sender_email'] ) : '';
		$new['reply_to']             = isset( $raw['reply_to'] ) ? sanitize_email( $raw['reply_to'] ) : '';
		$new['admin_recipient']      = isset( $raw['admin_recipient'] ) ? implode( ',', array_filter( array_map( 'sanitize_email', explode( ',', $raw['admin_recipient'] ) ) ) ) : '';
		$new['coupon_prefix']        = isset( $raw['coupon_prefix'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9\-_]/', '', $raw['coupon_prefix'] ) ) : '';
		update_option( 'oli_acr_settings', $new, false );

		// Liste d'exclusion.
		$lines = isset( $_POST['blocklist'] ) ? explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['blocklist'] ) ) ) : array();
		$list  = array();
		foreach ( $lines as $line ) {
			$email = strtolower( sanitize_email( trim( $line ) ) );
			if ( is_email( $email ) ) {
				$list[] = $email;
			}
		}
		$list = array_values( array_unique( $list ) );
		foreach ( array_diff( $list, oli_acr_get_blocklist() ) as $added ) {
			OLI_ACR_Carts::mark_unsubscribed( $added );
		}
		update_option( 'oli_acr_blocklist', $list, false );

		OLI_ACR_Install::sync_shop_manager_cap();
		self::redirect( 'settings', 'saved' );
	}

	/**
	 * Onglet modèles : liste, édition et test.
	 */
	private static function render_templates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
		if ( '' !== $edit ) {
			self::render_template_form( 'new' === $edit ? null : OLI_ACR_Templates::get( $edit ) );
			return;
		}

		$templates = OLI_ACR_Templates::all();
		echo '<p><a class="button button-primary" href="' . esc_url( self::url( 'templates', array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Add template', 'oli-abandoned-cart-recovery' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Type', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Send after', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Coupon', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Status', 'oli-abandoned-cart-recovery' ) . '</th><th></th></tr></thead><tbody>';
		$units = oli_acr_duration_units();
		foreach ( $templates as $id => $tpl ) {
			$delete = wp_nonce_url( admin_url( 'admin-post.php?action=oli_acr_delete_template&template=' . $id ), 'oli_acr_delete_template' );
			printf(
				'<tr><td><strong><a href="%s">%s</a></strong><br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a> | <a class="submitdelete" href="%s" onclick="return confirm(\'%s\');">%s</a></td></tr>',
				esc_url( self::url( 'templates', array( 'edit' => $id ) ) ),
				esc_html( $tpl['name'] ),
				esc_html( $tpl['subject'] ),
				'order' === $tpl['type'] ? esc_html__( 'Pending order', 'oli-abandoned-cart-recovery' ) : esc_html__( 'Abandoned cart', 'oli-abandoned-cart-recovery' ),
				esc_html( $tpl['delay']['value'] . ' ' . strtolower( $units[ $tpl['delay']['unit'] ] ) ),
				'yes' === $tpl['coupon_enabled'] ? esc_html( wc_format_localized_decimal( $tpl['coupon_amount'] ) . ( 'percent' === $tpl['coupon_type'] ? ' %' : ' ' . get_woocommerce_currency_symbol() ) ) : '—',
				'yes' === $tpl['active'] ? '<mark class="oli-acr-status oli-acr-status-recovered">' . esc_html__( 'Active', 'oli-abandoned-cart-recovery' ) . '</mark>' : '<mark class="oli-acr-status">' . esc_html__( 'Inactive', 'oli-abandoned-cart-recovery' ) . '</mark>',
				esc_url( self::url( 'templates', array( 'edit' => $id ) ) ),
				esc_html__( 'Edit', 'oli-abandoned-cart-recovery' ),
				esc_url( $delete ),
				esc_js( __( 'Delete this template?', 'oli-abandoned-cart-recovery' ) ),
				esc_html__( 'Delete', 'oli-abandoned-cart-recovery' )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Cart templates are sent in sequence, from the shortest delay to the longest, counted from the moment the cart was abandoned. Pending order templates are counted from the pending order delay set in Settings.', 'oli-abandoned-cart-recovery' ) . '</p>';
		self::render_test_form( '' );
	}

	/**
	 * Formulaire « Envoyer un test ».
	 *
	 * @param string $template_id Modèle présélectionné.
	 */
	private static function render_test_form( $template_id ) {
		echo '<h2>' . esc_html__( 'Send a test', 'oli-abandoned-cart-recovery' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oli-acr-test">';
		wp_nonce_field( 'oli_acr_test_email' );
		echo '<input type="hidden" name="action" value="oli_acr_test_email"><select name="template">';
		foreach ( OLI_ACR_Templates::all() as $id => $tpl ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $template_id, $id, false ), esc_html( $tpl['name'] ) );
		}
		echo '</select> <input type="email" name="to" required class="regular-text" value="' . esc_attr( wp_get_current_user()->user_email ) . '"> ';
		submit_button( __( 'Send a test', 'oli-abandoned-cart-recovery' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Formulaire d'édition d'un modèle.
	 *
	 * @param array|null $tpl Modèle.
	 */
	private static function render_template_form( $tpl ) {
		$tpl = $tpl ? $tpl : OLI_ACR_Templates::blank();
		echo '<p><a href="' . esc_url( self::url( 'templates' ) ) . '">&larr; ' . esc_html__( 'Back to templates', 'oli-abandoned-cart-recovery' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oli_acr_save_template' );
		echo '<input type="hidden" name="action" value="oli_acr_save_template"><input type="hidden" name="t[id]" value="' . esc_attr( $tpl['id'] ) . '">';
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th>%s</th><td><input type="text" required class="regular-text" name="t[name]" value="%s"></td></tr>', esc_html__( 'Template name', 'oli-abandoned-cart-recovery' ), esc_attr( $tpl['name'] ) );
		echo '<tr><th>' . esc_html__( 'Active', 'oli-abandoned-cart-recovery' ) . '</th><td><label><input type="checkbox" name="t[active]" value="1"' . checked( 'yes', $tpl['active'], false ) . '> ' . esc_html__( 'Send this template automatically', 'oli-abandoned-cart-recovery' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Type', 'oli-abandoned-cart-recovery' ) . '</th><td><select name="t[type]"><option value="cart"' . selected( 'cart', $tpl['type'], false ) . '>' . esc_html__( 'Abandoned cart', 'oli-abandoned-cart-recovery' ) . '</option><option value="order"' . selected( 'order', $tpl['type'], false ) . '>' . esc_html__( 'Pending order', 'oli-abandoned-cart-recovery' ) . '</option></select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Send after', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::duration_field( 't[delay]', $tpl['delay'] );
		echo '</td></tr>';
		printf( '<tr><th>%s</th><td><input type="text" required class="large-text" name="t[subject]" value="%s"></td></tr>', esc_html__( 'Email subject', 'oli-abandoned-cart-recovery' ), esc_attr( $tpl['subject'] ) );
		printf( '<tr><th>%s</th><td><input type="text" class="large-text" name="t[heading]" value="%s"></td></tr>', esc_html__( 'Email heading', 'oli-abandoned-cart-recovery' ), esc_attr( $tpl['heading'] ) );
		printf( '<tr><th>%s</th><td><input type="email" class="regular-text" name="t[reply_to]" value="%s" placeholder="%s"></td></tr>', esc_html__( 'Reply-to address', 'oli-abandoned-cart-recovery' ), esc_attr( $tpl['reply_to'] ), esc_attr( oli_acr_get_setting( 'reply_to' ) ) );
		printf( '<tr><th>%s</th><td><input type="text" class="regular-text" name="t[button_label]" value="%s"></td></tr>', esc_html__( 'Recovery button label', 'oli-abandoned-cart-recovery' ), esc_attr( $tpl['button_label'] ) );
		echo '<tr><th>' . esc_html__( 'Email content', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		wp_editor(
			$tpl['content'],
			'oli_acr_content',
			array(
				'textarea_name' => 't[content]',
				'textarea_rows' => 14,
				'media_buttons' => false,
			)
		);
		echo '<p class="description">' . esc_html__( 'Placeholders:', 'oli-abandoned-cart-recovery' ) . '</p><ul class="oli-acr-placeholders">';
		foreach ( OLI_ACR_Mailer::placeholders() as $tag => $label ) {
			printf( '<li><code>%s</code> %s</li>', esc_html( $tag ), esc_html( $label ) );
		}
		echo '</ul></td></tr>';
		echo '<tr><th>' . esc_html__( 'Coupon', 'oli-abandoned-cart-recovery' ) . '</th><td><label><input type="checkbox" name="t[coupon_enabled]" value="1"' . checked( 'yes', $tpl['coupon_enabled'], false ) . '> ' . esc_html__( 'Create a unique single-use coupon for each email ({coupon})', 'oli-abandoned-cart-recovery' ) . '</label><br>';
		printf( '<input type="number" step="0.01" min="0" class="small-text" name="t[coupon_amount]" value="%s"> ', esc_attr( $tpl['coupon_amount'] ) );
		echo '<select name="t[coupon_type]"><option value="percent"' . selected( 'percent', $tpl['coupon_type'], false ) . '>' . esc_html__( 'Percentage discount', 'oli-abandoned-cart-recovery' ) . '</option><option value="fixed_cart"' . selected( 'fixed_cart', $tpl['coupon_type'], false ) . '>' . esc_html__( 'Fixed cart discount', 'oli-abandoned-cart-recovery' ) . '</option></select> ';
		printf( '%s <input type="number" min="0" class="small-text" name="t[coupon_validity]" value="%d"> %s', esc_html__( 'valid for', 'oli-abandoned-cart-recovery' ), (int) $tpl['coupon_validity'], esc_html__( 'days (0 = no expiry)', 'oli-abandoned-cart-recovery' ) );
		echo '</td></tr></table>';
		submit_button( __( 'Save template', 'oli-abandoned-cart-recovery' ) );
		echo '</form>';
		if ( $tpl['id'] ) {
			self::render_test_form( $tpl['id'] );
		}
	}

	/**
	 * Enregistre un modèle.
	 */
	public static function save_template() {
		self::check_cap();
		check_admin_referer( 'oli_acr_save_template' );
		$raw = isset( $_POST['t'] ) && is_array( $_POST['t'] ) ? wp_unslash( $_POST['t'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nettoyé par OLI_ACR_Templates::sanitize().
		$id  = OLI_ACR_Templates::save( OLI_ACR_Templates::sanitize( $raw ) );
		self::redirect( 'templates', 'tpl_saved', array( 'edit' => $id ) );
	}

	/**
	 * Supprime un modèle.
	 */
	public static function delete_template() {
		self::check_cap();
		check_admin_referer( 'oli_acr_delete_template' );
		$id = isset( $_GET['template'] ) ? sanitize_key( $_GET['template'] ) : '';
		OLI_ACR_Templates::delete( $id );
		self::redirect( 'templates', 'tpl_deleted' );
	}

	/**
	 * Bouton « Envoyer un test ».
	 */
	public static function test_email() {
		self::check_cap();
		check_admin_referer( 'oli_acr_test_email' );
		$to  = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$id  = isset( $_POST['template'] ) ? sanitize_key( $_POST['template'] ) : '';
		$tpl = OLI_ACR_Templates::get( $id );
		$ok  = $tpl && is_email( $to ) && OLI_ACR_Mailer::send_test( $to, $tpl );
		self::redirect( 'templates', $ok ? 'test_sent' : 'test_failed', array( 'edit' => $id ) );
	}

	/**
	 * Actions sur un panier : envoi immédiat ou suppression.
	 */
	public static function cart_action() {
		self::check_cap();
		check_admin_referer( 'oli_acr_cart_action' );
		$id   = isset( $_GET['cart'] ) ? absint( $_GET['cart'] ) : 0;
		$do   = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		$cart = OLI_ACR_Carts::get( $id );
		if ( ! $cart ) {
			self::redirect( 'carts', 'not_sent' );
		}
		if ( 'delete' === $do ) {
			OLI_ACR_Carts::delete( $id );
			self::redirect( 'carts', 'deleted' );
		}
		$done = array_filter( explode( ',', (string) $cart->sent_templates ) );
		foreach ( OLI_ACR_Templates::active( 'cart' ) as $tpl_id => $tpl ) {
			if ( in_array( $tpl_id, $done, true ) ) {
				continue;
			}
			if ( empty( $cart->abandoned_at ) ) {
				OLI_ACR_Carts::update( $id, array( 'abandoned_at' => oli_acr_now() ) );
			}
			$ok = OLI_ACR_Mailer::send_cart_email( $cart, $tpl_id, $tpl );
			self::redirect( 'carts', $ok ? 'sent' : 'not_sent' );
		}
		self::redirect( 'carts', 'not_sent' );
	}

	/**
	 * Envoi immédiat d'une relance de commande en attente.
	 */
	public static function order_action() {
		self::check_cap();
		check_admin_referer( 'oli_acr_order_action' );
		$order = wc_get_order( isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0 );
		if ( $order ) {
			$done = array_filter( (array) $order->get_meta( '_oli_acr_sent' ) );
			foreach ( OLI_ACR_Templates::active( 'order' ) as $tpl_id => $tpl ) {
				if ( ! in_array( $tpl_id, $done, true ) ) {
					$ok = OLI_ACR_Mailer::send_order_email( $order, $tpl_id, $tpl );
					self::redirect( 'pending', $ok ? 'sent' : 'not_sent' );
				}
			}
		}
		self::redirect( 'pending', 'not_sent' );
	}
}
