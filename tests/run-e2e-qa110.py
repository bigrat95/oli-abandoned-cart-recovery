#!/usr/bin/env python3
"""Tests de non-régression des bogues de la revue QA de la 1.1.0 (B1 à B5), sur un WordPress LOCAL de test seulement.

Mêmes variables d'environnement que run-e2e.py. Le test B2 retire TEMPORAIREMENT le MU plugin de test
oli-acr-lang-stubs.php (remis en place à la fin, même en cas d'erreur) pour tester Polylang gratuit tel quel.
Usage : run-e2e-qa110.py [b1 b2 b3 b4 b5 ...]"""
import html as htmllib, importlib.util, json, os, re, shutil, sys, time, traceback
HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('r110', os.path.join(HERE, 'run-e2e-r110.py'))
R = importlib.util.module_from_spec(spec); spec.loader.exec_module(R)
spec2 = importlib.util.spec_from_file_location('ml', os.path.join(HERE, 'run-e2e-multilang.py'))
ML = importlib.util.module_from_spec(spec2); spec2.loader.exec_module(ML)
B, MP, WP = R.B, R.MP, R.WP
php, sql, wp, Client, mails_to = R.php, R.sql, R.wp, R.Client, R.mails_to
result, reset, due_cart, cart, opt, STAMP = R.result, R.reset, R.due_cart, R.cart, R.opt, R.STAMP
MU = os.path.join(WP, 'wp-content/mu-plugins')
STUB = os.path.join(MU, 'oli-acr-lang-stubs.php')
SNIPPET = os.path.join(MU, 'oli-acr-pll-wc-snippet.php')

def admin():
    ca = Client(); ca.login('admin', 'admin'); return ca

def save_settings(ca, consent_on):
    _, page, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=settings')
    form = dict(re.findall(r'<input type="hidden" (?:id="[^"]*" )?name="(_wpnonce|_wp_http_referer|action)" value="([^"]*)"', page))
    data = {'action': 'oli_acr_save_settings', '_wpnonce': form.get('_wpnonce', ''), '_wp_http_referer': form.get('_wp_http_referer', ''),
            's[enabled]': 'yes', 's[guest_capture]': 'capture', 's[consent_required]': ['no', 'yes'] if consent_on else 'no',
            's[abandon_after][value]': '1', 's[abandon_after][unit]': 'minutes', 's[cron_interval][value]': '1', 's[cron_interval][unit]': 'minutes', 's[retention_days]': '365'}
    ca.req('/wp-admin/admin-post.php', data)
    return php('echo oli_acr_get_setting("guest_tracking");')

def consent_of(cid):
    return sql(f'SELECT consent FROM wp_oli_acr_carts WHERE id={cid}')[0][0]

def make_due(where):
    sql(f"UPDATE wp_oli_acr_carts SET status='abandoned', abandoned_at=UTC_TIMESTAMP() - INTERVAL 5 MINUTE, next_send_at=UTC_TIMESTAMP() - INTERVAL 1 MINUTE, updated_at=UTC_TIMESTAMP() - INTERVAL 5 MINUTE WHERE {where}")

