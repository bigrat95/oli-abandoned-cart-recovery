#!/usr/bin/env python3
"""Tests E2E multilingues (1.1.0) sur un WordPress LOCAL de test seulement.

Modes : core, translatepress, polylang (extensions réelles, versions gratuites), wpml, weglot (simulés par
le MU plugin de test tests/stubs/oli-acr-lang-stubs.php), n1 (admin en_US). Mêmes variables d'environnement
que run-e2e.py. Le setup VIDE la boîte Mailpit indiquée et réinitialise les données du plugin.
Usage : run-e2e-multilang.py [mode ...]"""
import importlib.util, json, os, re, subprocess, sys, time, urllib.request, urllib.parse
HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('e2e', os.path.join(HERE, 'run-e2e.py'))
E = importlib.util.module_from_spec(spec); spec.loader.exec_module(E)
B, MP, WP = E.B, E.MP, E.WP
php, sql, wp, Client, mails_to, mail_get, links = E.php, E.sql, E.wp, E.Client, E.mails_to, E.mail_get, E.links
RESULTS = []
def result(name, ok, detail=''):
    RESULTS.append((name, bool(ok), detail))
    print(('PASS' if ok else 'FAIL'), '-', name, '-', str(detail)[:600], flush=True)

NODE_PATH = '/usr/local/lib/pnpm/5/.pnpm/playwright-core@1.59.1/node_modules'
FR_CONSENT = 'Enregistrer mon courriel'
EN_CONSENT = 'Save my email'
FR_SUBJ = 'oublié quelque chose'
EN_SUBJ = 'something in your cart'

def visit(product_url, checkout_url, email):
    env = dict(os.environ, PRODUCT_URL=product_url, CHECKOUT_URL=checkout_url, EMAIL=email, NODE_PATH=NODE_PATH)
    out = subprocess.run(['node', os.path.join(HERE, 'ml-visit.js')], env=env, capture_output=True, text=True, timeout=120)
    try:
        return json.loads(out.stdout.strip().splitlines()[-1])
    except Exception:
        return {'error': out.stdout[-300:] + out.stderr[-300:]}

def set_stub(mode):
    php('update_option("oli_acr_test_lang_stub", "' + mode + '");')

def plugins(active):
    for p in ('translatepress-multilingual', 'polylang'):
        wp('plugin', 'activate' if p in active else 'deactivate', p, check=False)

def reset_plugin():
    urllib.request.urlopen(urllib.request.Request(MP + '/api/v1/messages', method='DELETE'))
    php('''
    global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}oli_acr_carts"); $wpdb->query("TRUNCATE {$wpdb->prefix}oli_acr_log");
    update_option("oli_acr_blocklist", array());
    $s = oli_acr_default_settings();
    $s["abandon_after"] = array("value"=>1,"unit"=>"minutes");
    $s["cron_interval"] = array("value"=>1,"unit"=>"minutes");
    update_option("oli_acr_settings", $s);
    OLI_ACR_Lang::reset();
    $t = OLI_ACR_Templates::default_templates();
    $t["tpl_cart_1"]["delay"] = array("value"=>0,"unit"=>"minutes");
    update_option("oli_acr_templates", $t);
    delete_option("oli_acr_languages_signature");
    foreach ( get_posts(array("post_type"=>"shop_coupon","post_status"=>"any","numberposts"=>-1,"fields"=>"ids")) as $id ) { wp_delete_post($id, true); }
    ''')

def setup_translatepress():
    plugins(['translatepress-multilingual']); set_stub('')
    php('''
    $s = get_option("trp_settings", array());
    $s["default-language"] = "fr_CA";
    $s["translation-languages"] = array("fr_CA","en_US");
    $s["publish-languages"] = array("fr_CA","en_US");
    $s["url-slugs"] = array("fr_CA"=>"fr","en_US"=>"en");
    $s["add-subdirectory-to-default-language"] = "no";
    $s["force-language-to-custom-links"] = "yes";
    update_option("trp_settings", $s);
    ''')
    return {'fr': (B + '/product/tuque-en-laine/', B + '/checkout/'), 'en': (B + '/en/product/tuque-en-laine/', B + '/en/checkout/'),
            'en_mark': lambda u: '/en/' in u, 'en_checkout': lambda u: '/en/checkout' in u}

