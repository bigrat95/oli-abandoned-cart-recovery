<?php
/**
 * Liens de récupération, désabonnement et conversion des commandes.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Récupération.
 */
class OLI_ACR_Recovery {

	/**
	 * Accroches.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'handle_links' ), 30 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_unsubscribe' ), 5 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_order_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'link_order' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status_changed' ), 20, 4 );
	}

	/**
	 * Statuts de commande considérés comme « commande passée ».
	 *
	 * @return string[]
	 */
	public static function paid_statuses() {
		/**
		 * Filtre les statuts qui confirment une récupération.
		 *
		 * @param string[] $statuses Statuts.
		 */
		return apply_filters( 'oli_acr_recovered_order_statuses', array( 'processing', 'completed', 'on-hold' ) );
	}

	/**
	 * Traite les liens de récupération (panier) et de paiement (commande en attente).
	 *
	 * @return void
	 */
	public static function handle_links() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Liens publics signés par jeton aléatoire.
		if ( isset( $_GET['oli_acr_recover'] ) ) {
			$token  = sanitize_text_field( wp_unslash( $_GET['oli_acr_recover'] ) );
			$log_id = isset( $_GET['oli_acr_log'] ) ? absint( $_GET['oli_acr_log'] ) : 0;
			self::recover_cart( $token, $log_id );
		} elseif ( isset( $_GET['oli_acr_pay'] ) ) {
			$key    = sanitize_text_field( wp_unslash( $_GET['oli_acr_pay'] ) );
			$log_id = isset( $_GET['oli_acr_log'] ) ? absint( $_GET['oli_acr_log'] ) : 0;
			self::recover_order( $key, $log_id );
		}
		// phpcs:enable
	}

	/**
	 * Remplit le panier à partir du jeton et redirige au checkout.
	 *
	 * @param string $token  Jeton.
	 * @param int    $log_id Journal.
	 * @return void
	 */
	public static function recover_cart( $token, $log_id ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		$cart = OLI_ACR_Carts::get_by_token( $token );
		if ( ! $cart || ! in_array( $cart->status, OLI_ACR_Carts::LIVE_STATUSES, true ) ) {
			wc_add_notice( __( 'This cart link has expired or was already used.', 'oli-abandoned-cart-recovery' ), 'notice' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		// Clic enregistré une seule fois.
		$log = $log_id ? OLI_ACR_Mailer::get_log( $log_id ) : null;
		if ( $log && 'cart' === $log->object_type && (int) $log->object_id === (int) $cart->id ) {
			if ( empty( $log->clicked_at ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( oli_acr_table( 'log' ), array( 'clicked_at' => oli_acr_now() ), array( 'id' => (int) $log->id ) );
			}
			WC()->session->set( 'oli_acr_log_id', (int) $log->id );
		} else {
			$log = null;
		}
		if ( empty( $cart->clicked_at ) && $log ) {
			OLI_ACR_Carts::update( $cart->id, array( 'clicked_at' => oli_acr_now() ) );
		}

		WC()->session->set( OLI_ACR_Carts::SESSION_KEY, (int) $cart->id );

		// Reconstruit le panier.
		WC()->cart->empty_cart();
		foreach ( OLI_ACR_Carts::items( $cart ) as $item ) {
			$product = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product || ! $product->is_purchasable() ) {
				continue;
			}
			WC()->cart->add_to_cart( (int) $item['product_id'], max( 1, (int) $item['quantity'] ), (int) $item['variation_id'], isset( $item['variation'] ) ? (array) $item['variation'] : array() );
		}

		// Préremplit les coordonnées.
		if ( WC()->customer ) {
			WC()->customer->set_billing_email( $cart->email );
			if ( $cart->first_name ) {
				WC()->customer->set_billing_first_name( $cart->first_name );
			}
			if ( $cart->last_name ) {
				WC()->customer->set_billing_last_name( $cart->last_name );
			}
			if ( $cart->phone ) {
				WC()->customer->set_billing_phone( $cart->phone );
			}
			WC()->customer->save();
		}

		// Applique le coupon de la relance cliquée.
		if ( $log && '' !== $log->coupon_code && ! WC()->cart->has_discount( $log->coupon_code ) ) {
			WC()->cart->apply_coupon( $log->coupon_code );
		}

		/**
		 * Déclenché après la reconstitution d'un panier depuis un lien.
		 *
		 * @param object $cart Panier.
		 */
		do_action( 'oli_acr_cart_restored', $cart );

		// Paiement dans la langue du panier (page traduite ou URL de la langue).
		wp_safe_redirect( OLI_ACR_Lang::checkout_url( OLI_ACR_Lang::resolve( (string) $cart->language ) ) );
		exit;
	}

	/**
	 * Lien d'une relance de commande en attente : enregistre le clic et redirige vers le paiement.
	 *
	 * @param string $key    Clé de commande.
	 * @param int    $log_id Journal.
	 * @return void
	 */
	public static function recover_order( $key, $log_id ) {
		$order_id = wc_get_order_id_by_order_key( $key );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		$log = $log_id ? OLI_ACR_Mailer::get_log( $log_id ) : null;
		if ( $log && 'order' === $log->object_type && (int) $log->object_id === (int) $order_id ) {
			if ( empty( $log->clicked_at ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( oli_acr_table( 'log' ), array( 'clicked_at' => oli_acr_now() ), array( 'id' => (int) $log->id ) );
			}
			$order->update_meta_data( '_oli_acr_clicked_log', (int) $log->id );
			$order->save();
		}
		$url = $order->needs_payment() ? $order->get_checkout_payment_url() : $order->get_view_order_url();
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Page de désabonnement (confirmation par bouton pour éviter les clics des antipourriels).
	 *
	 * @return void
	 */
	public static function handle_unsubscribe() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['oli_acr_unsub'], $_GET['oli_acr_sig'] ) ) {
			return;
		}
		$email = sanitize_email( wp_unslash( $_GET['oli_acr_unsub'] ) );
		$sig   = sanitize_text_field( wp_unslash( $_GET['oli_acr_sig'] ) );
		$lang  = isset( $_GET['oli_acr_lang'] ) ? OLI_ACR_Lang::normalize( sanitize_text_field( wp_unslash( $_GET['oli_acr_lang'] ) ) ) : '';
		// phpcs:enable
		if ( '' !== $lang ) {
			// Page affichée dans la langue du courriel (wp_die ne revient pas : pas besoin de restaurer).
			OLI_ACR_Lang::adapter()->switch_language( $lang );
			switch_to_locale( $lang );
		}
		$home  = OLI_ACR_Lang::home_url( '' !== $lang ? $lang : OLI_ACR_Lang::current_language() );
		$title = __( 'Unsubscribe', 'oli-abandoned-cart-recovery' );

		if ( ! is_email( $email ) || ! hash_equals( oli_acr_email_signature( $email ), $sig ) ) {
			wp_die( esc_html__( 'This unsubscribe link is not valid.', 'oli-abandoned-cart-recovery' ), esc_html( $title ), array( 'response' => 400 ) );
		}

		$confirmed = isset( $_POST['oli_acr_confirm'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'oli_acr_unsub_' . $email );
		// Désabonnement en un clic (RFC 8058) : POST avec List-Unsubscribe=One-Click.
		$one_click = isset( $_POST['List-Unsubscribe'] ) && 'One-Click' === sanitize_text_field( wp_unslash( $_POST['List-Unsubscribe'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $confirmed || $one_click || oli_acr_is_unsubscribed( $email ) ) {
			oli_acr_unsubscribe( $email );
			$message = '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html(
				sprintf(
					/* translators: %s: email address. */
					__( '%s will no longer receive cart reminder emails from this store.', 'oli-abandoned-cart-recovery' ),
					$email
				)
			) . '</p><p><a href="' . esc_url( $home ) . '">' . esc_html__( 'Back to the store', 'oli-abandoned-cart-recovery' ) . '</a></p>';
			wp_die( wp_kses_post( $message ), esc_html( $title ), array( 'response' => 200 ) );
		}

		$form  = '<h1>' . esc_html( $title ) . '</h1>';
		$form .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: email address. */
				__( 'Stop receiving cart reminder emails at %s?', 'oli-abandoned-cart-recovery' ),
				$email
			)
		) . '</p>';
		$form .= '<form method="post"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'oli_acr_unsub_' . $email ) ) . '"><input type="hidden" name="oli_acr_confirm" value="1"><button type="submit" class="button">' . esc_html__( 'Yes, unsubscribe me', 'oli-abandoned-cart-recovery' ) . '</button></form>';
		wp_die( $form, esc_html( $title ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Contenu échappé ci-dessus.
	}

	/**
	 * Commande du checkout classique.
	 *
	 * @param int                  $order_id    ID.
	 * @param array<string, mixed> $posted_data Données.
	 * @param WC_Order             $order       Commande.
	 * @return void
	 */
	public static function classic_order_processed( $order_id, $posted_data = array(), $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order ) {
			self::link_order( $order );
		}
	}

	/**
	 * Relie la commande au panier suivi (session, sinon courriel) et arrête les relances.
	 *
	 * @param WC_Order $order Commande.
	 * @return void
	 */
	public static function link_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$cart = null;
		if ( WC()->session ) {
			$sid = absint( WC()->session->get( OLI_ACR_Carts::SESSION_KEY ) );
			if ( $sid ) {
				$row = OLI_ACR_Carts::get( $sid );
				if ( $row && in_array( $row->status, OLI_ACR_Carts::LIVE_STATUSES, true ) ) {
					$cart = $row;
				}
			}
			if ( ! $cart ) {
				$cart = OLI_ACR_Carts::find_live_by_session( OLI_ACR_Carts::current_session_key() );
			}
		}
		if ( ! $cart && is_email( $order->get_billing_email() ) ) {
			$cart = OLI_ACR_Carts::find_live_by_email( $order->get_billing_email() );
		}
		if ( ! $cart ) {
			return;
		}

		OLI_ACR_Carts::update(
			$cart->id,
			array(
				'order_id'     => $order->get_id(),
				'next_send_at' => null,
			)
		);
		$order->update_meta_data( '_oli_acr_cart_id', (int) $cart->id );
		if ( WC()->session && WC()->session->get( 'oli_acr_log_id' ) ) {
			$order->update_meta_data( '_oli_acr_clicked_log', absint( WC()->session->get( 'oli_acr_log_id' ) ) );
		}
		$order->save();

		if ( WC()->session ) {
			WC()->session->set( OLI_ACR_Carts::SESSION_KEY, null );
			WC()->session->set( 'oli_acr_log_id', null );
			WC()->session->set( 'oli_acr_cart_hash', null );
		}

		if ( $order->has_status( self::paid_statuses() ) ) {
			self::mark_cart_recovered( $order );
		}
	}

	/**
	 * Changement de statut : confirme la récupération d'un panier ou d'une commande en attente.
	 *
	 * @param int      $order_id ID.
	 * @param string   $from     Ancien statut.
	 * @param string   $to       Nouveau statut.
	 * @param WC_Order $order    Commande.
	 * @return void
	 */
	public static function order_status_changed( $order_id, $from, $to, $order ) {
		if ( ! in_array( $to, self::paid_statuses(), true ) || ! $order instanceof WC_Order ) {
			return;
		}
		if ( $order->get_meta( '_oli_acr_cart_id' ) ) {
			self::mark_cart_recovered( $order );
		}
		if ( 'pending' === $from && $order->get_meta( '_oli_acr_sent' ) && ! $order->get_meta( '_oli_acr_recovered' ) ) {
			self::mark_order_recovered( $order );
		}
	}

	/**
	 * Marque le panier lié comme récupéré.
	 *
	 * @param WC_Order $order Commande.
	 * @return void
	 */
	public static function mark_cart_recovered( $order ) {
		global $wpdb;
		$cart = OLI_ACR_Carts::get( absint( $order->get_meta( '_oli_acr_cart_id' ) ) );
		if ( ! $cart || 'recovered' === $cart->status ) {
			return;
		}
		$via = (int) $cart->emails_sent > 0 ? 'email' : 'direct';
		OLI_ACR_Carts::update(
			$cart->id,
			array(
				'status'          => 'recovered',
				'order_id'        => $order->get_id(),
				'recovered_via'   => $via,
				'recovered_total' => (float) $order->get_total(),
				'recovered_at'    => oli_acr_now(),
				'next_send_at'    => null,
			)
		);

		if ( 'email' !== $via ) {
			return;
		}

		// Attribution au courriel cliqué, sinon au dernier courriel envoyé.
		$log_id = absint( $order->get_meta( '_oli_acr_clicked_log' ) );
		if ( ! $log_id ) {
			$table = oli_acr_table( 'log' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
			$log_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_type = 'cart' AND object_id = %d ORDER BY id DESC LIMIT 1", $cart->id ) );
		}
		self::mark_log_recovered( $log_id, $order );
		$order->update_meta_data( '_oli_acr_recovered', 'cart' );
		$order->add_order_note( __( 'Order recovered from an abandoned cart reminder (Oli Abandoned Cart Recovery).', 'oli-abandoned-cart-recovery' ) );
		$order->save();
		self::notify_admin( $order, 'cart' );
	}

	/**
	 * Marque une commande en attente relancée comme récupérée.
	 *
	 * @param WC_Order $order Commande.
	 * @return void
	 */
	public static function mark_order_recovered( $order ) {
		global $wpdb;
		$log_id = absint( $order->get_meta( '_oli_acr_clicked_log' ) );
		if ( ! $log_id ) {
			$table = oli_acr_table( 'log' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
			$log_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_type = 'order' AND object_id = %d ORDER BY id DESC LIMIT 1", $order->get_id() ) );
		}
		self::mark_log_recovered( $log_id, $order );
		$order->update_meta_data( '_oli_acr_recovered', 'order' );
		$order->update_meta_data( '_oli_acr_done', 1 );
		$order->add_order_note( __( 'Pending order recovered after a reminder (Oli Abandoned Cart Recovery).', 'oli-abandoned-cart-recovery' ) );
		$order->save();
		self::notify_admin( $order, 'order' );
	}

	/**
	 * Met à jour une ligne du journal comme récupérée.
	 *
	 * @param int      $log_id ID.
	 * @param WC_Order $order  Commande.
	 * @return void
	 */
	private static function mark_log_recovered( $log_id, $order ) {
		global $wpdb;
		if ( ! $log_id ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			oli_acr_table( 'log' ),
			array(
				'recovered_at'    => oli_acr_now(),
				'recovered_total' => (float) $order->get_total(),
				'order_id'        => $order->get_id(),
			),
			array( 'id' => $log_id )
		);
	}

	/**
	 * Avis à l'admin.
	 *
	 * @param WC_Order $order Commande.
	 * @param string   $type  cart ou order.
	 * @return void
	 */
	private static function notify_admin( $order, $type ) {
		if ( 'yes' !== oli_acr_get_setting( 'admin_notify' ) ) {
			return;
		}
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['OLI_ACR_Email_Admin_Recovered'] ) ) {
			$emails['OLI_ACR_Email_Admin_Recovered']->trigger( $order, $type );
		}
	}
}
