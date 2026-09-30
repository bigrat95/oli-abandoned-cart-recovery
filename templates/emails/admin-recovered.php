<?php
/**
 * Avis admin : vente récupérée (HTML). Peut être surchargé dans le thème : woocommerce/emails/admin-recovered.php.
 *
 * @package OliAbandonedCartRecovery
 * @author  Olivier Bigras (bigrat95)
 * @link    https://olivierbigras.com
 * @var WC_Order $order
 * @var string   $recovery_type
 * @var string   $email_heading
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Crochet de WooCommerce. ?>

<p>
<?php
if ( 'order' === $recovery_type ) {
	/* translators: %s: order number. */
	printf( esc_html__( 'Good news! Pending order #%s was paid after a reminder email.', 'oli-abandoned-cart-recovery' ), esc_html( $order->get_order_number() ) );
} else {
	/* translators: %s: order number. */
	printf( esc_html__( 'Good news! An abandoned cart was recovered and became order #%s.', 'oli-abandoned-cart-recovery' ), esc_html( $order->get_order_number() ) );
}
?>
</p>

<?php
do_action( 'woocommerce_email_order_details', $order, true, false, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Crochet de WooCommerce.
do_action( 'woocommerce_email_customer_details', $order, true, false, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Crochet de WooCommerce.
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Crochet de WooCommerce.
