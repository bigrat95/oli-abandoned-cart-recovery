<?php
/**
 * Courriel WooCommerce : avis à l'admin quand un panier ou une commande en attente est récupéré.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

/**
 * Avis admin de récupération.
 */
class OLI_ACR_Email_Admin_Recovered extends WC_Email {

	/**
	 * Type de récupération (cart ou order).
	 *
	 * @var string
	 */
	public $recovery_type = 'cart';

	/**
	 * Constructeur.
	 */
	public function __construct() {
		$this->id             = 'oli_acr_admin_recovered';
		$this->title          = __( 'Recovered cart (admin)', 'oli-abandoned-cart-recovery' );
		$this->description    = __( 'Sent to the store admin when an abandoned cart or a pending order is recovered after a reminder email.', 'oli-abandoned-cart-recovery' );
		$this->template_html  = 'emails/admin-recovered.php';
		$this->template_plain = 'emails/plain/admin-recovered.php';
		$this->template_base  = OLI_ACR_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{order_date}'   => '',
		);
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * Sujet par défaut.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}] Recovered sale: order #{order_number}', 'oli-abandoned-cart-recovery' );
	}

	/**
	 * En-tête par défaut.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'A cart was recovered', 'oli-abandoned-cart-recovery' );
	}

	/**
	 * Destinataire : réglage du plugin sinon réglage WooCommerce.
	 *
	 * @return string
	 */
	public function get_recipient() {
		$custom = oli_acr_get_setting( 'admin_recipient' );
		if ( $custom ) {
			$this->recipient = $custom;
		}
		return parent::get_recipient();
	}

	/**
	 * Déclenchement.
	 *
	 * @param WC_Order $order Commande.
	 * @param string   $type  cart ou order.
	 * @return void
	 */
	public function trigger( $order, $type = 'cart' ) {
		$this->setup_locale();
		if ( $order instanceof WC_Order ) {
			$this->object                         = $order;
			$this->recovery_type                  = $type;
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		}
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	/**
	 * Contenu HTML.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'         => $this->object,
				'recovery_type' => $this->recovery_type,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => true,
				'plain_text'    => false,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Contenu texte.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'         => $this->object,
				'recovery_type' => $this->recovery_type,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => true,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Champs de réglages.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array(
				'recipient' => array(
					'title'       => __( 'Recipient(s)', 'oli-abandoned-cart-recovery' ),
					'type'        => 'text',
					/* translators: %s: admin email. */
					'description' => sprintf( __( 'Comma separated. Defaults to %s.', 'oli-abandoned-cart-recovery' ), esc_html( get_option( 'admin_email' ) ) ),
					'placeholder' => '',
					'default'     => '',
				),
			),
			$this->form_fields
		);
	}
}
