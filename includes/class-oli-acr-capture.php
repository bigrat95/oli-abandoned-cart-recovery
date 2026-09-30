<?php
/**
 * Capture du courriel et du panier au checkout (classique et en blocs).
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capture côté visiteur.
 */
class OLI_ACR_Capture {

	/**
	 * Empêche la récursion pendant la mise à jour du panier.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Accroches.
	 *
	 * @return void
	 */
	public static function init() {
		if ( 'yes' !== oli_acr_get_setting( 'enabled' ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wc_ajax_oli_acr_capture', array( __CLASS__, 'ajax_capture' ) );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'cart_updated' ), 20 );

		if ( 'consent' === oli_acr_get_setting( 'guest_tracking' ) ) {
			add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'classic_consent_field' ) );
			add_action( 'woocommerce_init', array( __CLASS__, 'blocks_consent_field' ) );
		}
	}

	/**
	 * Charge le script de capture sur la page de paiement seulement.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		if ( ! oli_acr_user_is_tracked( get_current_user_id() ) ) {
			return;
		}
		wp_enqueue_script( 'oli-acr-capture', OLI_ACR_URL . 'assets/js/capture.js', array(), OLI_ACR_VERSION, true );
		wp_localize_script(
			'oli-acr-capture',
			'oliAcrCapture',
			array(
				'endpoint' => WC_AJAX::get_endpoint( 'oli_acr_capture' ),
				'nonce'    => wp_create_nonce( 'oli_acr_capture' ),
				'consent'  => 'consent' === oli_acr_get_setting( 'guest_tracking' ) && ! is_user_logged_in() ? 1 : 0,
				// Langue de la page (l'appel wc-ajax ne passe pas toujours par l'URL de la langue).
				'lang'     => OLI_ACR_Lang::current_language(),
				'delay'    => 800,
			)
		);
	}

	/**
	 * Case de consentement au checkout classique.
	 *
	 * @param array<mixed> $fields Champs.
	 * @return array<mixed>
	 */
	public static function classic_consent_field( $fields ) {
		if ( is_user_logged_in() ) {
			return $fields;
		}
		$fields['billing']['oli_acr_consent'] = array(
			'type'     => 'checkbox',
			'label'    => esc_html( oli_acr_consent_text() ),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 115,
		);
		return $fields;
	}

	/**
	 * Case de consentement au checkout en blocs (API des champs additionnels).
	 *
	 * @return void
	 */
	public static function blocks_consent_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'oli-acr/consent',
				'label'    => oli_acr_consent_text(),
				'location' => 'contact',
				'type'     => 'checkbox',
				'required' => false,
			)
		);
	}

	/**
	 * Point d'entrée AJAX (wc-ajax) appelé sur blur/change du champ courriel ou téléphone.
	 *
	 * @return void
	 */
	public static function ajax_capture() {
		check_ajax_referer( 'oli_acr_capture', 'nonce' );

		$user_id = get_current_user_id();
		$mode    = oli_acr_get_setting( 'guest_tracking' );

		if ( ! oli_acr_user_is_tracked( $user_id ) ) {
			wp_send_json_error( array( 'reason' => 'not_tracked' ) );
		}

		// Loi 25 : sans consentement explicite, rien n'est enregistré (ni courriel, ni téléphone, ni panier).
		$consent = isset( $_POST['consent'] ) ? '1' === sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : false;
		if ( ! $user_id && 'consent' === $mode && ! $consent ) {
			// Consentement retiré : on oublie le panier de cette session.
			$existing = OLI_ACR_Carts::find_live_by_session( OLI_ACR_Carts::current_session_key() );
			if ( $existing ) {
				OLI_ACR_Carts::delete( $existing->id );
			}
			if ( WC()->session ) {
				WC()->session->set( OLI_ACR_Carts::SESSION_KEY, null );
			}
			wp_send_json_error( array( 'reason' => 'no_consent' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'reason' => 'invalid_email' ), 400 );
		}

		if ( oli_acr_is_unsubscribed( $email ) ) {
			wp_send_json_error( array( 'reason' => 'unsubscribed' ) );
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'reason' => 'empty_cart' ) );
		}

		$language   = isset( $_POST['lang'] ) ? OLI_ACR_Lang::normalize( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		self::$busy = true;
		$id         = OLI_ACR_Carts::upsert_current(
			array(
				'email'      => $email,
				'phone'      => isset( $_POST['phone'] ) ? wc_sanitize_phone_number( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '',
				'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
				'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
				'consent'    => $user_id ? true : ( 'consent' === $mode ? $consent : true ),
				'user_id'    => $user_id,
				'language'   => $language,
			)
		);
		self::$busy = false;

		if ( ! $id ) {
			wp_send_json_error( array( 'reason' => 'not_saved' ) );
		}
		wp_send_json_success( array( 'captured' => true ) );
	}

	/**
	 * Met à jour le panier suivi quand son contenu change (connectés et invités déjà capturés).
	 *
	 * @return void
	 */
	public static function cart_updated() {
		if ( self::$busy || ! WC()->session || ! WC()->cart || wp_doing_cron() ) {
			return;
		}
		$user_id = get_current_user_id();
		$cart_id = absint( WC()->session->get( OLI_ACR_Carts::SESSION_KEY ) );
		if ( ! $user_id && ! $cart_id ) {
			return;
		}
		if ( $user_id && ! oli_acr_user_is_tracked( $user_id ) ) {
			return;
		}

		self::$busy = true;
		$snap       = OLI_ACR_Carts::snapshot();
		if ( WC()->session->get( 'oli_acr_cart_hash' ) === $snap['hash'] ) {
			self::$busy = false;
			return;
		}

		if ( $user_id ) {
			if ( $snap['count'] > 0 ) {
				$user = wp_get_current_user();
				OLI_ACR_Carts::upsert_current(
					array(
						'email'      => $user->user_email,
						'first_name' => $user->first_name,
						'last_name'  => $user->last_name,
						'phone'      => get_user_meta( $user_id, 'billing_phone', true ),
						'user_id'    => $user_id,
						'consent'    => true,
					)
				);
			}
		} else {
			$row = OLI_ACR_Carts::get( $cart_id );
			if ( $row && in_array( $row->status, OLI_ACR_Carts::LIVE_STATUSES, true ) ) {
				OLI_ACR_Carts::upsert_current( array( 'email' => $row->email ) );
			}
		}
		self::$busy = false;
	}
}
