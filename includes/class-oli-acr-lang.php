<?php
/**
 * Langues : choix de l'adaptateur, langue de repli, URL et chaînes par langue.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

require_once OLI_ACR_DIR . 'includes/lang/class-oli-acr-lang-adapter.php';
require_once OLI_ACR_DIR . 'includes/lang/class-oli-acr-lang-core.php';
require_once OLI_ACR_DIR . 'includes/lang/class-oli-acr-lang-wpml.php';
require_once OLI_ACR_DIR . 'includes/lang/class-oli-acr-lang-polylang.php';
require_once OLI_ACR_DIR . 'includes/lang/class-oli-acr-lang-translatepress.php';
require_once OLI_ACR_DIR . 'includes/lang/class-oli-acr-lang-weglot.php';

/**
 * Point d'accès unique aux langues.
 */
class OLI_ACR_Lang {

	/**
	 * Adaptateur retenu pour la requête.
	 *
	 * @var OLI_ACR_Lang_Adapter|null
	 */
	private static $adapter = null;

	/**
	 * Accroches.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_strings' ) );
	}

	/**
	 * Adaptateurs candidats, dans l'ordre de priorité. Le premier actif est utilisé ; le cœur sert de repli.
	 *
	 * @return array<int, OLI_ACR_Lang_Adapter>
	 */
	public static function adapters() {
		$adapters = array(
			new OLI_ACR_Lang_WPML(),
			new OLI_ACR_Lang_Polylang(),
			new OLI_ACR_Lang_TranslatePress(),
			new OLI_ACR_Lang_Weglot(),
		);
		/**
		 * Ajoute ou retire des adaptateurs de langue (instances de OLI_ACR_Lang_Adapter).
		 *
		 * @param OLI_ACR_Lang_Adapter[] $adapters Adaptateurs, du plus prioritaire au moins prioritaire.
		 */
		$adapters = (array) apply_filters( 'oli_acr_lang_adapters', $adapters );
		return array_values(
			array_filter(
				$adapters,
				static function ( $adapter ) {
					return $adapter instanceof OLI_ACR_Lang_Adapter;
				}
			)
		);
	}

	/**
	 * Adaptateur actif.
	 *
	 * @return OLI_ACR_Lang_Adapter
	 */
	public static function adapter() {
		if ( null === self::$adapter ) {
			foreach ( self::adapters() as $adapter ) {
				if ( $adapter->is_active() && array() !== $adapter->languages() ) {
					self::$adapter = $adapter;
					break;
				}
			}
			if ( null === self::$adapter ) {
				self::$adapter = new OLI_ACR_Lang_Core();
			}
		}
		return self::$adapter;
	}

	/**
	 * Oublie l'adaptateur retenu (tests, changement d'extension).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$adapter = null;
	}

	/**
	 * Langues actives (locales), langue de repli en premier.
	 *
	 * @return array<int, string>
	 */
	public static function languages() {
		$languages = self::adapter()->languages();
		$fallback  = self::fallback_language( $languages );
		$languages = array_values( array_unique( array_merge( array( $fallback ), $languages ) ) );
		return $languages;
	}

	/**
	 * Langue de repli : réglage « fallback_language » s'il est actif, sinon langue par défaut de l'extension.
	 *
	 * @param array<int, string>|null $languages Langues (évite un appel).
	 * @return string
	 */
	public static function fallback_language( $languages = null ) {
		$languages = null === $languages ? self::adapter()->languages() : $languages;
		$chosen    = (string) oli_acr_get_setting( 'fallback_language' );
		if ( '' !== $chosen && in_array( $chosen, $languages, true ) ) {
			return $chosen;
		}
		$default = self::adapter()->default_language();
		return '' !== $default ? $default : get_locale();
	}

	/**
	 * Ramène une langue (fr_CA, fr-CA, fr) à une langue active, ou chaîne vide.
	 *
	 * @param string $language Langue.
	 * @return string
	 */
	public static function normalize( $language ) {
		$language = str_replace( '-', '_', trim( (string) $language ) );
		if ( '' === $language ) {
			return '';
		}
		$languages = self::languages();
		foreach ( $languages as $locale ) {
			if ( 0 === strcasecmp( $locale, $language ) ) {
				return $locale;
			}
		}
		$short = strtolower( substr( $language, 0, 2 ) );
		foreach ( $languages as $locale ) {
			if ( strtolower( substr( $locale, 0, 2 ) ) === $short ) {
				return $locale;
			}
		}
		return '';
	}

	/**
	 * Langue à utiliser pour un envoi : celle du panier si active, sinon la langue de repli.
	 *
	 * @param string $language Langue enregistrée.
	 * @return string
	 */
	public static function resolve( $language ) {
		$locale = self::normalize( $language );
		return '' !== $locale ? $locale : self::fallback_language();
	}

	/**
	 * Langue de la requête courante (locale active), sinon langue de repli.
	 *
	 * @return string
	 */
	public static function current_language() {
		$locale = self::normalize( self::adapter()->current_language() );
		if ( '' === $locale ) {
			$locale = self::normalize( determine_locale() );
		}
		return '' !== $locale ? $locale : self::fallback_language();
	}

