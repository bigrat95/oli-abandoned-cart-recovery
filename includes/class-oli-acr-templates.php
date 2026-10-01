<?php
/**
 * Modèles de courriels de relance (plusieurs, en séquence).
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestion des modèles, stockés dans l'option oli_acr_templates.
 */
class OLI_ACR_Templates {

	/**
	 * Tous les modèles.
	 *
	 * @return array<mixed>
	 */
	public static function all() {
		$templates = get_option( 'oli_acr_templates', array() );
		if ( ! is_array( $templates ) ) {
			return array();
		}
		foreach ( $templates as $id => $tpl ) {
			$templates[ $id ] = wp_parse_args( $tpl, self::blank() );
		}
		return $templates;
	}

	/**
	 * Un modèle.
	 *
	 * @param string $id ID.
	 * @return array<mixed>|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Modèles actifs d'un type, triés par délai croissant.
	 *
	 * @param string $type cart ou order.
	 * @return array<mixed>
	 */
	public static function active( $type = 'cart' ) {
		$list = array();
		foreach ( self::all() as $id => $tpl ) {
			if ( 'yes' === $tpl['active'] && $type === $tpl['type'] ) {
				$list[ $id ] = $tpl;
			}
		}
		uasort(
			$list,
			static function ( $a, $b ) {
				return oli_acr_duration_to_seconds( $a['delay'] ) <=> oli_acr_duration_to_seconds( $b['delay'] );
			}
		);
		return $list;
	}

	/**
	 * Modèle vide.
	 *
	 * @return array<mixed>
	 */
	public static function blank() {
		return array(
			'id'              => '',
			'name'            => '',
			'type'            => 'cart',
			'active'          => 'no',
			'delay'           => array(
				'value' => 1,
				'unit'  => 'hours',
			),
			'subject'         => '',
			'heading'         => '',
			'reply_to'        => '',
			'content'         => '',
			'button_label'    => __( 'Complete my order', 'oli-abandoned-cart-recovery' ),
			'coupon_enabled'  => 'no',
			'coupon_type'     => 'percent',
			'coupon_amount'   => 10,
			'coupon_validity' => 7,
			'texts'           => array(),
		);
	}

	/**
	 * Enregistre un modèle (création si ID vide).
	 *
	 * @param array<mixed> $tpl Modèle nettoyé.
	 * @return string ID.
	 */
	public static function save( $tpl ) {
		$all = self::all();
		if ( empty( $tpl['id'] ) ) {
			$tpl['id'] = 'tpl_' . strtolower( wp_generate_password( 8, false, false ) );
		}
		$all[ $tpl['id'] ] = $tpl;
		update_option( 'oli_acr_templates', $all, false );
		self::reset_schedule();
		return $tpl['id'];
	}

