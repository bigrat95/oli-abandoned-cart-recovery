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
		add_action( 'wc_ajax_oli_acr_nonce', array( __CLASS__, 'ajax_nonce' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache_checkout' ), 5 );
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'honeypot_field' ) );
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
				'endpoint'    => WC_AJAX::get_endpoint( 'oli_acr_capture' ),
				'nonce'       => wp_create_nonce( 'oli_acr_capture' ),
				// Nonce frais si celui de la page est périmé (page mise en cache par erreur).
				'nonceUrl'    => WC_AJAX::get_endpoint( 'oli_acr_nonce' ),
				// Interrupteur du consentement : s'applique aux invités ET aux clients connectés (R10).
				'consent'     => oli_acr_consent_required() ? 1 : 0,
				// Langue de la page (l'appel wc-ajax ne passe pas toujours par l'URL de la langue).
				'lang'        => OLI_ACR_Lang::current_language(),
				'delay'       => 800,
				// Libellé HTML (liens) du consentement : le checkout en blocs n'affiche que du texte.
				'consentHtml' => oli_acr_consent_required() ? oli_acr_consent_html() : '',
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
		$fields['billing']['oli_acr_consent'] = array(
			// Client connecté qui a déjà consenti : case précochée (il peut la décocher pour retirer son consentement).
			'default'  => oli_acr_user_has_consent( get_current_user_id() ) ? 1 : 0,
			'type'     => 'checkbox',
			'label'    => oli_acr_consent_html(),
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
				'label'    => html_entity_decode( wp_strip_all_tags( oli_acr_consent_text() ), ENT_QUOTES, 'UTF-8' ),
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

		// Piège à robots : champ caché jamais rempli par un humain. Réponse neutre, rien n'est enregistré.
		if ( ! empty( $_POST['oli_acr_hp'] ) ) {
			wp_send_json_success( array( 'captured' => false ) );
		}
		$email_raw = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! self::within_rate_limits( strtolower( $email_raw ) ) ) {
			wp_send_json_error( array( 'reason' => 'rate_limited' ), 429 );
		}

		$user_id = get_current_user_id();
		$mode    = oli_acr_get_setting( 'guest_tracking' );

		if ( ! oli_acr_user_is_tracked( $user_id ) ) {
			wp_send_json_error( array( 'reason' => 'not_tracked' ) );
		}

		// Loi 25 : sans consentement explicite, rien n'est enregistré (ni courriel, ni téléphone, ni panier).
		$consent = isset( $_POST['consent'] ) ? '1' === sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : false;
		if ( 'consent' === $mode && ! $consent ) {
			if ( $user_id ) {
				delete_user_meta( $user_id, OLI_ACR_Carts::USER_CONSENT_META );
			}
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
				// B1 (Loi 25) : consent=1 seulement pour une case réellement cochée (ou le consentement mémorisé d'un
				// client connecté). Interrupteur OFF : capté sans case, donc consent=0 ; jamais relancé si on le rallume.
				'consent'    => 'consent' === $mode ? $consent : ( $user_id && oli_acr_user_has_consent( $user_id ) ),
				'user_id'    => $user_id,
				'language'   => $language,
			)
		);
		self::$busy = false;
		if ( $id && $user_id && 'consent' === $mode && $consent ) {
			update_user_meta( $user_id, OLI_ACR_Carts::USER_CONSENT_META, time() );
		}

		if ( ! $id ) {
			wp_send_json_error( array( 'reason' => 'not_saved' ) );
		}
		wp_send_json_success( array( 'captured' => true ) );
	}

	/**
	 * Champ piège (checkout classique), caché aux humains et aux lecteurs d'écran.
	 *
	 * @return void
	 */
	public static function honeypot_field() {
		echo '<p class="oli-acr-hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><label for="oli_acr_hp">' . esc_html__( 'Leave this field empty', 'oli-abandoned-cart-recovery' ) . '</label><input type="text" name="oli_acr_hp" id="oli_acr_hp" value="" tabindex="-1" autocomplete="off"></p>';
	}

	/**
	 * Nonce frais pour la capture (wc-ajax n'est jamais mis en cache ; en-têtes no-cache en plus).
	 *
	 * @return void
	 */
	public static function ajax_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'oli_acr_capture' ) ) );
	}

	/**
	 * Pages de paiement (y compris une 2e page avec le bloc ou le code court) : jamais mises en cache.
	 *
	 * @return void
	 */
	public static function no_cache_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante standard des extensions de cache.
		}
		nocache_headers();
	}

	/**
	 * Limites de débit de la capture.
	 *
	 * @return array<string, int>
	 */
	public static function rate_limits() {
		/**
		 * Filtre les limites de débit du point de capture.
		 *
		 * @param array $limits ip_requests (par ip_window secondes), ip_emails et session_emails (adresses distinctes par email_window secondes).
		 */
		$limits = (array) apply_filters(
			'oli_acr_capture_rate_limits',
			array(
				'ip_requests'    => 60,
				'ip_window'      => 10 * MINUTE_IN_SECONDS,
				'ip_emails'      => 20,
				'session_emails' => 3,
				'email_window'   => HOUR_IN_SECONDS,
			)
		);
		return array_map( 'absint', $limits );
	}

	/**
	 * Applique les limites : requêtes par IP, adresses distinctes par IP et par session WooCommerce.
	 *
	 * @param string $email Courriel soumis (vide pour un retrait de consentement).
	 * @return bool
	 */
	public static function within_rate_limits( $email ) {
		$limits = self::rate_limits();
		$now    = time();
		$ip_key = 'oli_acr_rl_' . md5( oli_acr_client_ip() );

		$ip = get_transient( $ip_key );
		$ip = is_array( $ip ) && isset( $ip['start'] ) ? $ip : array(
			'start'  => $now,
			'count'  => 0,
			'emails' => array(),
			'estart' => $now,
		);
		if ( $now - (int) $ip['start'] >= $limits['ip_window'] ) {
			$ip['start'] = $now;
			$ip['count'] = 0;
		}
		if ( $now - (int) $ip['estart'] >= $limits['email_window'] ) {
			$ip['estart'] = $now;
			$ip['emails'] = array();
		}
		++$ip['count'];
		$hash    = '' !== $email ? substr( md5( $email ), 0, 12 ) : '';
		$allowed = $ip['count'] <= $limits['ip_requests'];
		if ( $allowed && '' !== $hash && ! in_array( $hash, $ip['emails'], true ) ) {
			if ( count( $ip['emails'] ) >= $limits['ip_emails'] ) {
				$allowed = false;
			} else {
				$session = WC()->session ? WC()->session->get( 'oli_acr_rl' ) : null;
				$session = is_array( $session ) && isset( $session['start'] ) && $now - (int) $session['start'] < $limits['email_window'] ? $session : array(
					'start'  => $now,
					'emails' => array(),
				);
				if ( ! in_array( $hash, $session['emails'], true ) ) {
					if ( count( $session['emails'] ) >= $limits['session_emails'] ) {
						$allowed = false;
					} else {
						$session['emails'][] = $hash;
						$ip['emails'][]      = $hash;
						if ( WC()->session ) {
							WC()->session->set( 'oli_acr_rl', $session );
						}
					}
				} else {
					$ip['emails'][] = $hash;
				}
			}
		}
		set_transient( $ip_key, $ip, max( $limits['ip_window'], $limits['email_window'] ) );
		if ( ! $allowed ) {
			oli_acr_log( sprintf( 'Capture rate limit reached for IP %s', oli_acr_client_ip() ), 'warning' );
		}
		return $allowed;
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
		// Consentement requis : un client connecté n'est suivi qu'après avoir coché la case (R10).
		if ( $user_id && oli_acr_consent_required() && ! oli_acr_user_has_consent( $user_id ) ) {
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
						// B1 : consentement explicite seulement (méta), même quand l'interrupteur est OFF.
						'consent'    => oli_acr_user_has_consent( $user_id ),
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
