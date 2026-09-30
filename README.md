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
- Mode consentement (case sous le champ courriel, texte réglable, classique et blocs) et choix des rôles suivis.
- Photo du panier : produits, quantités, variations, total, devise et langue, reliée à la session WooCommerce et à un jeton de 32 caractères.

### Relances
- Plusieurs modèles, chacun avec délai, sujet, en-tête, adresse de réponse, contenu, libellé du bouton et statut actif/inactif, envoyés en séquence.
- Balises : `{first_name}`, `{last_name}`, `{full_name}`, `{email}`, `{cart_items}`, `{cart_total}`, `{recovery_link}`, `{recovery_button}`, `{coupon}`, `{coupon_code}`, `{unsubscribe_link}`, `{site_name}`, `{site_url}`, `{order_number}`, `{order_date}`.
- Gabarit WooCommerce (`WC()->mailer()->wrap_message()` + `WC_Emails::send()`), en-tête `List-Unsubscribe`.
- Coupons uniques (préfixe, pourcentage ou montant fixe, validité, usage unique, restreints au courriel du client), appliqués automatiquement au clic, puis mis à la corbeille une fois utilisés ou expirés.
- Commandes en attente : modèles dédiés, délai de départ, lien « payer la commande », annulation automatique des vieilles commandes relancées.
- « Envoyer un test » vers l'adresse de son choix, et « Envoyer la prochaine relance maintenant » pour un panier ou une commande.
- Avis à l'administrateur (classe `WC_Email` « Panier récupéré (admin) ») quand un panier ou une commande est récupéré.

### Arrêt, désabonnement et vie privée
- Dès qu'une commande est passée avec la même session ou le même courriel : panier relié à la commande, relances arrêtées ; statut « récupéré » quand la commande est payée (en cours, terminée ou en attente de paiement manuel).
- Lien de désabonnement signé (HMAC), page de confirmation, désabonnement en un clic (RFC 8058), liste d'exclusion modifiable.
- Exporteur et effaceur WordPress, texte suggéré pour la politique de confidentialité.
- Nettoyage quotidien : vieux paniers non récupérés, durée de conservation globale, coupons, vieilles commandes en attente relancées.

### Admin (WooCommerce > Paniers abandonnés)
Tableau de bord et rapports · Paniers abandonnés (WP_List_Table, filtres par statut, recherche, suppression groupée) · Commandes en attente · Récupérés · Journal des courriels · Modèles de courriels · Réglages. Accès du rôle `shop_manager` en option (capacité `oli_acr_manage`).

### Performance
- Deux tables maison indexées (`{prefix}oli_acr_carts`, `{prefix}oli_acr_log`) créées par `dbDelta`, avec version de schéma.
- Un seul `UPDATE` indexé (`status, updated_at`) marque les paniers abandonnés ; les envois utilisent l'index (`status, next_send_at`) et des lots de 50 (filtre `oli_acr_batch_size`).
- Action Scheduler (groupe `oli-abandoned-cart-recovery`), WP-Cron en secours. Aucun travail au front hors du script de checkout (~2 Ko, sans jQuery).

## Correspondance YITH Recover Abandoned Cart 3.8.0 → Oli

| Fonction YITH | Équivalent Oli | Notes |
|---|---|---|
| Type de publication `ywrac_cart` | Table indexée `oli_acr_carts` | Plus léger, requêtes indexées |
| Capture AJAX du courriel invité (`ywrac_grab_guest`) | `wc-ajax=oli_acr_capture` | + checkout en blocs |
| Capture du téléphone invité | Téléphone capté avec le courriel | Idem |
| « Recover carts of guest users » : never / ever / privacy | Paniers des visiteurs : jamais / toujours / avec consentement | Texte réglable, case classique + blocs |
| Sélection des rôles (`ywrac_user_selection`, `ywrac_user_roles`) | Clients inscrits suivis : tous / rôles choisis | Idem |
| Cut-off time | « Considérer un panier comme abandonné après » | Minutes / heures / jours |
| Intervalle du CRON (`ywrac_cron_config`) | « Exécuter la tâche aux… » | Action Scheduler, WP-Cron en secours |
| Modèles de courriels (CPT `ywrac_email`) : délai, sujet, contenu, envoi auto | Modèles : délai, sujet, en-tête, réponse, contenu, bouton, actif | Stockés en option, envoyés en séquence |
| Balises `{{ywrac.firstname}}`, `{{ywrac.cart}}`, `{{ywrac.coupon}}`, `{{ywrac.recoverbutton}}`, `[ywrac_unsubscribe]` | `{first_name}`, `{cart_items}`, `{coupon}`, `{recovery_button}`, `{unsubscribe_link}`… | 15 balises |
| Coupon par modèle (montant, type, validité, préfixe) | Idem | + restriction au courriel + application auto au clic |
| Suppression des coupons utilisés / expirés | Idem (corbeille, tâche quotidienne) | |
| Commandes en attente (pending) | Idem + délai de départ propre | Lien sécurisé vers « payer la commande » |
| « Delete pending orders after » (via `woocommerce_hold_stock_minutes`) | « Annuler les commandes en attente relancées après » | Ne touche que les commandes relancées, ne modifie pas le réglage WooCommerce |
| « Delete abandoned carts after » | « Supprimer les paniers non récupérés après » + conservation globale | |
| Lien de récupération chiffré (`rec_cart`) | Jeton aléatoire de 32 caractères + ID du journal | Remplit le panier et le courriel, redirige au checkout |
| Page et liste de désabonnement (`ywrac_mail_blacklist`) | Lien signé HMAC, confirmation, un clic, liste d'exclusion | Aucun envoi aux désabonnés |
| Arrêt après commande (`remove_abandoned_cart_for_current_user`) | Liaison session/courriel → commande, statut « récupéré » | Le panier reste, relié à la commande |
| Courriel admin « panier récupéré » | `WC_Email` « Panier récupéré (admin) » | Réglable dans WooCommerce > Réglages > E-mails |
| Courriel test | Bouton « Envoyer un test » | |
| Onglets Paniers / Commandes en attente / Récupérés / Journal / Rapports | Mêmes onglets | Rapports par période, par jour et par modèle |
| Option « shop manager » | Idem, capacité dédiée `oli_acr_manage` | |
| Exporteur / effaceur / texte de confidentialité | Idem | |
| Compatibilité HPOS | Déclarée et testée | + blocs panier/checkout |

**Différences assumées :** pas de cadre YITH ni de page d'options propriétaire ; modèles en option plutôt qu'en type de publication (pas de traduction WPML par modèle : les modèles sont créés dans la langue du site) ; la liste « Récupérés » se base sur les commandes (HPOS) ; les vieilles commandes en attente sont annulées plutôt que supprimées ; le lien de récupération mène au checkout plutôt qu'au panier ; un panier commandé sans relance est aussi marqué « récupéré » (mention « commandé sans relance ») mais n'entre pas dans les statistiques de récupération.

## Développement

```bash
composer install          # phpcs + WordPress Coding Standards
composer lint             # vendor/bin/phpcs (règles dans .phpcs.xml.dist)
python3 tools/build-translations.py   # .po/.mo fr_CA et fr_FR à partir du .pot
python3 tests/run-e2e.py  # tests de bout en bout sur un WordPress LOCAL + Mailpit
```

Le dossier `.wordpress-org/` contient l'icône, les bannières et les captures pour la fiche wordpress.org.

## Licence
GPL-2.0-or-later