def setup_polylang():
    plugins(['polylang']); set_stub('polylang')
    out = php(r'''
    $model = PLL()->model;
    $have = wp_list_pluck( $model->get_languages_list(), "slug" );
    $add = function( $args ) use ( $model ) { return method_exists( $model, "add_language" ) ? $model->add_language( $args ) : $model->languages->add( $args ); };
    if ( ! in_array( "fr", $have, true ) ) { $add( array( "name"=>"Français", "slug"=>"fr", "locale"=>"fr_CA", "rtl"=>0, "term_group"=>0, "flag"=>"ca" ) ); }
    if ( ! in_array( "en", $have, true ) ) { $add( array( "name"=>"English", "slug"=>"en", "locale"=>"en_US", "rtl"=>0, "term_group"=>1, "flag"=>"us" ) ); }
    $o = get_option("polylang"); $o["default_lang"] = "fr"; $o["hide_default"] = 1; $o["force_lang"] = 1; $o["rewrite"] = 1; $o["browser"] = 0; update_option("polylang", $o);
    $model->clean_languages_cache();
    foreach ( array( "checkout", "cart" ) as $p ) {
        $id = (int) get_option( "woocommerce_" . $p . "_page_id" );
        if ( ! pll_get_post_language( $id ) ) { pll_set_post_language( $id, "fr" ); }
        $en = pll_get_post( $id, "en" );
        if ( ! $en ) {
            $src = get_post( $id );
            $en = wp_insert_post( array( "post_type"=>"page", "post_status"=>"publish", "post_title"=>ucfirst($p)." EN", "post_name"=>$p."-en", "post_content"=>$src->post_content ) );
            pll_set_post_language( $en, "en" );
            pll_save_post_translations( array( "fr"=>$id, "en"=>$en ) );
        }
        echo $p, "=", $id, "/", $en, " ";
    }
    flush_rewrite_rules();
    ''')
    print('   polylang :', out)
    wp('rewrite', 'flush', check=False)
    return {'fr': (B + '/product/tuque-en-laine/', B + '/checkout/'), 'en': (B + '/product/tuque-en-laine/', B + '/en/checkout-en/'),
            'en_mark': lambda u: '/en/' in u, 'en_checkout': lambda u: '/en/checkout-en' in u}

def setup_wpml():
    plugins([]); set_stub('wpml')
    return {'fr': (B + '/product/tuque-en-laine/', B + '/checkout/'), 'en': (B + '/product/tuque-en-laine/?lang=en', B + '/checkout/?lang=en'),
            'en_mark': lambda u: 'lang=en' in u, 'en_checkout': lambda u: '/checkout/' in u and 'lang=en' in u}

def setup_weglot():
    plugins([]); set_stub('weglot')
    # Le MU plugin de test ne route que les pages et l'accueil sous /en/ : ajout au panier sur la fiche produit, paiement sous /en/.
    return {'fr': (B + '/product/tuque-en-laine/', B + '/checkout/'), 'en': (B + '/product/tuque-en-laine/', B + '/en/checkout/'),
            'en_mark': lambda u: '/en/' in u, 'en_checkout': lambda u: '/en/checkout' in u}

def setup_core():
    plugins([]); set_stub('')
    return {'fr': (B + '/product/tuque-en-laine/', B + '/checkout/'), 'en': None,
            'en_mark': lambda u: True, 'en_checkout': lambda u: '/checkout' in u}