# ------------------------------------------------------------------ B1 --
def t_b1():
    # 1) Migration 1.0.x en mode « toujours » : paniers captés sans case => consent=0, plus relancés.
    reset('always')
    g = due_cart(f'b1-mig-g-{STAMP}@example.test', consent=1)
    u = due_cart('cliente@example.com', user_id=2, consent=1)
    php('delete_option("oli_acr_version"); OLI_ACR_Install::maybe_upgrade();')
    mode = php('echo oli_acr_get_setting("guest_tracking");')
    php('do_action("oli_acr_process");')
    result('(B1) Migration 1.0.x « toujours » : paniers invité et connecté captés sans case passent à consent=0 et ne sont pas relancés',
           mode == 'consent' and consent_of(g) == '0' and consent_of(u) == '0' and cart(g)[1] == '0' and cart(u)[1] == '0'
           and not mails_to(f'b1-mig-g-{STAMP}@example.test') and not mails_to('cliente@example.com'), f'mode={mode} invité={cart(g)} connecté={cart(u)}')
    # 2) Migration 1.0.x déjà en mode « consentement » : un invité qui avait coché la case reste consenti et relancé.
    reset('consent')
    g2 = due_cart(f'b1-mig-ok-{STAMP}@example.test', consent=1)
    php('delete_option("oli_acr_version"); OLI_ACR_Install::maybe_upgrade();')
    php('do_action("oli_acr_process");')
    result('(B1) Migration 1.0.x en mode consentement : la case cochée en 1.0.x reste valable (consent=1, relance envoyée)',
           consent_of(g2) == '1' and cart(g2)[1] == '1' and len(mails_to(f'b1-mig-ok-{STAMP}@example.test')) == 1, f'{cart(g2)}')
    # 3) Interrupteur OFF : invité et connecté captés sans case => consent=0 ; relancés tant que OFF.
    reset('always')
    eg = f'b1-off-g-{STAMP}@example.test'
    gc = Client(); gc.req('/?add-to-cart=10'); bg = gc.capture_classic(eg)
    uc = Client(); uc.login('cliente', 'cliente'); uc.req('/?add-to-cart=10'); uc.req('/?add-to-cart=11')
    rows = sql(f"SELECT id, consent, user_id FROM wp_oli_acr_carts WHERE email IN ('{eg}', 'cliente@example.com') ORDER BY user_id")
    result('(B1) Interrupteur OFF : invité et client connecté captés sans case avec consent=0 (jamais 1 sans case cochée)',
           len(rows) == 2 and all(r[1] == '0' for r in rows), f'{rows} capture={bg}')
    make_due(f"email IN ('{eg}', 'cliente@example.com')")
    php('do_action("oli_acr_process");')
    sent_off = (len(mails_to(eg)), len(mails_to('cliente@example.com')))
    result('(B1) Interrupteur OFF : ces paniers sont relancés (choix du marchand, sous sa responsabilité)', sent_off == (1, 1), f'courriels={sent_off}')
    # 4) Interrupteur rallumé : les paniers captés pendant OFF ne sont plus relancés.
    R.mp_clear()
    eg2 = f'b1-off2-g-{STAMP}@example.test'
    gc2 = Client(); gc2.req('/?add-to-cart=10'); gc2.capture_classic(eg2)
    sql("TRUNCATE wp_oli_acr_log")
    sql(f"UPDATE wp_oli_acr_carts SET emails_sent=0, sent_templates='' WHERE email IN ('{eg}', 'cliente@example.com')")
    ca = admin()
    mode_on = save_settings(ca, True)
    make_due(f"email IN ('{eg}', '{eg2}', 'cliente@example.com')")
    php('do_action("oli_acr_process");')
    sent_on = (len(mails_to(eg)), len(mails_to(eg2)), len(mails_to('cliente@example.com')))
    result('(B1) Interrupteur rallumé (ON) : aucun panier capté pendant OFF n\'est relancé (invités et connecté)',
           mode_on == 'consent' and sent_on == (0, 0, 0), f'mode={mode_on} courriels={sent_on}')
    # 5) L'invité coche ensuite la case : consent=1, relance.
    gc2.capture_classic(eg2, consent='1')
    cid = sql(f"SELECT id FROM wp_oli_acr_carts WHERE email='{eg2}'")[0][0]
    make_due(f'id={cid}')
    php('do_action("oli_acr_process");')
    result('(B1) Le même invité coche la case plus tard : consent=1 et relance envoyée', consent_of(cid) == '1' and len(mails_to(eg2)) == 1, f'{cart(cid)}')
    # 6) Exception confirmée par Olivier : relances de commandes en attente sans consentement, inchangées.
    out = php('''$s = get_option("oli_acr_settings"); $s["pending_enabled"] = "yes"; $s["pending_after"] = array("value"=>1,"unit"=>"hours"); update_option("oli_acr_settings", $s);
      $o = wc_create_order(); $o->add_product(wc_get_product(10), 1); $o->set_billing_email("b1-order-''' + STAMP + '''@example.test"); $o->set_created_via("checkout");
      $o->set_date_created(time() - 2*DAY_IN_SECONDS); $o->calculate_totals(); $o->set_status("pending"); $o->save();
      $n = OLI_ACR_Scheduler::send_due_orders(); echo $n, "|", oli_acr_consent_required() ? "ON" : "OFF"; $o->delete(true);''')
    result('(B1) Exception : commande en attente relancée sans case de consentement, interrupteur ON (comportement inchangé)',
           out.endswith('|ON') and len(mails_to(f'b1-order-{STAMP}@example.test')) == 1, out)
    reset()

