# Oli Abandoned Cart Recovery

Extension WooCommerce légère de récupération des **paniers abandonnés** et des **commandes en attente**. Le courriel est capturé dès qu'il est tapé au checkout (classique **et** en blocs), même pour un visiteur qui ne passe jamais de commande. Une séquence de relances part ensuite, avec des coupons uniques, un lien de récupération sécurisé, un lien de désabonnement et des rapports.

- **Slug / text domain :** `oli-abandoned-cart-recovery`
- **Préfixe :** `oli_acr_` / `OLI_ACR_` (fonctions, options, tables, crochets, constantes)
- **Exigences :** WordPress 6.4+, WooCommerce 8.2+, PHP 7.4+
- **Compatibilité :** HPOS (`custom_order_tables`) et blocs panier/checkout (`cart_checkout_blocks`) déclarés
- **Auteur :** Olivier Bigras ([bigrat95](https://profiles.wordpress.org/bigrat95/))

## Fonctionnalités

### Capture
- Courriel envoyé en AJAX (`?wc-ajax=oli_acr_capture`) sur `change`/`blur`/`input` avec un anti-rebond (800 ms), un nonce et la validation `is_email` côté serveur.
- Un seul écouteur délégué sur le document : fonctionne avec le checkout classique (`#billing_email`) et en blocs (`#email`).
- Téléphone, prénom et nom captés avec le courriel.
- Clients connectés : panier mis à jour à chaque changement (`woocommerce_cart_updated`, comparaison d'empreinte pour éviter les écritures inutiles).
- **Consentement d'abord (défaut depuis 1.0.1, Loi 25 / RGPD)** : pour un visiteur non connecté, rien n'est transmis ni enregistré (ni courriel, ni téléphone, ni panier) tant que la case de consentement n'est pas cochée (classique et blocs). Le texte par défaut suit la langue du visiteur ; un texte personnalisé est possible. Modes « toujours » et « jamais » offerts dans les réglages ; une installation 1.0.0 garde le mode déjà enregistré.
- Choix des rôles suivis.
- Photo du panier : produits, quantités, variations, total, devise et langue, reliée à la session WooCommerce et à un jeton de 32 caractères.

### Multilingue (1.1.0)
- **Adaptateurs** (`includes/lang/`) : classe de base `OLI_ACR_Lang_Adapter` ; `OLI_ACR_Lang_WPML`, `_Polylang`, `_TranslatePress`, `_Weglot`, `_Core`. Priorité : WPML, Polylang, TranslatePress, Weglot, puis le cœur. Filtre `oli_acr_lang_adapters` pour en ajouter ou en retirer. Toutes les langues sont des locales WordPress (`fr_CA`, `en_US`).
- **Modèles par langue** : `texts[locale] = {name, subject, heading, content, button_label}` ; les champs de premier niveau sont le miroir de la langue de repli (compatibilité 1.0.x et filtres). Délai, coupon, type et statut sont communs.
- **Consentement par langue** : réglage `consent_texts[locale]` (`consent_text` = miroir de la langue de repli).
- **Langue de repli** : réglage `fallback_language` (vide = langue par défaut du site ou de l'extension).
- **Ordre de priorité, champ par champ** (`OLI_ACR_Templates::for_locale()`, `oli_acr_consent_text()`) :
  1. texte saisi dans l'onglet de la langue ;
  2. traduction WPML String Translation / Polylang de la chaîne de la langue de repli (domaine / groupe `oli-abandoned-cart-recovery` / « Oli Abandoned Cart Recovery », noms `oli_acr_{id}_{champ}` et `oli_acr_consent_text`) ;
  3. texte par défaut traduit dans la langue (si le champ de repli est encore celui par défaut et si le plugin a cette traduction) ;
  4. texte de la langue de repli.
- **Langue du panier** : transmise par la page (`oliAcrCapture.lang`, calculée au rendu de la page de paiement), car l'appel `wc-ajax` ne passe pas toujours par l'URL de la langue.
- **Liens** : récupération et désabonnement construits sur l'accueil de la langue (`OLI_ACR_Lang::home_url()`), redirection vers la page de paiement de la langue (`OLI_ACR_Lang::checkout_url()`, page traduite avec WPML / Polylang), page de désabonnement affichée dans la langue (`oli_acr_lang`). Filtre `oli_acr_translate_url`.
- **Migration 1.0.x → 1.1.0** (`OLI_ACR_Install::migrate()`, idempotente) : textes existants = textes de la langue de repli ; modèles par défaut non modifiés = textes par défaut dans chaque langue active ; une langue ajoutée plus tard est remplie de la même façon.
- **Polylang + WooCommerce** : pour que la page de paiement traduite soit reconnue comme page de paiement, il faut « Polylang for WooCommerce » (ou l'équivalent du filtre `woocommerce_get_checkout_page_id`).
- **Weglot** traduit le HTML à la volée et n'a pas de module de chaînes : utilisez les onglets de langue.

### Relances
- Plusieurs modèles, chacun avec délai, sujet, en-tête, adresse de réponse, contenu, libellé du bouton et statut actif/inactif, envoyés en séquence.
- Balises : `{first_name}`, `{last_name}`, `{full_name}`, `{email}`, `{cart_items}`, `{cart_total}`, `{recovery_link}`, `{recovery_button}`, `{coupon}`, `{coupon_code}`, `{unsubscribe_link}`, `{site_name}`, `{site_url}`, `{order_number}`, `{order_date}`.
- Envoi dans la langue enregistrée avec le panier (locale WordPress et langue de l'extension multilingue), voir « Multilingue » ci-dessous.
- Balise `{coupon_amount}` (rabais formaté) ; les modèles par défaut ont une phrase d'introduction traduisible avant le code, retirée s'il n'y a pas de coupon.
- Gabarit WooCommerce (`WC()->mailer()->wrap_message()` + `WC_Emails::send()`), en-têtes `List-Unsubscribe` et `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058).
- Coupons uniques (préfixe, pourcentage ou montant fixe, validité, usage unique, restreints au courriel du client), appliqués automatiquement au clic, puis mis à la corbeille une fois utilisés ou expirés.
- Commandes en attente : modèles dédiés, délai de départ, lien « payer la commande », annulation automatique des vieilles commandes relancées.
- « Envoyer un test » vers l'adresse de son choix, et « Envoyer la prochaine relance maintenant » pour un panier ou une commande.
- Avis à l'administrateur (classe `WC_Email` « Panier récupéré (admin) ») quand un panier ou une commande est récupéré.

### Arrêt, désabonnement et vie privée
- Dès qu'une commande est passée avec la même session ou le même courriel : panier relié à la commande, relances arrêtées ; statut « récupéré » quand la commande est payée (en cours, terminée ou en attente de paiement manuel).
- Lien de désabonnement signé (HMAC), page de confirmation, désabonnement en un clic (RFC 8058), liste d'exclusion modifiable.
- Exporteur et effaceur WordPress, texte suggéré pour la politique de confidentialité.
- Désinstallation complète : tables, options, capacité, actions, journaux et groupe Action Scheduler, WP-Cron, fichiers wc-logs du plugin, métas de commandes/utilisateurs/coupons (dont le consentement du checkout en blocs), clés de session et coupons générés jamais utilisés. Les coupons générés déjà utilisés restent (liés à des commandes), sans le marqueur du plugin. Option « Garder les données à la désinstallation », désactivée par défaut.
- Nettoyage quotidien : vieux paniers non récupérés, durée de conservation globale, coupons, vieilles commandes en attente relancées.

### Admin (WooCommerce > Paniers abandonnés)
Tableau de bord et rapports · Paniers abandonnés (WP_List_Table, filtres par statut, recherche, suppression groupée) · Commandes en attente · Récupérés · Journal des courriels · Modèles de courriels · Réglages. Accès du rôle `shop_manager` en option (capacité `oli_acr_manage`).

### Performance
- Deux tables maison indexées (`{prefix}oli_acr_carts`, `{prefix}oli_acr_log`) créées par `dbDelta`, avec version de schéma.
- Un seul `UPDATE` indexé (`status, updated_at`) marque les paniers abandonnés ; les envois utilisent l'index (`status, next_send_at`) et des lots de 50 (filtre `oli_acr_batch_size`).
- Action Scheduler (groupe `oli-abandoned-cart-recovery`), WP-Cron en secours. Aucun travail au front hors du script de checkout (~2 Ko, sans jQuery).

## Développement

```bash
composer install          # outils de développement seulement (vendor/ est ignoré par git et exclu du zip)
composer lint             # PHPCS : WordPress-Extra, WordPress-Docs, PHPCompatibilityWP 7.4+ (phpcs.xml.dist)
composer analyse          # PHPStan niveau 6 + phpstan-wordpress + stubs WooCommerce (phpstan.neon.dist)
wp plugin check oli-abandoned-cart-recovery   # Plugin Check officiel, sur un WordPress de test
wp i18n make-pot . languages/oli-abandoned-cart-recovery.pot --exclude=vendor,tests,tools
bash tools/build-zip.sh   # zip de distribution (sans vendor, tests, tools, phpcs.xml.dist ni phpstan.neon.dist)
# Tests de bout en bout, sur un WordPress LOCAL de test et une instance Mailpit DÉDIÉE (le setup vide la boîte) :
OLI_ACR_E2E_URL=http://localhost:8898 OLI_ACR_E2E_MAILPIT=http://127.0.0.1:8026 OLI_ACR_E2E_WP=/chemin/wp python3 tests/run-e2e.py
```

Le dossier `.wordpress-org/` contient l'icône, les bannières et les captures pour la fiche wordpress.org.

## Licence
GPLv2 or later
