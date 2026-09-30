<?php
/**
 * Avis admin : vente récupérée (texte).
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

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
if ( 'order' === $recovery_type ) {
	/* translators: %s: order number. */
	printf( esc_html__( 'Good news! Pending order #%s was paid after a reminder email.', 'oli-abandoned-cart-recovery' ), esc_html( $order->get_order_number() ) );
} else {
	/* translators: %s: order number. */
	printf( esc_html__( 'Good news! An abandoned cart was recovered and became order #%s.', 'oli-abandoned-cart-recovery' ), esc_html( $order->get_order_number() ) );
}
echo "\n\n";
do_action( 'woocommerce_email_order_details', $order, true, true, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Crochet de WooCommerce.
echo "\n";
echo esc_html( wp_strip_all_tags( wptexturize( $email->get_additional_content() ) ) );