def run_mode(mode):
    print(f'\n===== MODE {mode} =====', flush=True)
    cfg = {'core': setup_core, 'translatepress': setup_translatepress, 'polylang': setup_polylang, 'wpml': setup_wpml, 'weglot': setup_weglot}[mode]()
    reset_plugin()
    info = php('echo OLI_ACR_Lang::adapter()->id(), "|", implode(",", OLI_ACR_Lang::languages()), "|", OLI_ACR_Lang::fallback_language(), "|", implode(",", array_keys(get_option("oli_acr_templates")["tpl_cart_1"]["texts"]));')
    parts = info.split('|')
    result(f'[{mode}] Adaptateur détecté, langues actives, modèles par défaut créés dans chaque langue',
           len(parts) == 4 and parts[0] == mode and set(parts[1].split(',')) == {'fr_CA', 'en_US'} and parts[2] == 'fr_CA' and set(parts[3].split(',')) == {'fr_CA', 'en_US'}, info)
    stamp = str(int(time.time()))[-5:]
    e_fr, e_en, e_fb = f'ml-{mode}-fr{stamp}@example.com', f'ml-{mode}-en{stamp}@example.com', f'ml-{mode}-repli{stamp}@example.com'
    vf = visit(cfg['fr'][0], cfg['fr'][1], e_fr)
    if cfg['en']:
        ve = visit(cfg['en'][0], cfg['en'][1], e_en)
    else:
        # Cœur : pas d'URL par langue ; la page transmet sa langue (ex. visiteur en anglais) avec la capture.
        c = Client(); c.req('/?add-to-cart=10')
        _, html, _, _ = c.req('/checkout-classique/')
        m = re.search(r'var oliAcrCapture = (\{.*?\});', html); j = json.loads(m.group(1))
        body = c.req(j['endpoint'].replace('\\/', '/'), {'nonce': j['nonce'], 'email': e_en, 'consent': '1', 'lang': 'en_US'})[1]
        ve = {'label': EN_CONSENT + ' (API)', 'captures': 1, 'langs': ['en_US'], 'ajax': body}
    t0 = time.time()
    rows = {r[0]: r for r in sql(f"SELECT email, language, consent, status FROM wp_oli_acr_carts WHERE email LIKE 'ml-{mode}-%{stamp}@example.com'")}
    result(f'[{mode}] Visiteur FR : case de consentement en français, panier capté en fr_CA avec consentement',
           FR_CONSENT in vf.get('label', '') and rows.get(e_fr, [0, ''])[1:3] == ['fr_CA', '1'], f'{vf} {rows.get(e_fr)}')
    result(f'[{mode}] Visiteur EN : case de consentement en anglais, panier capté en en_US avec consentement',
           EN_CONSENT in ve.get('label', '') and rows.get(e_en, [0, ''])[1:3] == ['en_US', '1'], f'{ve} {rows.get(e_en)}')
    # Repli : panier dont la langue n'est plus active, langue de repli réglée sur en_US.
    c3 = Client(); c3.req('/?add-to-cart=11'); c3.capture_classic(e_fb, consent='1')
    php('global $wpdb; $wpdb->update($wpdb->prefix."oli_acr_carts", array("language"=>"de_DE"), array("email"=>"' + e_fb + '")); $s = get_option("oli_acr_settings"); $s["fallback_language"] = "en_US"; update_option("oli_acr_settings", $s);')
    # Délai d'abandon réel (1 min) puis planificateur normal (WP-Cron -> Action Scheduler).
    E.wait_until(t0 + 62)
    for _ in range(12):
        urllib.request.urlopen(B + '/wp-cron.php', timeout=60).read()
        time.sleep(5)
        if all(mails_to(a) for a in (e_fr, e_en, e_fb)):
            break
    mf, me, mb = mails_to(e_fr), mails_to(e_en), mails_to(e_fb)
    result(f'[{mode}] Relance dans la langue du panier (FR et EN) par le planificateur',
           len(mf) == 1 and FR_SUBJ in mf[0]['Subject'] and len(me) == 1 and EN_SUBJ in me[0]['Subject'],
           f'fr={[m["Subject"] for m in mf]} en={[m["Subject"] for m in me]}')
    result(f'[{mode}] Repli : panier en langue inactive (de_DE) envoyé dans la langue de repli réglée (en_US)',
           len(mb) == 1 and EN_SUBJ in mb[0]['Subject'], f'{[m["Subject"] for m in mb]}')
    if not (mf and me):
        return
    fe, ff = mail_get(me[0]['ID']), mail_get(mf[0]['ID'])
    le, lf = links(fe), links(ff)
    rec_en = [l for l in le if 'oli_acr_recover' in l]; uns_en = [l for l in le if 'oli_acr_unsub' in l]
    rec_fr = [l for l in lf if 'oli_acr_recover' in l]; uns_fr = [l for l in lf if 'oli_acr_unsub' in l]
    body_en = re.sub(r'<[^>]+>', ' ', fe['HTML'])
    result(f'[{mode}] Courriel EN entièrement en anglais (bouton, pied, désabonnement)',
           'Complete my order' in fe['HTML'] and 'Unsubscribe' in fe['HTML'] and 'Terminer ma commande' not in fe['HTML'] and 'Se désabonner' not in fe['HTML'] and 'Bonjour' not in body_en, me[0]['Subject'])
    result(f'[{mode}] Liens EN (récupération et désabonnement) vers l\'URL de la langue ; liens FR sans langue EN',
           rec_en and uns_en and cfg['en_mark'](rec_en[0]) and cfg['en_mark'](uns_en[0]) and 'oli_acr_lang=en_US' in uns_en[0]
           and rec_fr and not ('/en/' in rec_fr[0] or 'lang=en' in rec_fr[0]) and 'oli_acr_lang=fr_CA' in uns_fr[0],
           f'EN {rec_en[:1]} {uns_en[:1]} | FR {rec_fr[:1]}')
    # Lien de récupération EN : redirection vers le paiement de la langue, panier remis.
    cr = Client()
    s1, _, h1, _ = cr.req(rec_en[0], follow=False)
    loc = h1.get('Location') or h1.get('location') or ''
    s2, page2, _, final = cr.req(loc if loc.startswith('http') else B + loc)
    cart, _ = cr.store_cart()
    result(f'[{mode}] Lien de récupération EN : 302 vers le paiement en anglais, panier remis',
           s1 == 302 and cfg['en_checkout'](loc) and s2 == 200 and len(cart.get('items', [])) >= 1, f'{s1} {loc} final={final} articles={len(cart.get("items", []))}')
    # Désabonnement EN : page en anglais, confirmation.
    cu = Client()
    _, pg, _, _ = cu.req(uns_en[0])
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', pg)
    s3, pg3, _, _ = cu.req(uns_en[0], {'_wpnonce': nonce.group(1) if nonce else '', 'oli_acr_confirm': '1'})
    st = sql(f"SELECT status FROM wp_oli_acr_carts WHERE email = '{e_en}'")
    _, pgf, _, _ = Client().req(uns_fr[0])
    result(f'[{mode}] Désabonnement : pages dans la langue du courriel (EN et FR), statut « désabonné »',
           'Stop receiving cart reminder emails' in pg and 'will no longer receive' in pg3 and st and st[0][0] == 'unsubscribed' and 'Ne plus recevoir' in pgf,
           f'en={bool(nonce)} post={s3} statut={st} fr={"Ne plus recevoir" in pgf}')
    if mode in ('wpml', 'polylang'):
        check_strings(mode)