# ------------------------------------------------------------------ B2 --
def t_b2():
    moved = False
    try:
        if os.path.exists(STUB):
            shutil.move(STUB, STUB + '.off'); moved = True
        cfg = ML.setup_polylang()
        ML.reset_plugin()
        stub_loaded = php('echo function_exists("oli_acr_stub_lang") ? "oui" : "non";')
        ca = admin()
        _, dash, _, _ = ca.req('/wp-admin/index.php')
        _, page, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=settings')
        en = f'b2-en-{STAMP}@example.com'; fr = f'b2-fr-{STAMP}@example.com'
        ve = ML.visit(cfg['en'][0], cfg['en'][1], en)
        vf = ML.visit(cfg['fr'][0], cfg['fr'][1], fr)
        n_en = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='{en}'")[0][0]
        n_fr = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='{fr}'")[0][0]
        result('(B2) Sans MU plugin de test, Polylang gratuit seul : avis admin clair (tableau de bord et réglages)',
               stub_loaded == 'non' and 'oli-acr-polylang-wc' in dash and 'oli-acr-polylang-wc' in page and 'Polylang for WooCommerce' in dash, f'stub={stub_loaded}')
        result('(B2) Sans MU plugin : limite documentée constatée (page EN non reconnue : aucun panier EN) ; la page FR capte',
               n_en == '0' and n_fr == '1', f'en={n_en} {ve} fr={n_fr}')
        # Snippet du readme (FAQ « Polylang: what do I need? »), copié tel quel dans un MU plugin.
        readme = open(os.path.join(R.ROOT, 'readme.txt'), encoding='utf-8').read()
        lines = re.findall(r"^`(add_filter\( 'woocommerce_get_(?:checkout|cart)_page_id'.*\);)`$", readme, re.M)
        with open(SNIPPET, 'w') as f:
            f.write('<?php\n// Snippet de la FAQ du readme (test B2).\n' + '\n'.join(lines) + '\n')
        _, dash2, _, _ = ca.req('/wp-admin/index.php')
        en2 = f'b2-en2-{STAMP}@example.com'
        ve2 = ML.visit(cfg['en'][0], cfg['en'][1], en2)
        row = sql(f"SELECT COUNT(*), IFNULL(MAX(language),'') FROM wp_oli_acr_carts WHERE email='{en2}'")[0]
        result('(B2) Avec le snippet du readme (sans MU plugin de test) : plus d\'avis, panier EN capté en en_US',
               len(lines) == 2 and 'oli-acr-polylang-wc' not in dash2 and row == ['1', 'en_US'], f'lignes={len(lines)} {row} {ve2}')
        # Avis seulement pour Polylang : rien avec TranslatePress ou sans extension.
        os.remove(SNIPPET)
        ML.plugins([])
        _, dash3, _, _ = ca.req('/wp-admin/index.php')
        result('(B2) Aucun avis Polylang quand Polylang n\'est pas actif', 'oli-acr-polylang-wc' not in dash3, '')
    finally:
        if os.path.exists(SNIPPET):
            os.remove(SNIPPET)
        if moved:
            shutil.move(STUB + '.off', STUB)
        ML.plugins([])
        ML.set_stub('')

