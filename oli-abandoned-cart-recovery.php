<?php
/**
 * Plugin Name: Oli Abandoned Cart Recovery
 * Plugin URI: https://github.com/bigrat95/oli-abandoned-cart-recovery
 * Description: Lightweight abandoned cart and pending order recovery for WooCommerce. Captures the checkout email as soon as it is typed (classic and block checkout), sends a sequence of reminder emails with unique coupons, and tracks recovered sales.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Olivier Bigras
 * Author URI: https://olivierbigras.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oli-abandoned-cart-recovery
 * Domain Path: /languages
 * WC requires at least: 8.2
 * WC tested up to: 11.1
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 */

defined( 'ABSPATH' ) || exit;

define( 'OLI_ACR_VERSION', '1.1.0' );
define( 'OLI_ACR_DB_VERSION', '1.0.0' );
define( 'OLI_ACR_FILE', __FILE__ );
define( 'OLI_ACR_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLI_ACR_URL', plugin_dir_url( __FILE__ ) );
define( 'OLI_ACR_BASENAME', plugin_basename( __FILE__ ) );
define( 'OLI_ACR_CAP', 'oli_acr_manage' );

require_once OLI_ACR_DIR . 'includes/functions.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-lang.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-install.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-carts.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-templates.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-mailer.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-capture.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-scheduler.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-recovery.php';
require_once OLI_ACR_DIR . 'includes/class-oli-acr-privacy.php';

register_activation_hook( __FILE__, array( 'OLI_ACR_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OLI_ACR_Install', 'deactivate' ) );

// Déclaration de compatibilité HPOS et checkout en blocs.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OLI_ACR_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', OLI_ACR_FILE, true );
		}
	}
);

/**
 * Démarrage du plugin une fois WooCommerce chargé.
 *
 * @return void
 */
function oli_acr_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'oli_acr_missing_wc_notice' );
		return;
	}

	add_action( 'init', 'oli_acr_load_textdomain' );
	OLI_ACR_Lang::init();
	add_action( 'init', array( 'OLI_ACR_Install', 'maybe_upgrade' ), 20 );
	OLI_ACR_Capture::init();
	OLI_ACR_Scheduler::init();
	OLI_ACR_Recovery::init();
	OLI_ACR_Privacy::init();
	OLI_ACR_Mailer::init();

	if ( is_admin() ) {
		require_once OLI_ACR_DIR . 'includes/admin/class-oli-acr-admin.php';
		OLI_ACR_Admin::init();
	}
}
add_action( 'plugins_loaded', 'oli_acr_boot', 20 );

/**
 * Avis quand WooCommerce est absent.
 *
 * @return void
 */
function oli_acr_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Oli Abandoned Cart Recovery requires WooCommerce to be installed and active.', 'oli-abandoned-cart-recovery' ) . '</p></div>';
}

/**
 * Charge les traductions fournies avec le plugin (fr_CA, fr_FR).
 *
 * @return void
 */
function oli_acr_load_textdomain() {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Le plugin n'est pas (encore) hébergé sur wordpress.org et fournit ses propres traductions dans /languages.
	load_plugin_textdomain( 'oli-abandoned-cart-recovery', false, dirname( OLI_ACR_BASENAME ) . '/languages' );
}