def check_strings(mode):
    # Priorité : texte par langue du plugin > traduction de chaîne (WPML / Polylang) > défaut traduit > repli.
    if mode == 'wpml':
        set_tr = '$o = array("oli-abandoned-cart-recovery" => array("oli_acr_tpl_cart_1_subject" => array("en" => "EN via WPML"), "oli_acr_consent_text" => array("en" => "EN consent via WPML"))); update_option("oli_acr_stub_wpml_strings", $o);'
    else:
        set_tr = '$lang = PLL()->model->get_language("en"); $mo = new PLL_MO(); $mo->import_from_db($lang); $mo->add_entry($mo->make_entry("Sujet perso FR", "EN via Polylang")); $mo->add_entry($mo->make_entry("Consentement perso FR", "EN consent via Polylang")); $mo->export_to_db($lang);'
    out = php('''
    $t = get_option("oli_acr_templates");
    $t["tpl_cart_1"]["texts"]["fr_CA"]["subject"] = "Sujet perso FR"; $t["tpl_cart_1"]["subject"] = "Sujet perso FR";
    unset($t["tpl_cart_1"]["texts"]["en_US"]);
    update_option("oli_acr_templates", $t);
    $s = get_option("oli_acr_settings"); $s["fallback_language"] = ""; $s["consent_texts"] = array("fr_CA" => "Consentement perso FR"); update_option("oli_acr_settings", $s);
    ''' + set_tr + '''
    OLI_ACR_Lang::register_strings();
    $tpl = OLI_ACR_Templates::get("tpl_cart_1");
    $a = OLI_ACR_Templates::for_locale("tpl_cart_1", $tpl, "en_US");
    echo $a["subject"], "|", oli_acr_consent_text("en_US"), "|";
    $t = get_option("oli_acr_templates"); $t["tpl_cart_1"]["texts"]["en_US"] = array("name"=>"","subject"=>"EN own text","heading"=>"","content"=>"","button_label"=>""); update_option("oli_acr_templates", $t);
    $b = OLI_ACR_Templates::for_locale("tpl_cart_1", OLI_ACR_Templates::get("tpl_cart_1"), "en_US");
    echo $b["subject"], "|", $b["button_label"], "|";
    $f = OLI_ACR_Templates::for_locale("tpl_cart_1", OLI_ACR_Templates::get("tpl_cart_1"), "fr_CA"); echo $f["subject"], "|";
    $reg = ''' + ('wp_json_encode(array_keys((array) (get_option("oli_acr_stub_wpml_registered")["oli-abandoned-cart-recovery"] ?? array())));' if mode == 'wpml' else '"polylang";') + ''' echo $reg;
    ''')
    p = out.split('|')
    via = 'EN via WPML' if mode == 'wpml' else 'EN via Polylang'
    result(f'[{mode}] Chaînes : enregistrées, traduction utilisée quand la langue n\'a pas de texte propre, texte propre prioritaire',
           len(p) >= 6 and p[0] == via and p[1].startswith('EN consent via') and p[2] == 'EN own text' and p[3] == 'Complete my order' and p[4] == 'Sujet perso FR'
           and (mode != 'wpml' or 'oli_acr_tpl_cart_1_subject' in p[5]), out[:400])

