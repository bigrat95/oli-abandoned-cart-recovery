<?php
/**
 * Construction et envoi des courriels de relance, coupons uniques et journal.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Service d'envoi.
 */
class OLI_ACR_Mailer {

	/**
	 * Adresse de réponse et expéditeur temporaires pendant un envoi.
	 *
	 * @var array<mixed>
	 */
	private static $sending = array();

	/**
	 * Cause du dernier échec d'envoi (wp_mail_failed).
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Partie texte du courriel en cours d'envoi (multipart/alternative).
	 *
	 * @var string
	 */
	private static $alt_body = '';

	/**
	 * Accroches.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_emails' ) );
	}

	/**
	 * Ajoute le courriel d'avis à l'admin aux courriels WooCommerce.
	 *
	 * @param array<mixed> $emails Courriels.
	 * @return array<mixed>
	 */
	public static function register_emails( $emails ) {
		require_once OLI_ACR_DIR . 'includes/emails/class-oli-acr-email-admin-recovered.php';
		$emails['OLI_ACR_Email_Admin_Recovered'] = new OLI_ACR_Email_Admin_Recovered();
		return $emails;
	}

	/**
	 * Liste des balises disponibles.
	 *
	 * @return array<mixed>
	 */
	public static function placeholders() {
		return array(
			'{first_name}'       => __( 'Customer first name', 'oli-abandoned-cart-recovery' ),
			'{last_name}'        => __( 'Customer last name', 'oli-abandoned-cart-recovery' ),
			'{full_name}'        => __( 'Customer full name', 'oli-abandoned-cart-recovery' ),
			'{email}'            => __( 'Customer email', 'oli-abandoned-cart-recovery' ),
			'{cart_items}'       => __( 'Table of the cart or order items', 'oli-abandoned-cart-recovery' ),
			'{cart_total}'       => __( 'Cart or order total', 'oli-abandoned-cart-recovery' ),
			'{recovery_link}'    => __( 'Recovery URL', 'oli-abandoned-cart-recovery' ),
			'{recovery_button}'  => __( 'Recovery button', 'oli-abandoned-cart-recovery' ),
			'{coupon}'           => __( 'Coupon box (empty when no coupon)', 'oli-abandoned-cart-recovery' ),
			'{coupon_code}'      => __( 'Coupon code only', 'oli-abandoned-cart-recovery' ),
			'{coupon_amount}'    => __( 'Coupon discount (e.g. 10 %). A paragraph with this tag is removed when there is no coupon.', 'oli-abandoned-cart-recovery' ),
			'{unsubscribe_link}' => __( 'Unsubscribe URL', 'oli-abandoned-cart-recovery' ),
			'{site_name}'        => __( 'Site name', 'oli-abandoned-cart-recovery' ),
			'{site_url}'         => __( 'Site URL', 'oli-abandoned-cart-recovery' ),
			'{order_number}'     => __( 'Order number (pending orders)', 'oli-abandoned-cart-recovery' ),
			'{order_date}'       => __( 'Order date (pending orders)', 'oli-abandoned-cart-recovery' ),
		);
	}

	/**
	 * Envoie un modèle pour un panier abandonné.
	 *
	 * @param object       $cart   Ligne de panier.
	 * @param string       $tpl_id ID du modèle.
	 * @param array<mixed> $tpl    Modèle.
	 * @return bool
	 */
	public static function send_cart_email( $cart, $tpl_id, $tpl ) {
		// Envoi dans la langue du panier (locale WordPress et langue de l'extension multilingue), sinon langue de repli.
		$locale = OLI_ACR_Lang::resolve( (string) $cart->language );
		return (bool) OLI_ACR_Lang::run_in( $locale, array( __CLASS__, 'send_cart_email_now' ), $cart, $tpl_id, $tpl, $locale );
	}