	/**
	 * Langue de l'interface d'administration (langue de l'utilisateur si active, sinon repli).
	 *
	 * @return string
	 */
	public static function admin_language() {
		$locale = self::normalize( get_user_locale() );
		return '' !== $locale ? $locale : self::fallback_language();
	}

	/**
	 * Nom lisible d'une langue.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function label( $locale ) {
		static $names = null;
		if ( null === $names ) {
			$names = array( 'en_US' => 'English (United States)' );
			if ( ! function_exists( 'wp_get_available_translations' ) && file_exists( ABSPATH . 'wp-admin/includes/translation-install.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			}
			if ( function_exists( 'wp_get_available_translations' ) ) {
				$available = get_site_transient( 'available_translations' );
				foreach ( is_array( $available ) ? $available : array() as $code => $info ) {
					if ( isset( $info['native_name'] ) ) {
						$names[ $code ] = (string) $info['native_name'];
					}
				}
			}
			$names += array(
				'fr_CA' => 'Français du Canada',
				'fr_FR' => 'Français',
			);
		}
		return isset( $names[ $locale ] ) ? $names[ $locale ] . ' (' . $locale . ')' : $locale;
	}

	/**
	 * URL d'accueil dans une langue.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function home_url( $locale ) {
		return self::url( home_url( '/' ), $locale );
	}

	/**
	 * Convertit une URL vers une langue (filtrable).
	 *
	 * @param string $url    URL.
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function url( $url, $locale ) {
		$translated = self::adapter()->translate_url( $url, $locale );
		/**
		 * Filtre une URL convertie vers une langue (liens des courriels, redirections).
		 *
		 * @param string $translated URL convertie.
		 * @param string $url        URL d'origine.
		 * @param string $locale     Locale.
		 */
		return (string) apply_filters( 'oli_acr_translate_url', $translated, $url, $locale );
	}

	/**
	 * URL de la page de paiement dans une langue.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function checkout_url( $locale ) {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		$url     = $page_id > 0 ? self::adapter()->page_url( $page_id, $locale ) : self::url( wc_get_checkout_url(), $locale );
		return (string) apply_filters( 'oli_acr_translate_url', $url, wc_get_checkout_url(), $locale );
	}

	/**
	 * Exécute une fonction dans une langue : locale WordPress et langue de l'extension.
	 *
	 * @param string   $locale   Locale.
	 * @param callable $callback Fonction.
	 * @param mixed    ...$args  Arguments.
	 * @return mixed
	 */
	public static function run_in( $locale, $callback, ...$args ) {
		$adapter = self::adapter();
		// D'abord la locale WordPress (rechargement des traductions), puis la langue de l'extension :
		// certaines extensions (TranslatePress) filtrent la locale d'après leur langue courante, ce qui
		// empêcherait switch_to_locale() de recharger les traductions si l'ordre était inversé (WP-CLI).
		return oli_acr_in_locale(
			$locale,
			static function () use ( $adapter, $locale, $callback, $args ) {
				$previous = $adapter->switch_language( $locale );
				try {
					return call_user_func_array( $callback, $args );
				} finally {
					$adapter->restore_language( $previous );
				}
			}
		);
	}

	/**
	 * Nom d'une chaîne enregistrée dans WPML String Translation / Polylang.
	 *
	 * @param string $key Clé (ex. tpl_cart_1_subject, consent_text).
	 * @return string
	 */
	public static function string_name( $key ) {
		return 'oli_acr_' . $key;
	}

	/**
	 * Enregistre les textes de la langue de repli (modèles et consentement) auprès de l'extension.
	 *
	 * @return void
	 */
	public static function register_strings() {
		$adapter = self::adapter();
		if ( 'core' === $adapter->id() ) {
			return;
		}
		$fallback = self::fallback_language();
		foreach ( OLI_ACR_Templates::all() as $id => $tpl ) {
			$base = OLI_ACR_Templates::base_texts( $tpl, $fallback );
			foreach ( OLI_ACR_Templates::TEXT_FIELDS as $field ) {
				$adapter->register_string( self::string_name( $id . '_' . $field ), (string) $base[ $field ], 'content' === $field );
			}
		}
		$consent = oli_acr_consent_base_text( $fallback );
		if ( '' !== $consent ) {
			$adapter->register_string( self::string_name( 'consent_text' ), $consent, true );
		}
	}

	/**
	 * Indique si le plugin a des textes par défaut traduits pour une locale.
	 *
	 * @param string $locale Locale.
	 * @return bool
	 */
	public static function has_translation( $locale ) {
		if ( 0 === strpos( $locale, 'en_' ) ) {
			return true;
		}
		$file = 'oli-abandoned-cart-recovery-' . $locale . '.mo';
		return file_exists( OLI_ACR_DIR . 'languages/' . $file ) || ( defined( 'WP_LANG_DIR' ) && file_exists( WP_LANG_DIR . '/plugins/' . $file ) );
	}
}