def run_n1():
    print('\n===== N1 : admin en_US =====', flush=True)
    plugins([]); set_stub('')
    wp('site', 'switch-language', 'fr_CA')
    reset_plugin()  # modèles par défaut créés sur un site fr_CA (cas du rapport QA)
    wp('site', 'switch-language', 'en_US')
    try:
        php('update_user_meta(1, "locale", "");')
        ca = Client(); ca.login('admin', 'admin')
        _, lst, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates')
        _, edt, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates&edit=tpl_cart_1')
        _, edf, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates&edit=tpl_cart_1&lang=fr_CA')
        php('global $wpdb; $wpdb->insert($wpdb->prefix."oli_acr_log", array("object_type"=>"cart","object_id"=>1,"template_id"=>"tpl_cart_1","email"=>"n1@example.com","sent_at"=>gmdate("Y-m-d H:i:s")));')
        _, lg, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=log')
        _, db, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=dashboard')
        fr_words = ['Relance de panier', 'Vous avez oublié', 'Relance de commande']
        subj = re.search(r'name="t\[texts\]\[en_US\]\[subject\]" value="([^"]*)"', edt)
        subj_fr = re.search(r'name="t\[texts\]\[fr_CA\]\[subject\]" value="([^"]*)"', edf)
        result('(N1) Admin en_US : liste, éditeur, journal et tableau de bord en anglais (modèles créés sur un site fr_CA)',
               'Cart reminder #1' in lst and 'Pending order reminder' in lst and not any(w in lst for w in fr_words)
               and subj and subj.group(1) == 'You left something in your cart' and 'Cart reminder #1' in lg and not any(w in lg for w in fr_words)
               and 'Cart reminder #1' in db, f'sujet_editeur={subj.group(1) if subj else None}')
        result('(N1) Onglet de langue fr_CA : textes du modèle en français', subj_fr and 'oublié' in subj_fr.group(1) and 'nav-tab-active' in edf, subj_fr.group(1) if subj_fr else None)
        # « Send a test » : langue de l'admin par défaut, puis onglet fr_CA.
        def send_test(tpl, lang):
            _, page, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates&edit=' + tpl + '&lang=' + lang)
            n = re.search(r'name="_wpnonce" value="([^"]+)"[^>]*>\s*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<input type="hidden" name="action" value="oli_acr_test_email"', page)
            n = n or re.search(r'id="_wpnonce" name="_wpnonce" value="([^"]+)" /><input type="hidden" name="_wp_http_referer" value="[^"]*" /><input type="hidden" name="action" value="oli_acr_test_email"', page)
            ca.req('/wp-admin/admin-post.php', {'action': 'oli_acr_test_email', '_wpnonce': n.group(1) if n else '', 'template': tpl, 'to': f'n1-{tpl}-{lang}@example.com', 'lang': lang})
            ms = mails_to(f'n1-{tpl}-{lang}@example.com')
            return mail_get(ms[0]['ID']) if ms else {'Subject': '', 'HTML': ''}
        t_en = send_test('tpl_cart_1', 'en_US'); t_fr = send_test('tpl_cart_1', 'fr_CA')
        result('(N1) « Send a test » dans la langue de l\'onglet (en_US puis fr_CA)',
               t_en['Subject'].startswith('[Test] You left something') and t_fr['Subject'].startswith('[Test] Vous avez oublié'), f'{t_en["Subject"]!r} / {t_fr["Subject"]!r}')
        c_en = send_test('tpl_cart_2', 'en_US'); c_fr = send_test('tpl_cart_2', 'fr_CA')
        php('$t = get_option("oli_acr_templates"); $t["tpl_cart_2"]["coupon_enabled"] = "no"; update_option("oli_acr_templates", $t);')
        c_no = send_test('tpl_cart_2', 'en_US') if False else None
        ca.req('/wp-admin/admin-post.php')  # rien
        nocoupon = php('$t = OLI_ACR_Templates::get("tpl_cart_2"); OLI_ACR_Mailer::send_test("n1-nocoupon@example.com", $t, "en_US");') or ''
        mn = mails_to('n1-nocoupon@example.com'); hn = mail_get(mn[0]['ID'])['HTML'] if mn else ''
        result('(Coupon) Phrase d\'introduction traduite avant le code (EN et FR), retirée sans coupon',
               'Use this code to get 10% off your order:' in c_en['HTML'] and 'Utilisez ce code pour obtenir 10 % de rabais sur votre commande :' in c_fr['HTML'].replace('&nbsp;', ' ')
               and c_en['HTML'].find('Use this code') < c_en['HTML'].find('TEST-COUPON') and mn and 'Use this code' not in hn and '{coupon_amount}' not in hn,
               f'en={"Use this code to get 10% off" in c_en["HTML"]} fr={"Utilisez ce code" in c_fr["HTML"]} sans_coupon={("Use this code" not in hn) if mn else None}')
    finally:
        wp('site', 'switch-language', 'fr_CA')

def main():
    modes = sys.argv[1:] or ['n1', 'core', 'translatepress', 'polylang', 'wpml', 'weglot']
    try:
        for m in modes:
            run_n1() if m == 'n1' else run_mode(m)
    finally:
        plugins([]); set_stub('')
        php('delete_option("oli_acr_test_lang_stub"); delete_option("oli_acr_stub_wpml_strings"); delete_option("oli_acr_stub_wpml_registered");')
    print('\n==== RÉSUMÉ MULTILINGUE ====')
    for n, ok, d in RESULTS:
        print(('PASS' if ok else 'FAIL'), n)
    print(f'{sum(1 for r in RESULTS if r[1])}/{len(RESULTS)}')
    json.dump([{'test': n, 'ok': ok, 'detail': d} for n, ok, d in RESULTS], open(os.path.join(HERE, 'e2e-results-multilang-' + '-'.join(modes) + '.json'), 'w'), ensure_ascii=False, indent=1)
    sys.exit(0 if all(ok for _, ok, _ in RESULTS) else 1)

if __name__ == '__main__':
    main()