	/**
	 * Envoie un modèle pour un panier abandonné, dans la langue courante.
	 *
	 * @param object               $cart   Ligne de panier.
	 * @param string               $tpl_id ID du modèle.
	 * @param array<string, mixed> $tpl    Modèle.
	 * @param string               $locale Langue d'envoi (vide = langue du panier).
	 * @return bool
	 */
	public static function send_cart_email_now( $cart, $tpl_id, $tpl, $locale = '' ) {
		$locale = '' !== $locale ? $locale : OLI_ACR_Lang::resolve( (string) $cart->language );
		$tpl    = OLI_ACR_Templates::for_locale( $tpl_id, $tpl, $locale );
		if ( oli_acr_is_unsubscribed( $cart->email ) ) {
			return false;
		}
		$log_id = self::insert_log( 'cart', $cart->id, $tpl_id, $cart->email );
		$coupon = self::maybe_create_coupon( $tpl, $cart->email, $log_id );

		$link = add_query_arg(
			array(
				'oli_acr_recover' => $cart->token,
				'oli_acr_log'     => $log_id,
			),
			OLI_ACR_Lang::home_url( $locale )
		);
		/**
		 * Filtre le lien de récupération d'un panier.
		 *
		 * @param string $link   Lien.
		 * @param object $cart   Panier.
		 * @param int    $log_id ID du journal.
		 */
		$link = apply_filters( 'oli_acr_recovery_link', $link, $cart, $log_id );

		$context = array(
			'first_name' => $cart->first_name,
			'last_name'  => $cart->last_name,
			'email'      => $cart->email,
			'items'      => OLI_ACR_Carts::items( $cart ),
			'total'      => (float) $cart->cart_total,
			'currency'   => $cart->currency,
			'link'       => $link,
			'coupon'     => $coupon,
			'locale'     => $locale,
		);

		$sent = self::deliver_template( $tpl, $context );
		if ( ! $sent ) {
			self::delete_log( $log_id );
			self::delete_coupon( $coupon );
			return false;
		}

		$sent_templates   = array_filter( explode( ',', (string) $cart->sent_templates ) );
		$sent_templates[] = $tpl_id;
		OLI_ACR_Carts::update(
			$cart->id,
			array(
				'status'         => 'reminded',
				'fail_count'     => 0,
				'last_error'     => '',
				'emails_sent'    => (int) $cart->emails_sent + 1,
				'sent_templates' => implode( ',', array_unique( $sent_templates ) ),
				'last_email_at'  => oli_acr_now(),
			)
		);
		/**
		 * Déclenché après l'envoi d'une relance de panier.
		 *
		 * @param object $cart   Panier.
		 * @param string $tpl_id Modèle.
		 * @param int    $log_id Journal.
		 */
		do_action( 'oli_acr_cart_email_sent', $cart, $tpl_id, $log_id );
		return true;
	}

	/**
	 * Envoie un modèle pour une commande en attente.
	 *
	 * @param WC_Order     $order  Commande.
	 * @param string       $tpl_id ID du modèle.
	 * @param array<mixed> $tpl    Modèle.
	 * @return bool
	 */
	public static function send_order_email( $order, $tpl_id, $tpl ) {
		$locale = OLI_ACR_Lang::resolve( self::order_language( $order ) );
		return (bool) OLI_ACR_Lang::run_in( $locale, array( __CLASS__, 'send_order_email_now' ), $order, $tpl_id, $tpl, $locale );
	}

