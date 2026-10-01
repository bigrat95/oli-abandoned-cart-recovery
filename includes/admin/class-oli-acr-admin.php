<?php
/**
 * Interface d'administration sous le menu WooCommerce.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
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
	 *
	 * @return void
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
		add_action( 'admin_post_oli_acr_dismiss_notice', array( __CLASS__, 'dismiss_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'global_notices' ) );
		add_filter( 'plugin_action_links_' . OLI_ACR_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Lien « Réglages » dans la liste des extensions.
	 *
	 * @param array<mixed> $links Liens.
	 * @return array<mixed>
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'oli-abandoned-cart-recovery' ) . '</a>' );
		return $links;
	}

	/**
	 * URL d'un onglet.
	 *
	 * @param string       $tab  Onglet.
	 * @param array<mixed> $args Arguments.
	 * @return string
	 */
	public static function url( $tab = 'dashboard', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Sous-menu WooCommerce.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Abandoned Carts', 'oli-abandoned-cart-recovery' ), __( 'Abandoned Carts', 'oli-abandoned-cart-recovery' ), OLI_ACR_CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Styles d'admin.
	 *
	 * @param string $hook Écran.
	 * @return void
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
	 * @return array<mixed>
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
	 *
	 * @return void
	 */
	private static function check_cap() {
		if ( ! current_user_can( OLI_ACR_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'oli-abandoned-cart-recovery' ), 403 );
		}
	}

	/**
	 * Redirection avec message.
	 *
	 * @param string               $tab  Onglet.
	 * @param string               $msg  Code du message.
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 */
	private static function redirect( $tab, $msg, $args = array() ) {
		wp_safe_redirect( self::url( $tab, array_merge( $args, array( 'oli_acr_msg' => $msg ) ) ) );
		exit;
	}

	/**
	 * Affiche la page.
	 *
	 * @return void
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
	 * @return void
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
	 *
	 * @return void
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
	 *
	 * @return void
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
	 *
	 * @return void
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
	 * @return array<mixed>
	 */
	public static function stats( $days ) {
		global $wpdb;
		$carts = oli_acr_table( 'carts' );
		$log   = oli_acr_table( 'log' );
		$since = $days > 0 ? oli_acr_now( -1 * $days * DAY_IN_SECONDS ) : '1970-01-01 00:00:00';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Noms de table fixes (oli_acr_table()), valeurs passées par prepare().
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
	 *
	 * @return void
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table fixe (oli_acr_table()), valeurs passées par prepare().
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table fixe (oli_acr_table()), valeurs passées par prepare().
		$by_tpl = $wpdb->get_results( $wpdb->prepare( "SELECT template_id, COUNT(*) AS sent, SUM(clicked_at IS NOT NULL) AS clicked, SUM(recovered_at IS NOT NULL) AS recovered, SUM(recovered_total) AS amount FROM {$log} WHERE sent_at >= %s GROUP BY template_id", $since ) );
		echo '<h2>' . esc_html__( 'Report by template', 'oli-abandoned-cart-recovery' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Template', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Emails sent', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Clicks', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Recovered', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Recovered revenue', 'oli-abandoned-cart-recovery' ) . '</th></tr></thead><tbody>';
		if ( empty( $by_tpl ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No data for this period.', 'oli-abandoned-cart-recovery' ) . '</td></tr>';
		}
		foreach ( (array) $by_tpl as $row ) {
			$name = self::template_name( (string) $row->template_id );
			printf( '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( $name ), (int) $row->sent, (int) $row->clicked, (int) $row->recovered, wp_kses_post( wc_price( (float) $row->amount ) ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Champ de durée.
	 *
	 * @param string               $name  Nom du champ.
	 * @param array<string, mixed> $value Valeur.
	 * @return void
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
	 * @return void
	 */
	private static function checkbox( $name, $value, $label ) {
		printf( '<label><input type="checkbox" name="%s" value="yes"%s> %s</label>', esc_attr( $name ), checked( 'yes', $value, false ), esc_html( $label ) );
	}

	/**
	 * Onglet réglages.
	 *
	 * @return void
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
		$consent_on = 'always' !== $s['guest_tracking'];
		echo '<tr><th>' . esc_html__( 'Guest carts', 'oli-abandoned-cart-recovery' ) . '</th><td><select name="s[guest_capture]">';
		printf( '<option value="capture"%s>%s</option>', selected( 'never' !== $s['guest_tracking'], true, false ), esc_html__( 'Capture guest carts', 'oli-abandoned-cart-recovery' ) );
		printf( '<option value="never"%s>%s</option>', selected( 'never', $s['guest_tracking'], false ), esc_html__( 'Never (registered customers only)', 'oli-abandoned-cart-recovery' ) );
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Require consent', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		printf(
			'<input type="hidden" name="s[consent_required]" value="no"><label><input type="checkbox" name="s[consent_required]" value="yes"%s> %s</label>',
			checked( $consent_on, true, false ),
			esc_html__( 'Show a consent checkbox at checkout and save an email and cart (guests and logged-in customers) only when it is checked (recommended, on by default)', 'oli-abandoned-cart-recovery' )
		);
		if ( ! $consent_on ) {
			echo '<div class="notice notice-error inline oli-acr-consent-off"><p><strong>' . esc_html__( 'Consent is turned off.', 'oli-abandoned-cart-recovery' ) . '</strong> ' . esc_html__( 'Emails and carts of guests and logged-in customers are saved without consent. Quebec Law 25 and the GDPR generally require explicit consent before saving this data for marketing reminders. By turning consent off, you, the site owner, are responsible for having another legal basis.', 'oli-abandoned-cart-recovery' ) . '</p></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Turning this off saves the data of guests and logged-in customers without consent: you then become responsible for compliance with Quebec Law 25 and the GDPR.', 'oli-abandoned-cart-recovery' ) . '</p>';
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Fallback language', 'oli-abandoned-cart-recovery' ) . '</th><td><select name="s[fallback_language]">';
		printf( '<option value="">%s</option>', esc_html( sprintf( /* translators: %s: language name. */ __( 'Site default language (%s)', 'oli-abandoned-cart-recovery' ), OLI_ACR_Lang::label( OLI_ACR_Lang::adapter()->default_language() ) ) ) );
		foreach ( OLI_ACR_Lang::adapter()->languages() as $locale ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $locale ), selected( (string) $s['fallback_language'], $locale, false ), esc_html( OLI_ACR_Lang::label( $locale ) ) );
		}
		echo '</select><p class="description">' . esc_html(
			sprintf(
				/* translators: %s: multilingual plugin name. */
				__( 'Language used for a cart whose language is unknown or no longer active, and for texts that are missing in a language. Languages detected with: %s.', 'oli-abandoned-cart-recovery' ),
				OLI_ACR_Lang::adapter()->label()
			)
		) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Consent text', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		$consent_texts = (array) $s['consent_texts'];
		foreach ( OLI_ACR_Lang::languages() as $locale ) {
			$value = isset( $consent_texts[ $locale ] ) ? (string) $consent_texts[ $locale ] : '';
			if ( '' === $value && OLI_ACR_Lang::fallback_language() === $locale ) {
				$value = oli_acr_consent_base_text( $locale );
			}
			printf( '<div class="oli-acr-lang-field" lang="%1$s"><p><strong>%2$s</strong></p>', esc_attr( str_replace( '_', '-', $locale ) ), esc_html( OLI_ACR_Lang::label( $locale ) ) );
			wp_editor(
				oli_acr_is_default_consent_text( $value ) ? '' : $value,
				'oli_acr_consent_' . strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '_', $locale ) ),
				array(
					'textarea_name' => 's[consent_texts][' . $locale . ']',
					'textarea_rows' => 3,
					'media_buttons' => false,
					'teeny'         => true,
					'wpautop'       => false,
					'tinymce'       => array(
						'toolbar1'          => 'bold,italic,link,unlink',
						'toolbar2'          => '',
						'forced_root_block' => '',
					),
					'quicktags'     => array( 'buttons' => 'strong,em,link' ),
				)
			);
			/* translators: %s: text used when the field is empty. */
			printf( '<p class="description">%s</p></div>', wp_kses( sprintf( __( 'Empty field: %s', 'oli-abandoned-cart-recovery' ), '<em>' . oli_acr_consent_html( $locale ) . '</em>' ), oli_acr_consent_allowed_html() ) );
		}
		echo '<p class="description">' . esc_html__( 'Label of the consent checkbox shown under the email field, for each language. Links (for example to your privacy policy), bold and italic are allowed. Leave a language empty to use, in order: its WPML or Polylang string translation, the default text translated in that language, then the fallback language text.', 'oli-abandoned-cart-recovery' ) . '</p></td></tr>';
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
		echo '<tr><th>' . esc_html__( 'Uninstall', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::checkbox( 's[keep_data]', $s['keep_data'], __( 'Keep the data when the plugin is deleted (settings, templates, carts, email log)', 'oli-abandoned-cart-recovery' ) );
		echo '<p class="description">' . esc_html__( 'Off by default: deleting the plugin removes all its data.', 'oli-abandoned-cart-recovery' ) . '</p></td></tr>';
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
	 *
	 * @return void
	 */
	public static function save_settings() {
		self::check_cap();
		check_admin_referer( 'oli_acr_save_settings' );
		$raw  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nettoyé champ par champ ci-dessous.
		$old  = oli_acr_settings();
		$new  = $old;
		$yesn = array( 'enabled', 'pending_enabled', 'admin_notify', 'coupon_delete_used', 'coupon_delete_expired', 'keep_data' );
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
		if ( isset( $raw['guest_capture'] ) ) {
			// Interrupteur du consentement (activé par défaut) : « consent » si coché, « always » sinon.
			$consent_required      = ! isset( $raw['consent_required'] ) || 'no' !== $raw['consent_required'];
			$new['guest_tracking'] = 'never' === $raw['guest_capture'] ? 'never' : ( $consent_required ? 'consent' : 'always' );
		} else {
			$new['guest_tracking'] = isset( $raw['guest_tracking'] ) && in_array( $raw['guest_tracking'], array( 'always', 'consent', 'never' ), true ) ? $raw['guest_tracking'] : 'consent';
		}
		if ( 'always' === $new['guest_tracking'] ) {
			// B3 : interrupteur éteint par le marchand, l'avis « le consentement est maintenant exigé » n'a plus lieu d'être.
			delete_option( 'oli_acr_notice_consent_migrated' );
		}
		$new['fallback_language'] = isset( $raw['fallback_language'] ) && in_array( $raw['fallback_language'], OLI_ACR_Lang::adapter()->languages(), true ) ? (string) $raw['fallback_language'] : '';
		$new['consent_texts']     = array();
		$submitted_consent        = isset( $raw['consent_texts'] ) && is_array( $raw['consent_texts'] ) ? $raw['consent_texts'] : array();
		foreach ( OLI_ACR_Lang::languages() as $locale ) {
			$text = isset( $submitted_consent[ $locale ] ) ? oli_acr_sanitize_consent_html( (string) $submitted_consent[ $locale ] ) : '';
			// Texte par défaut : on n'enregistre rien pour qu'il reste traduit dans la langue du visiteur.
			if ( '' !== trim( $text ) && ! oli_acr_is_default_consent_text( $text ) ) {
				$new['consent_texts'][ $locale ] = $text;
			}
		}
		// Champ de la 1.0.x : miroir du texte de la langue de repli.
		$fallback_new           = '' !== $new['fallback_language'] ? $new['fallback_language'] : OLI_ACR_Lang::adapter()->default_language();
		$new['consent_text']    = isset( $new['consent_texts'][ $fallback_new ] ) ? $new['consent_texts'][ $fallback_new ] : '';
		$new['roles_mode']      = isset( $raw['roles_mode'] ) && 'selected' === $raw['roles_mode'] ? 'selected' : 'all';
		$new['roles']           = isset( $raw['roles'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $raw['roles'] ), array_keys( wp_roles()->get_names() ) ) ) : array();
		$new['sender_name']     = isset( $raw['sender_name'] ) ? sanitize_text_field( $raw['sender_name'] ) : '';
		$new['sender_email']    = isset( $raw['sender_email'] ) ? sanitize_email( $raw['sender_email'] ) : '';
		$new['reply_to']        = isset( $raw['reply_to'] ) ? sanitize_email( $raw['reply_to'] ) : '';
		$new['admin_recipient'] = isset( $raw['admin_recipient'] ) ? implode( ',', array_filter( array_map( 'sanitize_email', explode( ',', $raw['admin_recipient'] ) ) ) ) : '';
		$new['coupon_prefix']   = isset( $raw['coupon_prefix'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9\-_]/', '', $raw['coupon_prefix'] ) ) : '';
		update_option( 'oli_acr_settings', $new, true );

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
		if ( $old['fallback_language'] !== $new['fallback_language'] ) {
			OLI_ACR_Templates::sync_languages();
		}
		OLI_ACR_Lang::register_strings();
		self::redirect( 'settings', 'saved' );
	}

	/**
	 * Onglet modèles : liste, édition et test.
	 *
	 * @return void
	 */
	private static function render_templates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
		if ( '' !== $edit ) {
			self::render_template_form( 'new' === $edit ? null : OLI_ACR_Templates::get( $edit ) );
			return;
		}

		$templates = OLI_ACR_Templates::all();
		$lang      = self::tab_language();
		self::language_tabs( self::url( 'templates' ), $lang );
		echo '<p><a class="button button-primary" href="' . esc_url(
			self::url(
				'templates',
				array(
					'edit' => 'new',
					'lang' => $lang,
				)
			)
		) . '">' . esc_html__( 'Add template', 'oli-abandoned-cart-recovery' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Type', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Send after', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Coupon', 'oli-abandoned-cart-recovery' ) . '</th><th>' . esc_html__( 'Status', 'oli-abandoned-cart-recovery' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $templates as $id => $tpl ) {
			$tpl    = OLI_ACR_Templates::for_locale( (string) $id, $tpl, $lang );
			$delete = wp_nonce_url( admin_url( 'admin-post.php?action=oli_acr_delete_template&template=' . $id ), 'oli_acr_delete_template' );
			printf(
				'<tr><td><strong><a href="%s">%s</a></strong><br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a> | <a class="submitdelete" href="%s" onclick="return confirm(\'%s\');">%s</a></td></tr>',
				esc_url(
					self::url(
						'templates',
						array(
							'edit' => $id,
							'lang' => $lang,
						)
					)
				),
				esc_html( $tpl['name'] ),
				esc_html( $tpl['subject'] ),
				'order' === $tpl['type'] ? esc_html__( 'Pending order', 'oli-abandoned-cart-recovery' ) : esc_html__( 'Abandoned cart', 'oli-abandoned-cart-recovery' ),
				esc_html( oli_acr_duration_label( $tpl['delay'] ) ),
				'yes' === $tpl['coupon_enabled'] ? esc_html( wc_format_localized_decimal( $tpl['coupon_amount'] ) . ( 'percent' === $tpl['coupon_type'] ? ' %' : ' ' . get_woocommerce_currency_symbol() ) ) . wp_kses_post( self::coupon_missing_warning( (string) $id, OLI_ACR_Templates::get( (string) $id ) ) ) : '—',
				'yes' === $tpl['active'] ? '<mark class="oli-acr-status oli-acr-status-recovered">' . esc_html__( 'Active', 'oli-abandoned-cart-recovery' ) . '</mark>' : '<mark class="oli-acr-status">' . esc_html__( 'Inactive', 'oli-abandoned-cart-recovery' ) . '</mark>',
				esc_url(
					self::url(
						'templates',
						array(
							'edit' => $id,
							'lang' => $lang,
						)
					)
				),
				esc_html__( 'Edit', 'oli-abandoned-cart-recovery' ),
				esc_url( $delete ),
				esc_js( __( 'Delete this template?', 'oli-abandoned-cart-recovery' ) ),
				esc_html__( 'Delete', 'oli-abandoned-cart-recovery' )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Cart templates are sent in sequence, from the shortest delay to the longest, counted from the moment the cart was abandoned. Pending order templates are counted from the pending order delay set in Settings.', 'oli-abandoned-cart-recovery' ) . '</p>';
		self::render_test_form( '', $lang );
	}

	/**
	 * Langue de l'onglet choisi (paramètre lang), sinon langue de l'admin.
	 *
	 * @return string
	 */
	public static function tab_language() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture seule d'un onglet.
		$lang = isset( $_GET['lang'] ) ? OLI_ACR_Lang::normalize( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ) : '';
		return '' !== $lang ? $lang : OLI_ACR_Lang::admin_language();
	}

	/**
	 * Onglets de langue.
	 *
	 * @param string $base    URL de base.
	 * @param string $current Langue affichée.
	 * @return void
	 */
	private static function language_tabs( $base, $current ) {
		$languages = OLI_ACR_Lang::languages();
		if ( count( $languages ) < 2 ) {
			return;
		}
		echo '<h2 class="nav-tab-wrapper oli-acr-lang-tabs">';
		foreach ( $languages as $locale ) {
			$label = OLI_ACR_Lang::label( $locale );
			if ( OLI_ACR_Lang::fallback_language() === $locale ) {
				/* translators: %s: language name. */
				$label = sprintf( __( '%s — fallback', 'oli-abandoned-cart-recovery' ), $label );
			}
			printf( '<a href="%s" class="nav-tab%s" data-lang="%s">%s</a>', esc_url( add_query_arg( 'lang', $locale, $base ) ), $locale === $current ? ' nav-tab-active' : '', esc_attr( $locale ), esc_html( $label ) );
		}
		echo '</h2>';
	}

	/**
	 * Nom d'un modèle dans la langue de l'admin (journal, tableau de bord).
	 *
	 * @param string $id ID du modèle.
	 * @return string
	 */
	public static function template_name( $id ) {
		$tpl = OLI_ACR_Templates::get( $id );
		if ( ! $tpl ) {
			return $id;
		}
		$tpl = OLI_ACR_Templates::for_locale( $id, $tpl, OLI_ACR_Lang::admin_language() );
		return (string) $tpl['name'];
	}

	/**
	 * Formulaire « Envoyer un test ».
	 *
	 * @param string $template_id Modèle présélectionné.
	 * @param string $lang        Langue présélectionnée.
	 * @return void
	 */
	private static function render_test_form( $template_id, $lang = '' ) {
		$lang = '' !== $lang ? $lang : OLI_ACR_Lang::admin_language();
		echo '<h2>' . esc_html__( 'Send a test', 'oli-abandoned-cart-recovery' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oli-acr-test">';
		wp_nonce_field( 'oli_acr_test_email' );
		echo '<input type="hidden" name="action" value="oli_acr_test_email"><select name="template">';
		foreach ( OLI_ACR_Templates::all() as $id => $tpl ) {
			$tpl = OLI_ACR_Templates::for_locale( (string) $id, $tpl, $lang );
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $template_id, $id, false ), esc_html( $tpl['name'] ) );
		}
		echo '</select> ';
		if ( count( OLI_ACR_Lang::languages() ) > 1 ) {
			echo '<select name="lang" aria-label="' . esc_attr__( 'Language', 'oli-abandoned-cart-recovery' ) . '">';
			foreach ( OLI_ACR_Lang::languages() as $locale ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $locale ), selected( $lang, $locale, false ), esc_html( OLI_ACR_Lang::label( $locale ) ) );
			}
			echo '</select> ';
		}
		echo '<input type="email" name="to" required class="regular-text" value="' . esc_attr( wp_get_current_user()->user_email ) . '"> ';
		submit_button( __( 'Send a test', 'oli-abandoned-cart-recovery' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Formulaire d'édition d'un modèle.
	 *
	 * @param array<string, mixed>|null $tpl Modèle.
	 * @return void
	 */
	private static function render_template_form( $tpl ) {
		$tpl      = $tpl ? $tpl : OLI_ACR_Templates::blank();
		$lang     = self::tab_language();
		$own      = isset( $tpl['texts'][ $lang ] ) && is_array( $tpl['texts'][ $lang ] ) ? $tpl['texts'][ $lang ] : array();
		$resolved = $tpl['id'] ? OLI_ACR_Templates::for_locale( (string) $tpl['id'], $tpl, $lang ) : $tpl;
		$value    = static function ( $field ) use ( $own, $resolved ) {
			return isset( $own[ $field ] ) && '' !== (string) $own[ $field ] ? (string) $own[ $field ] : (string) $resolved[ $field ];
		};
		echo '<p><a href="' . esc_url( self::url( 'templates', array( 'lang' => $lang ) ) ) . '">&larr; ' . esc_html__( 'Back to templates', 'oli-abandoned-cart-recovery' ) . '</a></p>';
		self::language_tabs( self::url( 'templates', array( 'edit' => $tpl['id'] ? $tpl['id'] : 'new' ) ), $lang );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oli_acr_save_template' );
		echo '<input type="hidden" name="action" value="oli_acr_save_template"><input type="hidden" name="t[id]" value="' . esc_attr( $tpl['id'] ) . '"><input type="hidden" name="t[lang]" value="' . esc_attr( $lang ) . '">';
		$field = 't[texts][' . $lang . ']';
		if ( $tpl['id'] && array() === array_filter( array_map( 'strval', $own ) ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: language name. */
					__( 'No text saved yet for %s. The fields show what is sent in this language now (string translation, translated default text or fallback language). Save to keep your own version.', 'oli-abandoned-cart-recovery' ),
					OLI_ACR_Lang::label( $lang )
				)
			) . '</p></div>';
		}
		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %s: language name. */
				__( 'Texts — %s', 'oli-abandoned-cart-recovery' ),
				OLI_ACR_Lang::label( $lang )
			)
		) . '</h2><table class="form-table oli-acr-lang-panel" role="presentation" lang="' . esc_attr( str_replace( '_', '-', $lang ) ) . '">';
		printf( '<tr><th>%s</th><td><input type="text" required class="regular-text" name="%s[name]" value="%s"></td></tr>', esc_html__( 'Template name', 'oli-abandoned-cart-recovery' ), esc_attr( $field ), esc_attr( $value( 'name' ) ) );
		printf( '<tr><th>%s</th><td><input type="text" required class="large-text" name="%s[subject]" value="%s"></td></tr>', esc_html__( 'Email subject', 'oli-abandoned-cart-recovery' ), esc_attr( $field ), esc_attr( $value( 'subject' ) ) );
		printf( '<tr><th>%s</th><td><input type="text" class="large-text" name="%s[heading]" value="%s"></td></tr>', esc_html__( 'Email heading', 'oli-abandoned-cart-recovery' ), esc_attr( $field ), esc_attr( $value( 'heading' ) ) );
		printf( '<tr><th>%s</th><td><input type="text" class="regular-text" name="%s[button_label]" value="%s"></td></tr>', esc_html__( 'Recovery button label', 'oli-abandoned-cart-recovery' ), esc_attr( $field ), esc_attr( $value( 'button_label' ) ) );
		echo '<tr><th>' . esc_html__( 'Email content', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		wp_editor(
			$value( 'content' ),
			'oli_acr_content',
			array(
				'textarea_name' => $field . '[content]',
				'textarea_rows' => 14,
				'media_buttons' => false,
			)
		);
		echo '<p class="description">' . esc_html__( 'Placeholders:', 'oli-abandoned-cart-recovery' ) . '</p><ul class="oli-acr-placeholders">';
		foreach ( OLI_ACR_Mailer::placeholders() as $tag => $label ) {
			printf( '<li><code>%s</code> %s</li>', esc_html( $tag ), esc_html( $label ) );
		}
		echo '</ul></td></tr></table>';

		echo '<h2>' . esc_html__( 'Settings (all languages)', 'oli-abandoned-cart-recovery' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Active', 'oli-abandoned-cart-recovery' ) . '</th><td><label><input type="checkbox" name="t[active]" value="1"' . checked( 'yes', $tpl['active'], false ) . '> ' . esc_html__( 'Send this template automatically', 'oli-abandoned-cart-recovery' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Type', 'oli-abandoned-cart-recovery' ) . '</th><td><select name="t[type]"><option value="cart"' . selected( 'cart', $tpl['type'], false ) . '>' . esc_html__( 'Abandoned cart', 'oli-abandoned-cart-recovery' ) . '</option><option value="order"' . selected( 'order', $tpl['type'], false ) . '>' . esc_html__( 'Pending order', 'oli-abandoned-cart-recovery' ) . '</option></select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Send after', 'oli-abandoned-cart-recovery' ) . '</th><td>';
		self::duration_field( 't[delay]', $tpl['delay'] );
		echo '</td></tr>';
		printf( '<tr><th>%s</th><td><input type="email" class="regular-text" name="t[reply_to]" value="%s" placeholder="%s"></td></tr>', esc_html__( 'Reply-to address', 'oli-abandoned-cart-recovery' ), esc_attr( $tpl['reply_to'] ), esc_attr( oli_acr_get_setting( 'reply_to' ) ) );
		echo '<tr><th>' . esc_html__( 'Coupon', 'oli-abandoned-cart-recovery' ) . '</th><td><label><input type="checkbox" name="t[coupon_enabled]" value="1"' . checked( 'yes', $tpl['coupon_enabled'], false ) . '> ' . esc_html__( 'Create a unique single-use coupon for each email ({coupon})', 'oli-abandoned-cart-recovery' ) . '</label><br>';
		printf( '<input type="number" step="0.01" min="0" class="small-text" name="t[coupon_amount]" value="%s"> ', esc_attr( $tpl['coupon_amount'] ) );
		echo '<select name="t[coupon_type]"><option value="percent"' . selected( 'percent', $tpl['coupon_type'], false ) . '>' . esc_html__( 'Percentage discount', 'oli-abandoned-cart-recovery' ) . '</option><option value="fixed_cart"' . selected( 'fixed_cart', $tpl['coupon_type'], false ) . '>' . esc_html__( 'Fixed cart discount', 'oli-abandoned-cart-recovery' ) . '</option></select> ';
		printf( '%s <input type="number" min="0" class="small-text" name="t[coupon_validity]" value="%d"> %s', esc_html__( 'valid for', 'oli-abandoned-cart-recovery' ), (int) $tpl['coupon_validity'], esc_html__( 'days (0 = no expiry)', 'oli-abandoned-cart-recovery' ) );
		echo wp_kses_post( self::coupon_missing_warning( (string) $tpl['id'], $tpl ) );
		echo '</td></tr></table>';
		submit_button( __( 'Save template', 'oli-abandoned-cart-recovery' ) );
		echo '</form>';
		if ( $tpl['id'] ) {
			self::render_test_form( $tpl['id'], $lang );
		}
	}

	/**
	 * Enregistre un modèle.
	 *
	 * @return void
	 */
	public static function save_template() {
		self::check_cap();
		check_admin_referer( 'oli_acr_save_template' );
		$raw      = isset( $_POST['t'] ) && is_array( $_POST['t'] ) ? wp_unslash( $_POST['t'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nettoyé par OLI_ACR_Templates::sanitize().
		$existing = ! empty( $raw['id'] ) ? OLI_ACR_Templates::get( sanitize_key( $raw['id'] ) ) : null;
		$lang     = isset( $raw['lang'] ) ? OLI_ACR_Lang::normalize( sanitize_text_field( $raw['lang'] ) ) : '';
		$lang     = '' !== $lang ? $lang : OLI_ACR_Lang::fallback_language();
		$tpl      = OLI_ACR_Templates::sanitize( $raw, $existing );
		if ( $existing && ( ! isset( $existing['texts'][ $lang ] ) || array() === array_filter( array_map( 'strval', (array) $existing['texts'][ $lang ] ) ) ) && isset( $tpl['texts'][ $lang ] ) ) {
			// Langue sans texte propre : les champs affichaient le texte envoyé actuellement ; s'ils n'ont pas
			// été modifiés, on ne les fige pas (la langue continue de suivre traductions et repli).
			$resolved = OLI_ACR_Templates::for_locale( (string) $existing['id'], $existing, $lang );
			$same     = true;
			foreach ( OLI_ACR_Templates::TEXT_FIELDS as $field ) {
				if ( oli_acr_normalize_text( wpautop( (string) $tpl['texts'][ $lang ][ $field ] ) ) !== oli_acr_normalize_text( wpautop( (string) $resolved[ $field ] ) ) ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				unset( $tpl['texts'][ $lang ] );
				$tpl = OLI_ACR_Templates::mirror_fallback( $tpl );
			}
		}
		$id = OLI_ACR_Templates::save( $tpl );
		OLI_ACR_Lang::register_strings();
		self::redirect(
			'templates',
			'tpl_saved',
			array(
				'edit' => $id,
				'lang' => $lang,
			)
		);
	}

	/**
	 * Supprime un modèle.
	 *
	 * @return void
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
	 *
	 * @return void
	 */
	public static function test_email() {
		self::check_cap();
		check_admin_referer( 'oli_acr_test_email' );
		$to   = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$id   = isset( $_POST['template'] ) ? sanitize_key( $_POST['template'] ) : '';
		$lang = isset( $_POST['lang'] ) ? OLI_ACR_Lang::normalize( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		$lang = '' !== $lang ? $lang : OLI_ACR_Lang::admin_language();
		$tpl  = OLI_ACR_Templates::get( $id );
		$ok   = $tpl && is_email( $to ) && OLI_ACR_Mailer::send_test( $to, $tpl, $lang );
		self::redirect(
			'templates',
			$ok ? 'test_sent' : 'test_failed',
			array(
				'edit' => $id,
				'lang' => $lang,
			)
		);
	}

	/**
	 * B2 : Polylang (gratuit) seul ne fait pas reconnaître les pages de paiement traduites par WooCommerce.
	 * Aucun avis si « Polylang for WooCommerce » est actif ou si un filtre relie déjà la page de paiement.
	 *
	 * @return bool
	 */
	public static function polylang_needs_wc_bridge() {
		if ( 'polylang' !== OLI_ACR_Lang::adapter()->id() || count( OLI_ACR_Lang::languages() ) < 2 ) {
			return false;
		}
		$bridged = defined( 'PLLWC_VERSION' ) || function_exists( 'PLLWC' ) || has_filter( 'woocommerce_get_checkout_page_id' );
		/**
		 * Filtre : vrai si les pages de paiement traduites par Polylang sont reconnues par WooCommerce.
		 *
		 * @param bool $bridged Détection automatique.
		 */
		return ! apply_filters( 'oli_acr_polylang_checkout_bridged', $bridged );
	}

	/**
	 * B4 : avertissement quand le coupon est activé mais que le courriel ne montre pas son code.
	 *
	 * @param string            $id  ID du modèle.
	 * @param array<mixed>|null $tpl Modèle (non résolu).
	 * @return string HTML (vide si tout va bien).
	 */
	public static function coupon_missing_warning( $id, $tpl ) {
		if ( ! is_array( $tpl ) ) {
			return '';
		}
		$missing = OLI_ACR_Templates::coupon_missing_locales( $id, $tpl );
		if ( empty( $missing ) ) {
			return '';
		}
		return '<p class="oli-acr-coupon-missing" style="color:#b32d2e"><strong>' . esc_html__( 'No coupon will be created:', 'oli-abandoned-cart-recovery' ) . '</strong> ' . esc_html(
			sprintf(
				/* translators: %s: list of languages. */
				__( 'the email does not contain {coupon} or {coupon_code} (%s). Add one of these tags to the content to send the coupon.', 'oli-abandoned-cart-recovery' ),
				implode( ', ', array_map( array( 'OLI_ACR_Lang', 'label' ), $missing ) )
			)
		) . '</p>';
	}

	/**
	 * Avis généraux de l'administration (toutes les pages, utilisateurs autorisés seulement).
	 *
	 * - Consentement désactivé : avertissement permanent (Loi 25 et RGPD).
	 * - Migration R2 : le mode « toujours » est passé au consentement (jusqu'à fermeture).
	 * - Échecs d'envoi des relances (jusqu'à fermeture).
	 *
	 * @return void
	 */
	public static function global_notices() {
		if ( ! current_user_can( OLI_ACR_CAP ) ) {
			return;
		}
		if ( 'always' === oli_acr_get_setting( 'guest_tracking' ) ) {
			printf(
				'<div class="notice notice-error oli-acr-consent-off"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Oli Abandoned Cart Recovery: consent is turned off.', 'oli-abandoned-cart-recovery' ),
				esc_html__( 'Emails and carts of guests and logged-in customers are saved without asking for consent. Quebec Law 25 and the GDPR generally require explicit consent before saving this data for marketing reminders. You are responsible for having another legal basis.', 'oli-abandoned-cart-recovery' ),
				esc_url( self::url( 'settings' ) ),
				esc_html__( 'Turn consent back on', 'oli-abandoned-cart-recovery' )
			);
		}
		if ( self::polylang_needs_wc_bridge() ) {
			printf(
				'<div class="notice notice-error oli-acr-polylang-wc"><p><strong>%1$s</strong> %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></div>',
				esc_html__( 'Oli Abandoned Cart Recovery: carts on translated checkout pages are not captured.', 'oli-abandoned-cart-recovery' ),
				esc_html__( 'Polylang is active without "Polylang for WooCommerce", so WooCommerce does not recognize the translated checkout pages: the capture script is not loaded there and no cart is saved in those languages. Install "Polylang for WooCommerce", or link the translated cart and checkout pages to WooCommerce with the woocommerce_get_checkout_page_id filter (see the plugin FAQ).', 'oli-abandoned-cart-recovery' ),
				esc_url( 'https://polylang.pro/downloads/polylang-for-woocommerce/' ),
				esc_html__( 'Polylang for WooCommerce', 'oli-abandoned-cart-recovery' )
			);
		}
		// B3 : jamais à côté de l'avertissement « consentement désactivé » (les deux messages se contrediraient).
		if ( get_option( 'oli_acr_notice_consent_migrated' ) && oli_acr_consent_required() ) {
			printf(
				'<div class="notice notice-warning oli-acr-consent-migrated"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a> | <a href="%5$s">%6$s</a></p></div>',
				esc_html__( 'Oli Abandoned Cart Recovery 1.1.0: consent is now required.', 'oli-abandoned-cart-recovery' ),
				esc_html__( 'Your store saved guest carts without consent ("Always" mode). To comply with Quebec Law 25 and the GDPR, the update turned consent on: guests and logged-in customers are now tracked only when they check the consent box at checkout. You can turn it off again in the settings, under your own responsibility.', 'oli-abandoned-cart-recovery' ),
				esc_url( self::url( 'settings' ) ),
				esc_html__( 'Review the settings', 'oli-abandoned-cart-recovery' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oli_acr_dismiss_notice&notice=consent_migrated' ), 'oli_acr_dismiss_consent_migrated' ) ),
				esc_html__( 'Dismiss', 'oli-abandoned-cart-recovery' )
			);
		}
		$failure = get_option( 'oli_acr_mail_failure' );
		if ( is_array( $failure ) && ! empty( $failure['count'] ) ) {
			printf(
				'<div class="notice notice-error oli-acr-mail-failure"><p><strong>%1$s</strong> %2$s <code>%3$s</code> %4$s <a href="%5$s">%6$s</a> | <a href="%7$s">%8$s</a> | <a href="%9$s">%10$s</a></p></div>',
				esc_html__( 'Oli Abandoned Cart Recovery: some reminders could not be sent.', 'oli-abandoned-cart-recovery' ),
				/* translators: 1: number of failures, 2: date and time of the last failure (site format). */
				esc_html( sprintf( _n( '%1$d failure, the last one: %2$s.', '%1$d failures, the last one: %2$s.', (int) $failure['count'], 'oli-abandoned-cart-recovery' ), (int) $failure['count'], oli_acr_format_datetime( (int) $failure['time'] ) ) ) . ' ' . esc_html__( 'Error:', 'oli-abandoned-cart-recovery' ),
				esc_html( (string) $failure['error'] ),
				esc_html__( 'Failed reminders are retried automatically. Check your mail settings (SMTP).', 'oli-abandoned-cart-recovery' ),
				esc_url( self::url( 'carts', array( 'status' => 'failed' ) ) ),
				esc_html__( 'Failed carts', 'oli-abandoned-cart-recovery' ),
				esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=oli-abandoned-cart-recovery' ) ),
				esc_html__( 'Logs', 'oli-abandoned-cart-recovery' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oli_acr_dismiss_notice&notice=mail_failure' ), 'oli_acr_dismiss_mail_failure' ) ),
				esc_html__( 'Dismiss', 'oli-abandoned-cart-recovery' )
			);
		}
	}

	/**
	 * Ferme un avis (nonce par avis).
	 *
	 * @return void
	 */
	public static function dismiss_notice() {
		self::check_cap();
		$notice = isset( $_GET['notice'] ) ? sanitize_key( $_GET['notice'] ) : '';
		if ( ! in_array( $notice, array( 'consent_migrated', 'mail_failure' ), true ) ) {
			wp_die( esc_html__( 'Unknown notice.', 'oli-abandoned-cart-recovery' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'oli_acr_dismiss_' . $notice );
		delete_option( 'consent_migrated' === $notice ? 'oli_acr_notice_consent_migrated' : 'oli_acr_mail_failure' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url( 'dashboard' ) );
		exit;
	}

	/**
	 * Actions sur un panier : envoi immédiat ou suppression.
	 *
	 * @return void
	 */
	public static function cart_action() {
		self::check_cap();
		$id = isset( $_GET['cart'] ) ? absint( $_GET['cart'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		// Nonce lié à l'action et au panier.
		check_admin_referer( 'oli_acr_cart_action_' . $do . '_' . $id );
		$cart = OLI_ACR_Carts::get( $id );
		if ( ! $cart ) {
			self::redirect( 'carts', 'not_sent' );
		}
		if ( 'delete' === $do ) {
			OLI_ACR_Carts::delete( $id );
			self::redirect( 'carts', 'deleted' );
		}
		// Seuls les paniers encore actifs peuvent être relancés (pas récupérés, désabonnés ni terminés).
		if ( 'send' !== $do || ! in_array( $cart->status, OLI_ACR_Carts::LIVE_STATUSES, true ) || oli_acr_is_unsubscribed( $cart->email ) || (int) $cart->order_id > 0 || ! OLI_ACR_Scheduler::cart_owner_tracked( $cart ) ) {
			self::redirect( 'carts', 'not_sent' );
		}
		$templates = OLI_ACR_Templates::active( 'cart' );
		$done      = array_filter( explode( ',', (string) $cart->sent_templates ) );
		$next      = OLI_ACR_Scheduler::next_template( $templates, $done );
		if ( ! $next ) {
			self::redirect( 'carts', 'not_sent' );
		}
		if ( empty( $cart->abandoned_at ) ) {
			$cart->abandoned_at = oli_acr_now();
			OLI_ACR_Carts::update( $id, array( 'abandoned_at' => $cart->abandoned_at ) );
		}
		// Envoi manuel : le compteur d'échecs repart de zéro.
		$cart->fail_count = 0;
		$ok               = OLI_ACR_Mailer::send_cart_email( $cart, $next['id'], $next );
		if ( ! $ok ) {
			OLI_ACR_Scheduler::cart_failed( $cart, $next['id'] );
		}
		if ( $ok ) {
			// La suite de la séquence continue automatiquement.
			$done[]    = $next['id'];
			$following = OLI_ACR_Scheduler::next_template( $templates, $done );
			$base      = (int) strtotime( $cart->abandoned_at . ' UTC' );
			OLI_ACR_Carts::update( $id, array( 'next_send_at' => $following ? gmdate( 'Y-m-d H:i:s', max( time() + 60, $base + oli_acr_duration_to_seconds( $following['delay'] ) ) ) : null ) );
		}
		self::redirect( 'carts', $ok ? 'sent' : 'not_sent' );
	}

	/**
	 * Envoi immédiat d'une relance de commande en attente.
	 *
	 * @return void
	 */
	public static function order_action() {
		self::check_cap();
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'oli_acr_order_action_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && $order->has_status( 'pending' ) && ! $order->get_meta( '_oli_acr_done' ) ) {
			$done = array_filter( (array) $order->get_meta( '_oli_acr_sent' ) );
			foreach ( OLI_ACR_Templates::active( 'order' ) as $tpl_id => $tpl ) {
				if ( ! in_array( $tpl_id, $done, true ) ) {
					$order->delete_meta_data( '_oli_acr_fail_count' );
					$order->delete_meta_data( '_oli_acr_retry_at' );
					$ok = OLI_ACR_Mailer::send_order_email( $order, $tpl_id, $tpl );
					if ( ! $ok ) {
						OLI_ACR_Scheduler::order_failed( $order, $tpl_id );
					} else {
						$order->save();
					}
					self::redirect( 'pending', $ok ? 'sent' : 'not_sent' );
				}
			}
		}
		self::redirect( 'pending', 'not_sent' );
	}
}