	/**
	 * Supprime un modèle.
	 *
	 * @param string $id ID.
	 * @return void
	 */
	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( 'oli_acr_templates', $all, false );
		self::reset_schedule();
	}

	/**
	 * Après un changement de modèles, les paniers en attente sont réévalués au prochain passage.
	 *
	 * @return void
	 */
	public static function reset_schedule() {
		global $wpdb;
		$table = oli_acr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nom de table construit par oli_acr_table() ou $wpdb->prefix avec un suffixe fixe, jamais une saisie.
		$wpdb->query( "UPDATE {$table} SET next_send_at = abandoned_at WHERE status IN ('abandoned','reminded') AND abandoned_at IS NOT NULL" );
	}

	/**
	 * Nettoie un modèle soumis par formulaire.
	 *
	 * Les textes sont soumis pour une seule langue (onglet) : t[lang] et t[texts][lang][champ].
	 * Les textes des autres langues déjà enregistrés sont conservés.
	 *
	 * @param array<mixed>      $raw      Données brutes (déjà wp_unslash).
	 * @param array<mixed>|null $existing Modèle enregistré (null = nouveau).
	 * @return array<mixed>
	 */
	public static function sanitize( $raw, $existing = null ) {
		$tpl                    = self::blank();
		$tpl['id']              = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
		$tpl['type']            = ( isset( $raw['type'] ) && 'order' === $raw['type'] ) ? 'order' : 'cart';
		$tpl['active']          = ! empty( $raw['active'] ) ? 'yes' : 'no';
		$tpl['delay']           = oli_acr_sanitize_duration( isset( $raw['delay'] ) ? $raw['delay'] : array() );
		$tpl['reply_to']        = isset( $raw['reply_to'] ) ? sanitize_email( $raw['reply_to'] ) : '';
		$tpl['coupon_enabled']  = ! empty( $raw['coupon_enabled'] ) ? 'yes' : 'no';
		$tpl['coupon_type']     = ( isset( $raw['coupon_type'] ) && 'fixed_cart' === $raw['coupon_type'] ) ? 'fixed_cart' : 'percent';
		$tpl['coupon_amount']   = isset( $raw['coupon_amount'] ) ? (float) wc_format_decimal( $raw['coupon_amount'] ) : 0;
		$tpl['coupon_validity'] = isset( $raw['coupon_validity'] ) ? absint( $raw['coupon_validity'] ) : 0;

		$texts = ( is_array( $existing ) && isset( $existing['texts'] ) && is_array( $existing['texts'] ) ) ? $existing['texts'] : array();
		if ( is_array( $existing ) && array() === $texts ) {
			// Modèle d'avant 1.1.0 : ses textes appartiennent à la langue de repli.
			$texts[ OLI_ACR_Lang::fallback_language() ] = self::pick_texts( $existing );
		}
		$languages = OLI_ACR_Lang::languages();
		$submitted = isset( $raw['texts'] ) && is_array( $raw['texts'] ) ? $raw['texts'] : array();
		// Formulaire à une seule langue sans tableau texts (compatibilité) : champs de premier niveau.
		if ( array() === $submitted && isset( $raw['subject'] ) ) {
			$lang               = isset( $raw['lang'] ) ? OLI_ACR_Lang::normalize( sanitize_text_field( $raw['lang'] ) ) : '';
			$lang               = '' !== $lang ? $lang : OLI_ACR_Lang::fallback_language();
			$submitted[ $lang ] = $raw;
		}
		foreach ( $submitted as $locale => $fields ) {
			$locale = OLI_ACR_Lang::normalize( sanitize_text_field( (string) $locale ) );
			if ( '' === $locale || ! in_array( $locale, $languages, true ) || ! is_array( $fields ) ) {
				continue;
			}
			$texts[ $locale ] = array(
				'name'         => isset( $fields['name'] ) ? sanitize_text_field( $fields['name'] ) : '',
				'subject'      => isset( $fields['subject'] ) ? sanitize_text_field( $fields['subject'] ) : '',
				'heading'      => isset( $fields['heading'] ) ? sanitize_text_field( $fields['heading'] ) : '',
				'content'      => isset( $fields['content'] ) ? wp_kses_post( $fields['content'] ) : '',
				'button_label' => isset( $fields['button_label'] ) ? sanitize_text_field( $fields['button_label'] ) : '',
			);
		}
		$tpl['texts'] = $texts;
		return self::mirror_fallback( $tpl );
	}

	/**
	 * Recopie les textes de la langue de repli dans les champs de premier niveau (compatibilité 1.0.x,
	 * filtres et extensions de traduction de chaînes).
	 *
	 * @param array<mixed> $tpl Modèle.
	 * @return array<mixed>
	 */
	public static function mirror_fallback( $tpl ) {
		$fallback = OLI_ACR_Lang::fallback_language();
		if ( isset( $tpl['texts'][ $fallback ] ) && is_array( $tpl['texts'][ $fallback ] ) ) {
			foreach ( self::TEXT_FIELDS as $field ) {
				if ( isset( $tpl['texts'][ $fallback ][ $field ] ) && '' !== (string) $tpl['texts'][ $fallback ][ $field ] ) {
					$tpl[ $field ] = $tpl['texts'][ $fallback ][ $field ];
				}
			}
		}
		if ( '' === (string) $tpl['name'] ) {
			$tpl['name'] = (string) $tpl['subject'];
		}
		return $tpl;
	}

	/**
	 * Champs de texte d'un modèle, par langue (onglets de l'admin).
	 */
	const TEXT_FIELDS = array( 'name', 'subject', 'heading', 'content', 'button_label' );

	/**
	 * B4 : vrai si le modèle (déjà résolu dans une langue) montre le code du coupon ({coupon} ou {coupon_code}).
	 *
	 * @param array<mixed> $tpl Modèle résolu.
	 * @return bool
	 */
	public static function shows_coupon( $tpl ) {
		foreach ( array( 'subject', 'heading', 'content', 'button_label' ) as $field ) {
			$text = isset( $tpl[ $field ] ) ? (string) $tpl[ $field ] : '';
			if ( false !== strpos( $text, '{coupon}' ) || false !== strpos( $text, '{coupon_code}' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * B4 : langues où le coupon est activé mais où le courriel ne montre pas le code (aucun coupon n'y est créé).
	 *
	 * @param string       $id  ID du modèle.
	 * @param array<mixed> $tpl Modèle.
	 * @return string[] Locales.
	 */
	public static function coupon_missing_locales( $id, $tpl ) {
		if ( ! isset( $tpl['coupon_enabled'] ) || 'yes' !== $tpl['coupon_enabled'] || (float) ( isset( $tpl['coupon_amount'] ) ? $tpl['coupon_amount'] : 0 ) <= 0 ) {
			return array();
		}
		$missing = array();
		foreach ( OLI_ACR_Lang::languages() as $locale ) {
			if ( ! self::shows_coupon( self::for_locale( (string) $id, $tpl, $locale ) ) ) {
				$missing[] = $locale;
			}
		}
		return $missing;
	}

	/**
	 * Champs qui forment le courriel (comparés pour savoir si un modèle est encore celui par défaut).
	 */
	const MAIL_FIELDS = array( 'subject', 'heading', 'content', 'button_label' );

	/**
	 * Extrait les champs de texte d'un modèle.
	 *
	 * @param array<mixed> $tpl Modèle.
	 * @return array<string, string>
	 */
	public static function pick_texts( $tpl ) {
		$out = array();
		foreach ( self::TEXT_FIELDS as $field ) {
			$out[ $field ] = isset( $tpl[ $field ] ) ? (string) $tpl[ $field ] : '';
		}
		return $out;
	}

	/**
	 * Textes de la langue de repli d'un modèle (champs vides complétés par les champs de premier niveau).
	 *
	 * @param array<mixed> $tpl      Modèle.
	 * @param string|null  $fallback Langue de repli.
	 * @return array<string, string>
	 */
	public static function base_texts( $tpl, $fallback = null ) {
		$fallback = null === $fallback ? OLI_ACR_Lang::fallback_language() : $fallback;
		$base     = self::pick_texts( $tpl );
		if ( isset( $tpl['texts'][ $fallback ] ) && is_array( $tpl['texts'][ $fallback ] ) ) {
			foreach ( self::TEXT_FIELDS as $field ) {
				if ( isset( $tpl['texts'][ $fallback ][ $field ] ) && '' !== (string) $tpl['texts'][ $fallback ][ $field ] ) {
					$base[ $field ] = (string) $tpl['texts'][ $fallback ][ $field ];
				}
			}
		}
		return $base;
	}

	/**
	 * Textes d'un modèle dans une langue, champ par champ, dans cet ordre de priorité :
	 *
	 * 1. texte saisi pour cette langue dans le plugin (onglet de langue) ;
	 * 2. traduction de la chaîne dans WPML String Translation ou Polylang (chaînes de la langue de repli) ;
	 * 3. texte par défaut traduit dans cette langue, si le champ de la langue de repli est encore celui par défaut ;
	 * 4. texte de la langue de repli.
	 *
	 * @param string       $id     ID du modèle.
	 * @param array<mixed> $tpl    Modèle.
	 * @param string       $locale Locale voulue.
	 * @return array<mixed> Modèle avec les champs de texte résolus et la clé « locale ».
	 */
	public static function for_locale( $id, $tpl, $locale ) {
		$fallback = OLI_ACR_Lang::fallback_language();
		$locale   = OLI_ACR_Lang::resolve( $locale );
		$base     = self::base_texts( $tpl, $fallback );
		$own      = ( isset( $tpl['texts'][ $locale ] ) && is_array( $tpl['texts'][ $locale ] ) ) ? $tpl['texts'][ $locale ] : array();
		$defaults = null;
		if ( OLI_ACR_Lang::has_translation( $locale ) ) {
			$all      = self::defaults_by_locale();
			$defaults = isset( $all[ $locale ][ $id ] ) ? $all[ $locale ][ $id ] : null;
		}
		$adapter = OLI_ACR_Lang::adapter();
		foreach ( self::TEXT_FIELDS as $field ) {
			if ( isset( $own[ $field ] ) && '' !== trim( (string) $own[ $field ] ) ) {
				$tpl[ $field ] = (string) $own[ $field ];
				continue;
			}
			if ( $locale !== $fallback ) {
				$translated = $adapter->translate_string( OLI_ACR_Lang::string_name( $id . '_' . $field ), $base[ $field ], $locale );
				if ( null !== $translated ) {
					$tpl[ $field ] = $translated;
					continue;
				}
			}
			if ( null !== $defaults && self::is_default_field( $id, $field, $base[ $field ] ) ) {
				$tpl[ $field ] = (string) $defaults[ $field ];
				continue;
			}
			$tpl[ $field ] = $base[ $field ];
		}
		$tpl['locale'] = $locale;
		return $tpl;
	}

	/**
	 * Rend un modèle dans la langue courante (compatibilité 1.0.x).
	 *
	 * @param string               $id  ID du modèle.
	 * @param array<string, mixed> $tpl Modèle.
	 * @return array<string, mixed>
	 */
	public static function localize( $id, $tpl ) {
		return self::for_locale( $id, $tpl, OLI_ACR_Lang::current_language() );
	}

	/**
	 * Textes par défaut de chaque langue connue (cache).
	 *
	 * @return array<string, array<mixed>>
	 */
	private static function defaults_by_locale() {
		static $cache = array();
		$locales      = array_values( array_unique( array_merge( oli_acr_known_locales(), OLI_ACR_Lang::languages() ) ) );
		$key          = implode( ',', $locales );
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = array();
			foreach ( $locales as $locale ) {
				$cache[ $key ][ $locale ] = oli_acr_in_locale( $locale, array( __CLASS__, 'default_templates_raw' ) );
				// Contenu des modèles par défaut de la 1.0.x, reconnu pour la migration.
				$cache[ $key ][ $locale . ':legacy' ] = oli_acr_in_locale( $locale, array( __CLASS__, 'legacy_default_templates' ) );
			}
		}
		return $cache[ $key ];
	}

	/**
	 * Indique si les textes d'un modèle sont identiques aux textes par défaut d'une langue installée.
	 *
	 * @param string               $id  ID du modèle.
	 * @param array<string, mixed> $tpl Modèle ou textes.
	 * @return bool
	 */
	public static function has_default_text( $id, $tpl ) {
		foreach ( self::defaults_by_locale() as $defaults ) {
			if ( ! isset( $defaults[ $id ] ) ) {
				continue;
			}
			$same = true;
			foreach ( self::MAIL_FIELDS as $field ) {
				$a = isset( $tpl[ $field ] ) ? (string) $tpl[ $field ] : '';
				$b = (string) $defaults[ $id ][ $field ];
				if ( 'content' === $field ) {
					$a = wpautop( $a );
					$b = wpautop( $b );
				}
				if ( oli_acr_normalize_text( $a ) !== oli_acr_normalize_text( $b ) ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Indique si un champ d'un modèle est encore le texte par défaut (dans une langue installée ou de la 1.0.x).
	 *
	 * @param string $id    ID du modèle.
	 * @param string $field Champ.
	 * @param string $value Valeur.
	 * @return bool
	 */
	public static function is_default_field( $id, $field, $value ) {
		$value = 'content' === $field ? wpautop( (string) $value ) : (string) $value;
		$value = oli_acr_normalize_text( $value );
		foreach ( self::defaults_by_locale() as $defaults ) {
			if ( ! isset( $defaults[ $id ][ $field ] ) ) {
				continue;
			}
			$default = (string) $defaults[ $id ][ $field ];
			$default = 'content' === $field ? wpautop( $default ) : $default;
			if ( oli_acr_normalize_text( $default ) === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ajoute les textes par défaut, dans chaque langue active, aux modèles par défaut qui ne les ont pas.
	 *
	 * Un modèle par défaut non modifié reçoit ses textes dans toutes les langues ; un modèle modifié garde
	 * ses textes dans la langue de repli (les autres langues suivent l'ordre de priorité de for_locale()).
	 *
	 * @return bool Vrai si les modèles ont changé.
	 */
	public static function sync_languages() {
		$all = get_option( 'oli_acr_templates', array() );
		if ( ! is_array( $all ) ) {
			return false;
		}
		$fallback = OLI_ACR_Lang::fallback_language();
		$changed  = false;
		foreach ( $all as $id => $tpl ) {
			$tpl   = wp_parse_args( $tpl, self::blank() );
			$texts = is_array( $tpl['texts'] ) ? $tpl['texts'] : array();
			if ( array() === $texts ) {
				$texts[ $fallback ] = self::pick_texts( $tpl );
				$changed            = true;
			}
			$base = self::base_texts( array_merge( $tpl, array( 'texts' => $texts ) ), $fallback );
			if ( self::has_default_text( (string) $id, $base ) ) {
				foreach ( OLI_ACR_Lang::languages() as $locale ) {
					$defaults = OLI_ACR_Lang::run_in( $locale, array( __CLASS__, 'default_templates_raw' ) );
					$current  = isset( $texts[ $locale ] ) ? (array) $texts[ $locale ] : array();
					// Remplit une langue absente, ou remplace des textes par défaut d'une autre langue ou de la 1.0.x.
					if ( ( array() === $current || self::has_default_text( (string) $id, $current ) ) && isset( $defaults[ $id ] ) ) {
						$wanted = self::pick_texts( $defaults[ $id ] );
						if ( $current !== $wanted ) {
							$texts[ $locale ] = $wanted;
							$changed          = true;
						}
					}
				}
			}
			$tpl['texts'] = $texts;
			$all[ $id ]   = self::mirror_fallback( $tpl );
		}
		if ( $changed ) {
			update_option( 'oli_acr_templates', $all, false );
		}
		return $changed;
	}

	/**
	 * Modèles par défaut, avec leurs textes dans chaque langue active (création à l'installation).
	 *
	 * @return array<mixed>
	 */
	public static function default_templates() {
		$fallback = OLI_ACR_Lang::fallback_language();
		$all      = OLI_ACR_Lang::run_in( $fallback, array( __CLASS__, 'default_templates_raw' ) );
		foreach ( OLI_ACR_Lang::languages() as $locale ) {
			$localized = OLI_ACR_Lang::run_in( $locale, array( __CLASS__, 'default_templates_raw' ) );
			foreach ( $all as $id => $tpl ) {
				$all[ $id ]['texts'][ $locale ] = self::pick_texts( $localized[ $id ] );
			}
		}
		return $all;
	}

	/**
	 * Contenu des modèles par défaut de la version 1.0.x (pour reconnaître un modèle non modifié).
	 *
	 * @return array<mixed>
	 */
	public static function legacy_default_templates() {
		$all                          = self::default_templates_raw();
		$all['tpl_cart_2']['content'] = '<p>' . __( 'Hi {first_name},', 'oli-abandoned-cart-recovery' ) . '</p><p>' . __( 'Your items are still available. Use this single-use code at checkout:', 'oli-abandoned-cart-recovery' ) . '</p>{coupon}{cart_items}<p style="text-align:center">{recovery_button}</p>';
		return $all;
	}

	// Contenu de courriels HTML : style="text-align:center" en ligne est voulu (les logiciels de courriel
	// n'appliquent pas de feuille de style externe ni de balise style).
	/**
	 * Modèles par défaut dans la langue courante : 2 relances de panier et 1 relance de commande en attente.
	 *
	 * @return array<mixed>
	 */
	public static function default_templates_raw() {
		$first  = array_merge(
			self::blank(),
			array(
				'id'      => 'tpl_cart_1',
				'name'    => __( 'Cart reminder #1', 'oli-abandoned-cart-recovery' ),
				'type'    => 'cart',
				'active'  => 'yes',
				'delay'   => array(
					'value' => 1,
					'unit'  => 'hours',
				),
				'subject' => __( 'You left something in your cart', 'oli-abandoned-cart-recovery' ),
				'heading' => __( 'Your cart is waiting for you', 'oli-abandoned-cart-recovery' ),
				'content' => '<p>' . __( 'Hi {first_name},', 'oli-abandoned-cart-recovery' ) . '</p><p>' . __( 'Looks like you got interrupted. We saved the items in your cart:', 'oli-abandoned-cart-recovery' ) . '</p>{cart_items}<p style="text-align:center">{recovery_button}</p><p>' . __( 'Thank you,', 'oli-abandoned-cart-recovery' ) . '<br>{site_name}</p>',
			)
		);
		$second = array_merge(
			self::blank(),
			array(
				'id'             => 'tpl_cart_2',
				'name'           => __( 'Cart reminder #2 (coupon)', 'oli-abandoned-cart-recovery' ),
				'type'           => 'cart',
				'active'         => 'no',
				'delay'          => array(
					'value' => 1,
					'unit'  => 'days',
				),
				'subject'        => __( 'A little something to complete your order', 'oli-abandoned-cart-recovery' ),
				'heading'        => __( 'Here is 10% off your cart', 'oli-abandoned-cart-recovery' ),
				'content'        => '<p>' . __( 'Hi {first_name},', 'oli-abandoned-cart-recovery' ) . '</p><p>' . __( 'Your items are still available.', 'oli-abandoned-cart-recovery' ) . '</p><p>' . self::coupon_intro_text() . '</p>{coupon}{cart_items}<p style="text-align:center">{recovery_button}</p>',
				'coupon_enabled' => 'yes',
			)
		);
		$order  = array_merge(
			self::blank(),
			array(
				'id'           => 'tpl_order_1',
				'name'         => __( 'Pending order reminder', 'oli-abandoned-cart-recovery' ),
				'type'         => 'order',
				'active'       => 'no',
				'delay'        => array(
					'value' => 0,
					'unit'  => 'minutes',
				),
				'subject'      => __( 'Your order #{order_number} is awaiting payment', 'oli-abandoned-cart-recovery' ),
				'heading'      => __( 'Complete your payment', 'oli-abandoned-cart-recovery' ),
				'content'      => '<p>' . __( 'Hi {first_name},', 'oli-abandoned-cart-recovery' ) . '</p><p>' . __( 'Your order #{order_number} was not paid yet. You can complete the payment here:', 'oli-abandoned-cart-recovery' ) . '</p>{cart_items}<p style="text-align:center">{recovery_button}</p>',
				'button_label' => __( 'Pay for my order', 'oli-abandoned-cart-recovery' ),
			)
		);
		return array(
			$first['id']  => $first,
			$second['id'] => $second,
			$order['id']  => $order,
		);
	}

	/**
	 * Phrase d'introduction du coupon (modèles par défaut), avec la balise {coupon_amount}.
	 *
	 * @return string
	 */
	public static function coupon_intro_text() {
		/* translators: {coupon_amount} is a placeholder replaced by the discount (e.g. 10 % or $5.00). Keep it as is. */
		return __( 'Use this code to get {coupon_amount} off your order:', 'oli-abandoned-cart-recovery' );
	}
}