	/**
	 * Langue d'une commande : celle du panier lié, sinon celle du compte client, sinon celle du site.
	 *
	 * @param WC_Order $order Commande.
	 * @return string
	 */
	public static function order_language( $order ) {
		$cart_id = absint( $order->get_meta( '_oli_acr_cart_id' ) );
		$cart    = $cart_id ? OLI_ACR_Carts::get( $cart_id ) : null;
		if ( $cart && '' !== (string) $cart->language ) {
			return (string) $cart->language;
		}
		$meta = (string) $order->get_meta( 'wpml_language' );
		if ( '' === $meta ) {
			$meta = (string) $order->get_meta( 'trp_language' );
		}
		if ( '' === $meta && function_exists( 'pll_get_post_language' ) ) {
			$meta = (string) pll_get_post_language( $order->get_id(), 'locale' );
		}
		if ( '' !== $meta ) {
			return $meta;
		}
		if ( $order->get_customer_id() ) {
			return get_user_locale( $order->get_customer_id() );
		}
		return OLI_ACR_Lang::fallback_language();
	}

	/**
	 * Envoie un modèle pour une commande en attente, dans la langue courante.
	 *
	 * @param WC_Order             $order  Commande.
	 * @param string               $tpl_id ID du modèle.
	 * @param array<string, mixed> $tpl    Modèle.
	 * @param string               $locale Langue d'envoi.
	 * @return bool
	 */
	public static function send_order_email_now( $order, $tpl_id, $tpl, $locale = '' ) {
		$locale = '' !== $locale ? $locale : OLI_ACR_Lang::resolve( self::order_language( $order ) );
		$tpl    = OLI_ACR_Templates::for_locale( $tpl_id, $tpl, $locale );
		$email  = strtolower( $order->get_billing_email() );
		if ( ! is_email( $email ) || oli_acr_is_unsubscribed( $email ) ) {
			return false;
		}
		$log_id = self::insert_log( 'order', $order->get_id(), $tpl_id, $email );
		$coupon = self::maybe_create_coupon( $tpl, $email, $log_id );
		$link   = add_query_arg(
			array(
				'oli_acr_pay' => $order->get_order_key(),
				'oli_acr_log' => $log_id,
			),
			OLI_ACR_Lang::home_url( $locale )
		);
		$items  = array();
		foreach ( $order->get_items() as $item ) {
			/**
			 * Articles de type line_item (valeur par défaut de get_items()).
			 *
			 * @var WC_Order_Item_Product $item
			 */
			$items[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => (int) $item->get_quantity(),
				'name'         => $item->get_name(),
				'line_total'   => (float) $item->get_total() + (float) $item->get_total_tax(),
			);
		}
		$context = array(
			'first_name'   => $order->get_billing_first_name(),
			'last_name'    => $order->get_billing_last_name(),
			'email'        => $email,
			'items'        => $items,
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'link'         => $link,
			'coupon'       => $coupon,
			'order_number' => $order->get_order_number(),
			'order_date'   => $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
			'locale'       => $locale,
		);

		$sent = self::deliver_template( $tpl, $context );
		if ( ! $sent ) {
			self::delete_log( $log_id );
			self::delete_coupon( $coupon );
			return false;
		}
		$sent_templates   = (array) $order->get_meta( '_oli_acr_sent' );
		$sent_templates[] = $tpl_id;
		$order->update_meta_data( '_oli_acr_sent', array_values( array_unique( array_filter( $sent_templates ) ) ) );
		$order->update_meta_data( '_oli_acr_last_sent', time() );
		$order->save();
		return true;
	}

	/**
	 * Envoie un courriel de test à partir d'un modèle, dans une langue (par défaut celle de l'admin).
	 *
	 * @param string       $to     Destinataire.
	 * @param array<mixed> $tpl    Modèle.
	 * @param string       $locale Langue.
	 * @return bool
	 */
	public static function send_test( $to, $tpl, $locale = '' ) {
		$locale = '' !== $locale ? OLI_ACR_Lang::resolve( $locale ) : OLI_ACR_Lang::admin_language();
		return (bool) OLI_ACR_Lang::run_in( $locale, array( __CLASS__, 'send_test_now' ), $to, $tpl, $locale );
	}

