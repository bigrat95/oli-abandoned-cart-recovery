#!/bin/bash
# B2 : suite multilingue Polylang complète SANS le MU plugin de test (oli-acr-lang-stubs.php), avec seulement
# le snippet de la FAQ du readme (pages panier/paiement traduites reliées à WooCommerce). Restaure tout à la fin.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
MU="${OLI_ACR_E2E_WP:?}/wp-content/mu-plugins"
mv "$MU/oli-acr-lang-stubs.php" "$MU/oli-acr-lang-stubs.php.off"
trap 'rm -f "$MU/oli-acr-pll-wc-snippet.php"; mv "$MU/oli-acr-lang-stubs.php.off" "$MU/oli-acr-lang-stubs.php"; (cd "$OLI_ACR_E2E_WP" && wp plugin deactivate polylang >/dev/null 2>&1)' EXIT
{ echo '<?php'; echo '// Snippet de la FAQ du readme (B2).'; grep -E "^\`add_filter\( 'woocommerce_get_(checkout|cart)_page_id'" "$HERE/../readme.txt" | sed 's/^`//; s/`$//'; } > "$MU/oli-acr-pll-wc-snippet.php"
python3 "$HERE/run-e2e-multilang.py" polylang