# ------------------------------------------------------------------ B3 --
def t_b3():
    reset('always')
    php('delete_option("oli_acr_version"); OLI_ACR_Install::maybe_upgrade();')
    ca = admin()
    _, d1, _, _ = ca.req('/wp-admin/index.php')
    on = 'oli-acr-consent-migrated' in d1 and 'oli-acr-consent-off' not in d1
    mode = save_settings(ca, False)
    _, d2, _, _ = ca.req('/wp-admin/index.php')
    _, sp, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=settings')
    flag = php('echo get_option("oli_acr_notice_consent_migrated") ? "1" : "0";')
    result('(B3) Interrupteur OFF : seul l\'avertissement Loi 25/RGPD s\'affiche, plus l\'avis contradictoire « consentement maintenant exigé »',
           on and mode == 'always' and 'oli-acr-consent-off' in d2 and 'oli-acr-consent-migrated' not in d2 and 'oli-acr-consent-migrated' not in sp and flag == '0',
           f'avant={on} mode={mode} avis_migration={flag}')
    # Même si l'option existe encore (réglage modifié hors de l'écran), l'avis n'est pas affiché avec OFF.
    php('update_option("oli_acr_notice_consent_migrated", time());')
    _, d3, _, _ = ca.req('/wp-admin/index.php')
    mode2 = save_settings(ca, True)
    _, d4, _, _ = ca.req('/wp-admin/index.php')
    result('(B3) Avis de migration masqué tant que OFF (même option présente) ; ON : il revient jusqu\'à sa fermeture',
           'oli-acr-consent-migrated' not in d3 and mode2 == 'consent' and 'oli-acr-consent-migrated' in d4 and 'oli-acr-consent-off' not in d4, f'mode={mode2}')
    reset()

# ------------------------------------------------------------------ B4 --
def t_b4():
    reset()
    php('$t = get_option("oli_acr_templates"); $t["tpl_cart_1"]["coupon_enabled"] = "yes"; $t["tpl_cart_1"]["coupon_amount"] = 10; $t["tpl_cart_1"]["coupon_type"] = "percent"; update_option("oli_acr_templates", $t);')
    has_tag = php('$t = OLI_ACR_Templates::for_locale("tpl_cart_1", get_option("oli_acr_templates")["tpl_cart_1"], "fr_CA"); echo OLI_ACR_Templates::shows_coupon($t) ? "oui" : "non";')
    email = f'b4-{STAMP}@example.test'
    cid = due_cart(email)
    php('do_action("oli_acr_process");')
    coupons = php('echo count(get_posts(array("post_type"=>"shop_coupon","post_status"=>"any","numberposts"=>-1)));')
    logc = sql(f"SELECT IFNULL(coupon_code,'') FROM wp_oli_acr_log WHERE object_id={cid}")
    ca = admin()
    _, lst, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates')
    _, edit, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates&edit=tpl_cart_1')
    result('(B4) Coupon activé sans {coupon}/{coupon_code} dans le modèle : relance envoyée, AUCUN coupon créé',
           has_tag == 'non' and cart(cid)[1] == '1' and coupons == '0' and (not logc or logc[0][0] == ''), f'balise={has_tag} coupons={coupons} journal={logc}')
    result('(B4) Avertissement à l\'admin : liste des modèles et éditeur (« No coupon will be created » / « Aucun coupon ne sera créé »)',
           'oli-acr-coupon-missing' in lst and 'oli-acr-coupon-missing' in edit, '')
    # Avec {coupon} dans toutes les langues : coupon créé et montré, plus d'avertissement.
    php('''$t = get_option("oli_acr_templates"); foreach (OLI_ACR_Lang::languages() as $l) { $r = OLI_ACR_Templates::for_locale("tpl_cart_1", $t["tpl_cart_1"], $l); $t["tpl_cart_1"]["texts"][$l] = array("content" => $r["content"] . "<p>{coupon}</p>"); } update_option("oli_acr_templates", $t);''')
    R.mp_clear()
    email2 = f'b4b-{STAMP}@example.test'
    cid2 = due_cart(email2)
    php('do_action("oli_acr_process");')
    code = php('$p = get_posts(array("post_type"=>"shop_coupon","post_status"=>"any","numberposts"=>1)); echo $p ? $p[0]->post_title : "";')
    m = mails_to(email2)
    body = R.mail_get(m[0]['ID']).get('HTML', '') if m else ''
    _, lst2, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates')
    result('(B4) Avec {coupon} : coupon créé et visible dans le courriel ; plus d\'avertissement',
           code != '' and code.upper() in body.upper() and 'oli-acr-coupon-missing' not in lst2, f'coupon={code} dans_courriel={code.upper() in body.upper()}')
    reset()