	/**
	 * Courriel de test, dans la langue courante.
	 *
	 * @param string       $to     Destinataire.
	 * @param array<mixed> $tpl    Modèle.
	 * @param string       $locale Langue.
	 * @return bool
	 */
	public static function send_test_now( $to, $tpl, $locale ) {
		$tpl      = OLI_ACR_Templates::for_locale( (string) $tpl['id'], $tpl, $locale );
		$items    = array();
		$products = wc_get_products(
			array(
				'limit'  => 2,
				'status' => 'publish',
			)
		);
		$total    = 0;
		foreach ( $products as $product ) {
			$price   = (float) $product->get_price();
			$items[] = array(
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
				'quantity'     => 1,
				'name'         => $product->get_name(),
				'line_total'   => $price,
			);
			$total  += $price;
		}
		$context = array(
			'first_name'   => __( 'John', 'oli-abandoned-cart-recovery' ),
			'last_name'    => __( 'Doe', 'oli-abandoned-cart-recovery' ),
			'email'        => $to,
			'items'        => $items,
			'total'        => $total,
			'currency'     => get_woocommerce_currency(),
			'link'         => wc_get_checkout_url(),
			'coupon'       => 'yes' === $tpl['coupon_enabled'] ? 'TEST-COUPON' : '',
			'order_number' => '1234',
			'order_date'   => wc_format_datetime( new WC_DateTime() ),
			'test'         => true,
			'locale'       => $locale,
		);
		/* translators: %s: email subject. */
		$tpl['subject'] = sprintf( __( '[Test] %s', 'oli-abandoned-cart-recovery' ), $tpl['subject'] );
		return self::deliver_template( $tpl, $context );
	}

	/**
	 * Remplace les balises et envoie.
	 *
	 * @param array<mixed> $tpl     Modèle.
	 * @param array<mixed> $context Contexte.
	 * @return bool
	 */
	public static function deliver_template( $tpl, $context ) {
		$context = wp_parse_args(
			$context,
			array(
				'first_name'   => '',
				'last_name'    => '',
				'email'        => '',
				'items'        => array(),
				'total'        => 0,
				'currency'     => '',
				'link'         => '',
				'coupon'       => '',
				'order_number' => '',
				'order_date'   => '',
				'test'         => false,
				'locale'       => '',
			)
		);

		// Courriel test : lien de désabonnement factice, sans effet (aucune adresse n'est exclue).
		$unsub      = $context['test'] ? '#' : oli_acr_unsubscribe_url( $context['email'], (string) $context['locale'] );
		$amount     = self::coupon_amount_text( $tpl );
		$first_name = '' !== $context['first_name'] ? $context['first_name'] : __( 'there', 'oli-abandoned-cart-recovery' );
		$price_args = array( 'currency' => $context['currency'] );
		$button     = '' !== $tpl['button_label'] ? $tpl['button_label'] : __( 'Complete my order', 'oli-abandoned-cart-recovery' );

		$text_map = array(
			'{first_name}'    => $context['first_name'],
			'{last_name}'     => $context['last_name'],
			'{full_name}'     => trim( $context['first_name'] . ' ' . $context['last_name'] ),
			'{email}'         => $context['email'],
			'{site_name}'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'      => home_url( '/' ),
			'{order_number}'  => $context['order_number'],
			'{order_date}'    => $context['order_date'],
			'{coupon_code}'   => $context['coupon'],
			'{coupon_amount}' => $amount,
			'{cart_total}'    => wp_strip_all_tags( wc_price( $context['total'], $price_args ) ),
		);

		$html_map = array(
			'{first_name}'       => esc_html( $first_name ),
			'{last_name}'        => esc_html( $text_map['{last_name}'] ),
			'{full_name}'        => esc_html( $text_map['{full_name}'] ),
			'{email}'            => esc_html( $text_map['{email}'] ),
			'{site_name}'        => esc_html( $text_map['{site_name}'] ),
			'{site_url}'         => esc_url( $text_map['{site_url}'] ),
			'{order_number}'     => esc_html( $text_map['{order_number}'] ),
			'{order_date}'       => esc_html( $text_map['{order_date}'] ),
			'{coupon_code}'      => esc_html( $context['coupon'] ),
			'{coupon_amount}'    => esc_html( $amount ),
			'{cart_total}'       => wc_price( $context['total'], $price_args ),
			'{recovery_link}'    => esc_url( $context['link'] ),
			'{unsubscribe_link}' => esc_url( $unsub ),
			'{cart_items}'       => self::items_table( $context['items'], $context['total'], $context['currency'] ),
			'{coupon}'           => self::coupon_box( $context['coupon'] ),
			'{recovery_button}'  => self::button( $context['link'], $button ),
		);

		// Nettoie les restes de ponctuation quand le prénom est inconnu (ex. « , vous avez oublié… »).
		$subject = self::tidy( strtr( $tpl['subject'], $text_map ) );
		$heading = self::tidy( strtr( $tpl['heading'], $text_map ) );
		$content = (string) $tpl['content'];
		if ( '' === (string) $context['coupon'] ) {
			// Sans coupon, la phrase d'introduction du coupon n'a pas de sens : son paragraphe est retiré.
			$content = (string) preg_replace( '#<p\b[^>]*>(?:(?!</p>).)*\{coupon_amount\}(?:(?!</p>).)*</p>#is', '', $content );
		}
		$body  = strtr( wpautop( $content ), $html_map );
		$body .= '<p style="font-size:12px;color:#777;text-align:center;margin-top:30px">' . sprintf(
			/* translators: %s: unsubscribe link. */
			esc_html__( 'You received this email because you started an order on our store. %s', 'oli-abandoned-cart-recovery' ),
			'<a href="' . esc_url( $unsub ) . '">' . esc_html__( 'Unsubscribe', 'oli-abandoned-cart-recovery' ) . '</a>'
		) . '</p>';

		$reply_to = '' !== $tpl['reply_to'] ? $tpl['reply_to'] : oli_acr_get_setting( 'reply_to' );
		return self::deliver( $context['email'], $subject, $heading, $body, $reply_to, $context['test'] ? '' : $unsub );
	}

