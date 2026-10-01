#!/usr/bin/env bash
# Rejoue la suite E2E (run-e2e.py) sans extension multilingue puis avec chacune.
# Usage : run-e2e-matrix.sh [none translatepress polylang wpml weglot]   (WordPress LOCAL de test seulement)
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
WPD="${OLI_ACR_E2E_WP:-$(pwd)}"
for mode in "${@:-none translatepress polylang wpml weglot}"; do
  for m in $mode; do
    (cd "$WPD" && wp plugin deactivate translatepress-multilingual polylang >/dev/null 2>&1; wp option update oli_acr_test_lang_stub '' >/dev/null)
    case "$m" in
      translatepress) (cd "$WPD" && wp plugin activate translatepress-multilingual >/dev/null) ;;
      polylang) (cd "$WPD" && wp plugin activate polylang >/dev/null && wp option update oli_acr_test_lang_stub polylang >/dev/null) ;;
      wpml|weglot) (cd "$WPD" && wp option update oli_acr_test_lang_stub "$m" >/dev/null) ;;
    esac
    echo "===== Suite E2E : $m ====="
    (cd "$WPD" && python3 "$HERE/run-e2e.py") > "$HERE/e2e-run-$m.log" 2>&1
    echo "exit=$? $(grep -c '^PASS -' "$HERE/e2e-run-$m.log") PASS, $(grep -c '^FAIL -' "$HERE/e2e-run-$m.log") FAIL"
    grep '^FAIL -' "$HERE/e2e-run-$m.log" | cut -c1-300
    [ -f "$HERE/e2e-results-fr_CA.json" ] && cp "$HERE/e2e-results-fr_CA.json" "$HERE/e2e-results-$m.json"
  done
done
(cd "$WPD" && wp plugin deactivate translatepress-multilingual polylang >/dev/null 2>&1; wp option delete oli_acr_test_lang_stub >/dev/null 2>&1)
