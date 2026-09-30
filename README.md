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

### Relances
- Plusieurs modèles, chacun avec délai, sujet, en-tête, adresse de réponse, contenu, libellé du bouton et statut actif/inactif, envoyés en séquence.
- Balises : `{first_name}`, `{last_name}`, `{full_name}`, `{email}`, `{cart_items}`, `{cart_total}`, `{recovery_link}`, `{recovery_button}`, `{coupon}`, `{coupon_code}`, `{unsubscribe_link}`, `{site_name}`, `{site_url}`, `{order_number}`, `{order_date}`.
- Envoi dans la langue enregistrée avec le panier (`switch_to_locale()` / `restore_previous_locale()`). Les modèles par défaut restent traduisibles et sont rendus dans cette langue tant que l'admin ne les a pas modifiés ; un modèle modifié part tel qu'écrit (pas de modèle distinct par langue).
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