	/**
	 * Retire la ponctuation orpheline et remet la majuscule initiale.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function tidy( $text ) {
		$clean = trim( (string) preg_replace( '/^[\s,;:!-]+|\s+(?=[,;:!])/u', '', $text ) );
		if ( trim( $text ) !== $clean && '' !== $clean ) {
			$clean = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $clean, 0, 1 ) ) . mb_substr( $clean, 1 ) : ucfirst( $clean );
		}
		return $clean;
	}

	/**
	 * Envoi via le gabarit WooCommerce.
	 *
	 * @param string $to       Destinataire.
	 * @param string $subject  Sujet.
	 * @param string $heading  En-tête.
	 * @param string $body     Corps HTML.
	 * @param string $reply_to Adresse de réponse.
	 * @param string $unsub    URL de désabonnement (en-tête List-Unsubscribe).
	 * @return bool
	 */
	public static function deliver( $to, $subject, $heading, $body, $reply_to = '', $unsub = '' ) {
		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $body );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		if ( $unsub ) {
			// RFC 8058 : désabonnement en un clic (POST sans confirmation sur la même URL signée).
			$headers[] = 'List-Unsubscribe: <' . esc_url_raw( $unsub ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		self::$sending    = array(
			'name'  => oli_acr_get_setting( 'sender_name' ),
			'email' => oli_acr_get_setting( 'sender_email' ),
		);
		self::$last_error = '';
		self::$alt_body   = self::html_to_text( $heading, $body );
		add_filter( 'woocommerce_email_from_name', array( __CLASS__, 'filter_from_name' ), 99 );
		add_filter( 'woocommerce_email_from_address', array( __CLASS__, 'filter_from_address' ), 99 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_failure' ) );
		add_action( 'phpmailer_init', array( __CLASS__, 'add_alt_body' ), 99 );
		$sent = $mailer->send( $to, $subject, $message, implode( "\r\n", $headers ) . "\r\n" );
		remove_action( 'phpmailer_init', array( __CLASS__, 'add_alt_body' ), 99 );
		remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_failure' ) );
		remove_filter( 'woocommerce_email_from_name', array( __CLASS__, 'filter_from_name' ), 99 );
		remove_filter( 'woocommerce_email_from_address', array( __CLASS__, 'filter_from_address' ), 99 );
		self::$sending  = array();
		self::$alt_body = '';

		if ( ! $sent && '' === self::raw_error() ) {
			self::$last_error = __( 'wp_mail() returned false (no error message was given by the mailer).', 'oli-abandoned-cart-recovery' );
		}
		oli_acr_log( sprintf( 'Email "%s" to %s: %s', $subject, $to, $sent ? 'sent' : 'failed (' . self::$last_error . ')' ), $sent ? 'info' : 'error' );
		return (bool) $sent;
	}

	/**
	 * Mémorise la cause d'un échec de wp_mail().
	 *
	 * @param WP_Error $error Erreur.
	 * @return void
	 */
	public static function capture_failure( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = wp_strip_all_tags( $error->get_error_message() );
		}
	}

	/**
	 * Cause brute du dernier échec (vide si wp_mail_failed n'a rien signalé).
	 *
	 * @return string
	 */
	private static function raw_error() {
		return self::$last_error;
	}

	/**
	 * Cause du dernier échec d'envoi.
	 *
	 * @return string
	 */
	public static function last_error() {
		return '' !== self::$last_error ? self::$last_error : __( 'Unknown error', 'oli-abandoned-cart-recovery' );
	}

	/**
	 * Ajoute la partie texte (multipart/alternative) au courriel en cours.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Instance.
	 * @return void
	 */
	public static function add_alt_body( $phpmailer ) {
		if ( '' !== self::$alt_body && 'text/html' === $phpmailer->ContentType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Propriété de PHPMailer.
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Propriété de PHPMailer.
		}
	}

	/**
	 * Version texte d'un courriel HTML : liens en clair, tableaux et paragraphes sur des lignes.
	 *
	 * @param string $heading En-tête.
	 * @param string $html    Corps HTML.
	 * @return string
	 */
	public static function html_to_text( $heading, $html ) {
		$html = (string) preg_replace( '#<(head|style|script)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$html = (string) preg_replace( '#<img\b[^>]*>#i', '', $html );
		$html = (string) preg_replace_callback(
			'#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( $m ) {
				$url  = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				$text = trim( html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' === $url || '#' === $url || $text === $url ) {
					return '' !== $text ? $text : $url;
				}
				return ( '' !== $text ? $text . ' : ' : '' ) . $url;
			},
			$html
		);
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#</(p|div|h[1-6]|tr|table|li|ul|ol)>#i', "\n\n", $html );
		$html = (string) preg_replace( '#</t[dh]>#i', '   ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = implode( "\n", array_map( 'trim', explode( "\n", $text ) ) );
		$text = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
		$head = trim( wp_strip_all_tags( (string) $heading ) );
		return ( '' !== $head ? $head . "\n\n" : '' ) . $text;
	}

	/**
	 * Supprime un coupon généré pour un envoi qui a échoué (le nouvel essai en créera un autre).
	 *
	 * @param string $code Code.
	 * @return void
	 */
	public static function delete_coupon( $code ) {
		if ( '' === (string) $code ) {
			return;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Nom d'expéditeur personnalisé.
	 *
	 * @param string $name Nom.
	 * @return string
	 */
	public static function filter_from_name( $name ) {
		return ! empty( self::$sending['name'] ) ? self::$sending['name'] : $name;
	}

	/**
	 * Adresse d'expéditeur personnalisée.
	 *
	 * @param string $address Adresse.
	 * @return string
	 */
	public static function filter_from_address( $address ) {
		return ! empty( self::$sending['email'] ) && is_email( self::$sending['email'] ) ? self::$sending['email'] : $address;
	}

	/**
	 * Tableau HTML des articles.
	 *
	 * @param array<mixed> $items    Articles.
	 * @param float        $total    Total.
	 * @param string       $currency Devise.
	 * @return string
	 */
	public static function items_table( $items, $total, $currency ) {
		if ( empty( $items ) ) {
			return '';
		}
		$args = array( 'currency' => $currency );
		$rows = array();
		foreach ( $items as $item ) {
			$product_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
			$product    = wc_get_product( $product_id );
			$name       = $product ? $product->get_name() : ( isset( $item['name'] ) ? $item['name'] : '' );
			$image      = '';
			if ( $product && $product->get_image_id() ) {
				$src = wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_gallery_thumbnail' );
				if ( $src ) {
					$image = '<img src="' . esc_url( $src ) . '" width="48" height="48" alt="" style="display:block;border:0">';
				}
			}
			$rows[] = array( $image, $name, (int) $item['quantity'], (float) $item['line_total'] );
		}
		// La colonne des images n'est affichée que si au moins un article a une image (sinon elle reste vide, surtout visible sur mobile).
		$with_images = (bool) array_filter( wp_list_pluck( $rows, 0 ) );
		$html        = '<table class="oli-acr-items" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border:1px solid #e5e5e5;margin:0 0 16px">';
		$html       .= '<thead><tr><th style="text-align:left"' . ( $with_images ? ' colspan="2"' : '' ) . '>' . esc_html__( 'Product', 'oli-abandoned-cart-recovery' ) . '</th><th style="text-align:center">' . esc_html__( 'Quantity', 'oli-abandoned-cart-recovery' ) . '</th><th style="text-align:right">' . esc_html__( 'Total', 'oli-abandoned-cart-recovery' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>' . ( $with_images ? '<td class="oli-acr-img" style="width:56px">' . $row[0] . '</td>' : '' ) . '<td>' . esc_html( $row[1] ) . '</td><td style="text-align:center">' . esc_html( (string) $row[2] ) . '</td><td style="text-align:right">' . wc_price( $row[3], $args ) . '</td></tr>';
		}
		$html .= '</tbody><tfoot><tr><th colspan="' . ( $with_images ? 3 : 2 ) . '" style="text-align:right">' . esc_html__( 'Total', 'oli-abandoned-cart-recovery' ) . '</th><td style="text-align:right"><strong>' . wc_price( (float) $total, $args ) . '</strong></td></tr></tfoot></table>';
		return $html;
	}

	/**
	 * Rabais du coupon d'un modèle, en texte (10 % ou 5,00 $).
	 *
	 * @param array<mixed> $tpl Modèle.
	 * @return string
	 */
	public static function coupon_amount_text( $tpl ) {
		$amount = isset( $tpl['coupon_amount'] ) ? (float) $tpl['coupon_amount'] : 0;
		if ( isset( $tpl['coupon_type'] ) && 'fixed_cart' === $tpl['coupon_type'] ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		/* translators: %s: discount percentage (number). */
		return sprintf( __( '%s%%', 'oli-abandoned-cart-recovery' ), wc_format_localized_decimal( (string) ( 0.0 === fmod( $amount, 1.0 ) ? (int) $amount : $amount ) ) );
	}

	/**
	 * Bloc coupon.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function coupon_box( $code ) {
		if ( '' === (string) $code ) {
			return '';
		}
		$color = get_option( 'woocommerce_email_base_color', '#7f54b3' );
		return '<p style="text-align:center;margin:20px 0"><span class="oli-acr-coupon" style="display:inline-block;border:2px dashed ' . esc_attr( $color ) . ';padding:10px 20px;font-size:20px;font-weight:bold;letter-spacing:2px">' . esc_html( $code ) . '</span></p>';
	}

	/**
	 * Bouton de récupération.
	 *
	 * @param string $link  URL.
	 * @param string $label Libellé.
	 * @return string
	 */
	public static function button( $link, $label ) {
		$color = get_option( 'woocommerce_email_base_color', '#7f54b3' );
		return '<a href="' . esc_url( $link ) . '" style="display:inline-block;background:' . esc_attr( $color ) . ';color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:4px;font-weight:bold">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Crée un coupon unique pour une relance si le modèle le demande.
	 *
	 * @param array<mixed> $tpl    Modèle.
	 * @param string       $email  Courriel du client (restriction).
	 * @param int          $log_id Journal.
	 * @return string Code ou chaîne vide.
	 */
	public static function maybe_create_coupon( $tpl, $email, $log_id ) {
		if ( 'yes' !== $tpl['coupon_enabled'] || (float) $tpl['coupon_amount'] <= 0 ) {
			return '';
		}
		/**
		 * B4 : pas de coupon si le courriel ne montre pas son code ({coupon} ou {coupon_code}) : il serait créé
		 * pour rien et le client ne le verrait jamais. Le filtre permet de forcer la création (usage avancé).
		 *
		 * @param bool         $create Créer le coupon.
		 * @param array<mixed> $tpl    Modèle résolu dans la langue du courriel.
		 */
		if ( ! apply_filters( 'oli_acr_create_coupon', OLI_ACR_Templates::shows_coupon( $tpl ), $tpl ) ) {
			return '';
		}
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) oli_acr_get_setting( 'coupon_prefix' ) ) );
		$code   = ( '' !== $prefix ? $prefix . '-' : '' ) . strtoupper( wp_generate_password( 8, false, false ) );
		/**
		 * Filtre le code du coupon généré.
		 *
		 * @param string $code  Code.
		 * @param array  $tpl   Modèle.
		 * @param string $email Courriel.
		 */
		$code = apply_filters( 'oli_acr_coupon_code', $code, $tpl, $email );

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $tpl['coupon_type'] );
		$coupon->set_amount( (float) $tpl['coupon_amount'] );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_email_restrictions( array( $email ) );
		$coupon->set_description( __( 'Created automatically by Oli Abandoned Cart Recovery.', 'oli-abandoned-cart-recovery' ) );
		if ( (int) $tpl['coupon_validity'] > 0 ) {
			$coupon->set_date_expires( time() + (int) $tpl['coupon_validity'] * DAY_IN_SECONDS );
		}
		$coupon->update_meta_data( '_oli_acr_coupon', 'yes' );
		$coupon->save();

		if ( $log_id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( oli_acr_table( 'log' ), array( 'coupon_code' => $code ), array( 'id' => (int) $log_id ) );
		}
		return $code;
	}

	/**
	 * Ajoute une ligne au journal des courriels.
	 *
	 * @param string $type        cart ou order.
	 * @param int    $object_id   ID.
	 * @param string $template_id Modèle.
	 * @param string $email       Courriel.
	 * @return int
	 */
	public static function insert_log( $type, $object_id, $template_id, $email ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			oli_acr_table( 'log' ),
			array(
				'object_type' => $type,
				'object_id'   => (int) $object_id,
				'template_id' => $template_id,
				'email'       => strtolower( $email ),
				'sent_at'     => oli_acr_now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Supprime une ligne de journal (envoi échoué).
	 *
	 * @param int $log_id ID.
	 * @return void
	 */
	public static function delete_log( $log_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( oli_acr_table( 'log' ), array( 'id' => (int) $log_id ) );
	}

	/**
	 * Ligne de journal.
	 *
	 * @param int $log_id ID.
	 * @return object|null
	 */
	public static function get_log( $log_id ) {
		global $wpdb;
		$table = oli_acr_table( 'log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ) );
	}
}