# ------------------------------------------------------------------ B5 --
def t_b5():
    reset()
    labels = php('''$o = array();
      foreach (array("en_US", "fr_CA") as $l) { switch_to_locale($l);
        $o[] = oli_acr_duration_label(array("value"=>1,"unit"=>"hours")) . "/" . oli_acr_duration_label(array("value"=>2,"unit"=>"hours")) . "/" . oli_acr_duration_label(array("value"=>1,"unit"=>"days")) . "/" . oli_acr_duration_label(array("value"=>30,"unit"=>"minutes"));
        restore_previous_locale(); } echo implode("|", $o);''')
    result('(B5) Durées au singulier et au pluriel avec _n() (en_US et fr_CA)',
           labels == '1 hour/2 hours/1 day/30 minutes|1 heure/2 heures/1 jour/30 minutes', labels)
    # Liste des modèles en anglais (profil admin en_US) : « 1 hour », jamais « 1 hours ».
    php('$t = get_option("oli_acr_templates"); $t["tpl_cart_1"]["delay"] = array("value"=>1,"unit"=>"hours"); update_option("oli_acr_templates", $t); update_user_meta(1, "locale", "en_US");')
    try:
        ca = admin()
        _, lst, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates')
        result('(B5) Liste des modèles (admin en_US) : « 1 hour », aucun « 1 hours »', '>1 hour<' in lst and '1 hours' not in lst, re.findall(r'<td>(\d+ \w+)</td>', lst)[:5])
    finally:
        php('delete_user_meta(1, "locale");')
    # Avis d'échec : date et heure au format du site (date_i18n), traduites.
    old = php('echo get_option("date_format"), "|", get_option("time_format");').split('|')
    try:
        ts = 1790000000  # 2026-09-21 14:13:20 UTC
        php(f'update_option("date_format", "j F Y"); update_option("time_format", "G \\\\h i"); update_option("oli_acr_mail_failure", array("count"=>1, "time"=>{ts}, "error"=>"SMTP Error: Could not connect"));')
        expected = php(f'echo date_i18n("j F Y G \\\\h i", {ts} + (int) round((float) get_option("gmt_offset") * HOUR_IN_SECONDS));')
        ca = admin()
        _, d, _, _ = ca.req('/wp-admin/index.php')
        m = re.search(r'oli-acr-mail-failure.{0,600}', d, re.S)
        txt = htmllib.unescape(re.sub('<[^>]+>', ' ', m.group(0))) if m else ''
        result('(B5) Avis d\'échec : date au format du site (Réglages > Général), traduite, et « 1 échec » au singulier',
               expected in txt and 'septembre' in expected and '1 échec,' in txt and 'échec(s)' not in txt and ' le septembre' not in txt, f'attendu={expected} avis={txt[:250]}')
        php('update_option("date_format", "Y-m-d"); update_option("time_format", "H:i"); update_option("oli_acr_mail_failure", array("count"=>3, "time"=>' + str(ts) + ', "error"=>"x"));')
        expected2 = php(f'echo date_i18n("Y-m-d H:i", {ts} + (int) round((float) get_option("gmt_offset") * HOUR_IN_SECONDS));')
        _, d2, _, _ = ca.req('/wp-admin/index.php')
        result('(B5) Autre format du site (Y-m-d H:i) respecté ; pluriel « 3 échecs »', expected2 in d2 and '3 échecs' in d2, expected2)
    finally:
        php(f'update_option("date_format", {json.dumps(old[0])}); update_option("time_format", {json.dumps(old[1] if len(old) > 1 else "H:i")}); delete_option("oli_acr_mail_failure");')
    reset()

TESTS = {'b1': t_b1, 'b2': t_b2, 'b3': t_b3, 'b4': t_b4, 'b5': t_b5}
if __name__ == '__main__':
    for name in (sys.argv[1:] or list(TESTS)):
        print(f'\n===== {name} =====', flush=True)
        try:
            TESTS[name]()
        except Exception:
            result(f'({name}) exception', False, traceback.format_exc()[-800:])
    print()
    for n, ok, _ in R.RESULTS:
        print('PASS' if ok else 'FAIL', n)
    print(f'{sum(1 for r in R.RESULTS if r[1])}/{len(R.RESULTS)}')
