#!/usr/bin/env python3
"""Tests de bout en bout sur la box locale (http://localhost:8888 + Mailpit 127.0.0.1:8025).
Jamais contre un staging ou un Live."""
import json, os, re, subprocess, sys, time, urllib.parse, urllib.request, http.cookiejar

B = 'http://localhost:8888'
MP = 'http://127.0.0.1:8025'
WP = '/workspace/wp'
HERE = os.path.dirname(os.path.abspath(__file__))
RESULTS = []
LOCALE = subprocess.run(['wp', 'option', 'get', 'WPLANG'], cwd=WP, capture_output=True, text=True).stdout.strip() or 'en_US'
FR = LOCALE.startswith('fr')
S_REM1 = 'oublié quelque chose' if FR else 'something in your cart'
S_REM2 = 'petit quelque chose' if FR else 'little something'
S_ADMIN = 'Vente récupérée' if FR else 'Recovered sale'
def order_ref(oid):
    return f'nº {oid}' if FR else f'#{oid}'

def wp(*args, check=True):
    r = subprocess.run(['wp', *args], cwd=WP, capture_output=True, text=True)
    out = '\n'.join(l for l in r.stdout.splitlines() if 'Notice' not in l and 'Deprecated' not in l)
    if check and r.returncode != 0:
        print('WP-CLI error:', args, r.stderr[-500:])
    return out.strip()

def php(code):
    return wp('eval', code)

def sql(q):
    out = wp('db', 'query', q, '--skip-column-names', '--batch')
    return [l.split('\t') for l in out.splitlines() if l]

def result(name, ok, detail=''):
    RESULTS.append((name, ok, detail))
    print(('PASS' if ok else 'FAIL'), '-', name, '-', detail, flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None

class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.nr = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, url, data=None, headers=None, follow=True, method=None):
        if url.startswith('/'):
            url = B + url
        body = None
        if isinstance(data, dict):
            body = urllib.parse.urlencode(data, doseq=True).encode()
        elif isinstance(data, (bytes, str)):
            body = data.encode() if isinstance(data, str) else data
        r = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
        try:
            resp = (self.op if follow else self.nr).open(r, timeout=60)
            return resp.status, resp.read().decode('utf-8', 'replace'), dict(resp.headers), resp.geturl()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), dict(e.headers), url
    def login(self, user, pwd):
        self.req('/wp-login.php')
        return self.req('/wp-login.php', {'log': user, 'pwd': pwd, 'wp-submit': 'Log In', 'testcookie': '1', 'redirect_to': B + '/wp-admin/'})
    def store_cart(self):
        s, body, h, _ = self.req('/wp-json/wc/store/v1/cart')
        return json.loads(body), h.get('Nonce') or h.get('nonce')
    def checkout(self, email, method='cod', first='Test', last='Client'):
        _, nonce = self.store_cart()
        payload = {'billing_address': {'first_name': first, 'last_name': last, 'address_1': '1 rue Test', 'city': 'Montreal', 'state': 'QC', 'postcode': 'H2X 1Y4', 'country': 'CA', 'email': email, 'phone': '5145550000'}, 'payment_method': method}
        s, body, _, _ = self.req('/wp-json/wc/store/v1/checkout', json.dumps(payload), {'Nonce': nonce, 'Content-Type': 'application/json'})
        return json.loads(body)
    def capture_classic(self, email, phone='', consent=None):
        _, html, _, _ = self.req('/checkout-classique/')
        m = re.search(r'var oliAcrCapture = (\{.*?\});', html)
        cfg = json.loads(m.group(1))
        data = {'nonce': cfg['nonce'], 'email': email, 'phone': phone}
        if consent is not None:
            data['consent'] = consent
        s, body, _, _ = self.req(cfg['endpoint'].replace('\\/', '/'), data)
        return body

def mail_list():
    with urllib.request.urlopen(MP + '/api/v1/messages?limit=200') as r:
        return json.loads(r.read())['messages']

def mail_get(mid):
    with urllib.request.urlopen(MP + '/api/v1/message/' + mid) as r:
        return json.loads(r.read())

def mails_to(addr, subject_contains=''):
    return [m for m in mail_list() if any(t['Address'] == addr for t in m['To']) and subject_contains in m['Subject']]

def links(msg):
    return [l.replace('&amp;', '&') for l in re.findall(r'href="([^"]+)"', msg['HTML'])]

def process():
    php('do_action("oli_acr_process");')

def wait_until(ts):
    d = ts - time.time()
    if d > 0:
        print(f'   … attente {int(d)} s (délai réel)', flush=True)
        time.sleep(d)

# ---------------------------------------------------------------- setup --
def setup():
    urllib.request.urlopen(urllib.request.Request(MP + '/api/v1/messages', method='DELETE'))
    php('''
    global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}oli_acr_carts"); $wpdb->query("TRUNCATE {$wpdb->prefix}oli_acr_log");
    update_option("oli_acr_blocklist", array());
    $s = oli_acr_default_settings();
    $s["abandon_after"] = array("value"=>1,"unit"=>"minutes");
    $s["cron_interval"] = array("value"=>1,"unit"=>"minutes");
    $s["pending_enabled"] = "yes";
    $s["pending_after"] = array("value"=>1,"unit"=>"minutes");
    update_option("oli_acr_settings", $s);
    $t = OLI_ACR_Templates::default_templates();
    $t["tpl_cart_1"]["delay"] = array("value"=>0,"unit"=>"minutes");
    $t["tpl_cart_2"]["active"] = "yes"; $t["tpl_cart_2"]["delay"] = array("value"=>1,"unit"=>"minutes");
    $t["tpl_order_1"]["active"] = "yes";
    update_option("oli_acr_templates", $t);
    foreach ( get_posts(array("post_type"=>"shop_coupon","post_status"=>"any","numberposts"=>-1,"fields"=>"ids")) as $id ) { wp_delete_post($id, true); }
    OLI_ACR_Install::sync_shop_manager_cap();
    ''')

def main():
    setup()
    stamp = str(int(time.time()))[-5:]
    E_CLASSIC = f'classique{stamp}@example.com'
    E_BLOCKS = f'blocs{stamp}@example.com'
    E_UNSUB = f'desabo{stamp}@example.com'

    # (a) et (b) : navigateur sans tête, courriel + téléphone seulement, non connecté.
    env = dict(os.environ, EMAIL_C=E_CLASSIC, EMAIL_B=E_BLOCKS, PHONE='4385550199', NODE_PATH='/usr/local/lib/pnpm/5/.pnpm/playwright-core@1.59.1/node_modules')
    out = subprocess.run(['node', os.path.join(HERE, 'browser-capture.js'), 'both'], env=env, capture_output=True, text=True)
    print(out.stdout.strip(), out.stderr.strip()[-300:])
    t_capture = time.time()
    rows = {r[0]: r for r in sql("SELECT email, status, phone, item_count, cart_total, currency, user_id FROM wp_oli_acr_carts")}
    ra = rows.get(E_CLASSIC); rb = rows.get(E_BLOCKS)
    result('(a) Checkout classique : courriel capturé (visiteur non connecté, sans commande)', bool(ra and ra[1] == 'open' and ra[6] == '0' and int(ra[3]) > 0), str(ra))
    result('(b) Checkout en blocs : courriel capturé', bool(rb and rb[1] == 'open' and int(rb[3]) > 0), str(rb))
    result('(+) Téléphone capturé (classique et blocs)', bool(ra and rb and ra[2] == '4385550199' and rb[2] == '4385550199'), f'{ra and ra[2]} / {rb and rb[2]}')

    # Troisième visiteur (futur désabonné) via le flux AJAX.
    c_unsub = Client(); c_unsub.req('/?add-to-cart=11')
    body = c_unsub.capture_classic(E_UNSUB, '514 555 0123')
    result('(+) Flux AJAX (curl) : capture acceptée', '"success":true' in body, body)

    # (c) Délai d'abandon réel (1 min), puis exécution de l'action.
    wait_until(t_capture + 62)
    process()
    ok = True; det = []
    for addr in (E_CLASSIC, E_BLOCKS, E_UNSUB):
        ms = mails_to(addr)
        det.append(f'{addr}:{len(ms)}')
        ok = ok and len(ms) == 1
    m_classic = mail_get(mails_to(E_CLASSIC)[0]['ID']) if mails_to(E_CLASSIC) else None
    rec = [l for l in links(m_classic) if 'oli_acr_recover' in l] if m_classic else []
    uns = [l for l in links(m_classic) if 'oli_acr_unsub' in l] if m_classic else []
    status = dict(sql("SELECT email, status FROM wp_oli_acr_carts"))
    result('(c) Statut « relancé » après envoi', all(status.get(a) == 'reminded' for a in (E_CLASSIC, E_BLOCKS, E_UNSUB)), str(status))
    s2 = Client().req(uns[0])[0] if uns else 0
    s1 = Client().req(rec[0], follow=False)[0] if rec else 0
    result('(c) Relance #1 reçue dans Mailpit après le délai, liens fonctionnels', ok and s1 == 302 and s2 == 200, ' '.join(det) + f' recover={s1} unsub={s2}')

    # (d) Le lien de récupération remet le panier (navigateur vierge).
    c_rec = Client()
    s, _, h, final = c_rec.req(rec[0])
    cart, _ = c_rec.store_cart()
    items = [(i['name'], i['quantity']) for i in cart['items']]
    result('(d) Lien de récupération : panier + courriel remis, redirection au checkout', final.rstrip('/').endswith('/checkout') and len(items) == 2 and cart['billing_address']['email'] == E_CLASSIC, f'{final} {items} {cart["billing_address"]["email"]}')

    # (e) Désabonnement.
    m_u = mail_get(mails_to(E_UNSUB)[0]['ID'])
    unsub_link = [l for l in links(m_u) if 'oli_acr_unsub' in l][0]
    cu = Client()
    _, page, _, _ = cu.req(unsub_link)
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', page).group(1)
    s, page2, _, _ = cu.req(unsub_link, {'_wpnonce': nonce, 'oli_acr_confirm': '1'})
    st = dict(sql("SELECT email, status FROM wp_oli_acr_carts"))
    blocked = E_UNSUB in php('echo implode(",", oli_acr_get_blocklist());')
    again = Client(); again.req('/?add-to-cart=12'); body_again = again.capture_classic(E_UNSUB)
    # (f) Commande passée par le client récupéré (d) : statut « récupéré » et fin des relances.
    order = c_rec.checkout(E_CLASSIC, 'cod', 'Marie', 'Tremblay')
    oid = order.get('order_id')
    frow = sql(f"SELECT status, order_id, recovered_via, next_send_at FROM wp_oli_acr_carts WHERE email = '{E_CLASSIC}'")
    # Attente du délai de la relance #2 (1 min après l'abandon) puis nouveau passage.
    wait_until(t_capture + 62 + 65)
    process()
    n_unsub = len(mails_to(E_UNSUB)); n_classic = len(mails_to(E_CLASSIC, S_REM1)) + len(mails_to(E_CLASSIC, S_REM2))
    result('(e) Désabonnement : liste d\'exclusion + statut « désabonné » + aucun envoi ensuite', s == 200 and blocked and st.get(E_UNSUB) == 'unsubscribed' and n_unsub == 1 and 'unsubscribed' in body_again, f'status={st.get(E_UNSUB)} emails={n_unsub} recapture={body_again}')
    result('(f) Commande passée → panier « récupéré », relié à la commande, relance #2 annulée', bool(frow) and frow[0][0] == 'recovered' and frow[0][1] == str(oid) and frow[0][3] == 'NULL' and n_classic == 1, f'order={oid} row={frow} relances_recues={n_classic}')
    adm = [m for m in mail_list() if S_ADMIN in m['Subject'] and order_ref(oid) in m['Subject']]
    result('(+) Avis à l\'admin quand un panier est récupéré', len(adm) == 1, adm[0]['Subject'] if adm else 'aucun')

    # Séquence : la relance #2 (avec coupon) arrive au panier en blocs.
    seq = sorted(m['Subject'] for m in mails_to(E_BLOCKS))
    log = sql(f"SELECT template_id, coupon_code FROM wp_oli_acr_log WHERE email = '{E_BLOCKS}' ORDER BY id")
    coupon = log[-1][1] if log else ''
    cexists = php(f'echo wc_get_coupon_id_by_code("{coupon}");') if coupon else ''
    result('(+) Plusieurs modèles en séquence (#1 puis #2 avec coupon unique)', len(seq) == 2 and [l[0] for l in log] == ['tpl_cart_1', 'tpl_cart_2'] and coupon.startswith('OLI-') and cexists not in ('', '0'), f'{seq} coupon={coupon} id={cexists}')

    # Coupon : utilisé via le lien de la relance #2 (appliqué automatiquement), puis nettoyé.
    m2 = mail_get([m for m in mails_to(E_BLOCKS) if S_REM2 in m['Subject']][0]['ID'])
    rec2 = [l for l in links(m2) if 'oli_acr_recover' in l][0]
    cb = Client(); cb.req(rec2)
    cartb, _ = cb.store_cart()
    applied = [c['code'] for c in cartb.get('coupons', [])]
    o2 = cb.checkout(E_BLOCKS, 'cod', 'Luc', 'Gagnon')
    php('do_action("oli_acr_daily_cleanup");')
    cstatus = php(f'echo get_post_status({cexists});')
    result('(+) Coupon appliqué au retour, utilisé dans la commande puis nettoyé (corbeille)', coupon.lower() in [a.lower() for a in applied] and cstatus == 'trash', f'appliqué={applied} commande={o2.get("order_id")} statut_coupon={cstatus}')
    rl = sql(f"SELECT recovered_at IS NOT NULL, order_id FROM wp_oli_acr_log WHERE email = '{E_BLOCKS}' AND template_id = 'tpl_cart_2'")
    result('(+) Journal : clic et récupération attribués à la relance #2', bool(rl) and rl[0][0] == '1' and rl[0][1] == str(o2.get('order_id')), str(rl))

    # Coupon expiré nettoyé.
    exp = php('$c = new WC_Coupon(); $c->set_code("OLI-EXPIRE' + stamp + '"); $c->set_amount(5); $c->set_date_expires( time() - DAY_IN_SECONDS ); $c->update_meta_data("_oli_acr_coupon","yes"); echo $c->save();')
    php('do_action("oli_acr_daily_cleanup");')
    result('(+) Coupon expiré nettoyé', php(f'echo get_post_status({exp});') == 'trash', exp)

    # Commande en attente (pending) : relance, clic, paiement → récupérée + avis admin.
    E_PEND = f'attente{stamp}@example.com'
    pid = php(f'''$o = wc_create_order(); $o->add_product( wc_get_product(10), 1 ); $o->set_billing_email("{E_PEND}"); $o->set_billing_first_name("Paul"); $o->set_created_via("checkout"); $o->calculate_totals(); $o->set_status("pending"); $o->save(); echo $o->get_id();''')
    php(f'$o = wc_get_order({pid}); $o->set_date_created( time() - 120 ); $o->save();')
    process()
    pm = mails_to(E_PEND)
    pay = [l for l in links(mail_get(pm[0]['ID'])) if 'oli_acr_pay' in l] if pm else []
    s_pay, _, h_pay, _ = Client().req(pay[0], follow=False) if pay else (0, '', {}, '')
    php(f'$o = wc_get_order({pid}); $o->set_payment_method("cod"); $o->update_status("processing");')
    prec = php(f'echo wc_get_order({pid})->get_meta("_oli_acr_recovered");')
    padm = [m for m in mail_list() if S_ADMIN in m['Subject'] and order_ref(pid) in m['Subject']]
    result('(+) Commande en attente : relance envoyée, lien de paiement, récupérée au paiement + avis admin', len(pm) == 1 and s_pay == 302 and 'order-pay' in h_pay.get('Location', '') and prec == 'order' and len(padm) == 1, f'order={pid} mails={len(pm)} pay={s_pay} {h_pay.get("Location","")[:60]} meta={prec} admin={len(padm)}')

    # Commande en attente trop vieille annulée automatiquement.
    pid2 = php(f'''$o = wc_create_order(); $o->add_product( wc_get_product(11), 1 ); $o->set_billing_email("vieille{stamp}@example.com"); $o->set_created_via("checkout"); $o->calculate_totals(); $o->set_status("pending"); $o->save(); $o->set_date_created( time() - 3 * DAY_IN_SECONDS ); $o->update_meta_data("_oli_acr_sent", array("tpl_order_1")); $o->save(); echo $o->get_id();''')
    php('$s = get_option("oli_acr_settings"); $s["pending_cancel_after"] = array("value"=>2,"unit"=>"days"); update_option("oli_acr_settings", $s); do_action("oli_acr_daily_cleanup");')
    result('(+) Nettoyage : commande en attente relancée trop vieille annulée', php(f'echo wc_get_order({pid2})->get_status();') == 'cancelled', pid2)

    # Nettoyage automatique des vieux paniers.
    php('global $wpdb; $wpdb->insert($wpdb->prefix."oli_acr_carts", array("token"=>wp_generate_password(32,false,false),"email"=>"vieux@example.com","status"=>"abandoned","item_count"=>1,"created_at"=>gmdate("Y-m-d H:i:s", time()-40*DAY_IN_SECONDS),"updated_at"=>gmdate("Y-m-d H:i:s", time()-40*DAY_IN_SECONDS)));')
    before = sql("SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='vieux@example.com'")[0][0]
    php('do_action("oli_acr_daily_cleanup");')
    after = sql("SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='vieux@example.com'")[0][0]
    result('(+) Nettoyage automatique des paniers de plus de 30 jours', before == '1' and after == '0', f'{before} → {after}')

    # Mode consentement.
    php('$s = get_option("oli_acr_settings"); $s["guest_tracking"] = "consent"; update_option("oli_acr_settings", $s);')
    E_CONS = f'consent{stamp}@example.com'
    cc = Client(); cc.req('/?add-to-cart=10')
    b_no = cc.capture_classic(E_CONS, consent='0')
    n_no = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='{E_CONS}'")[0][0]
    b_yes = cc.capture_classic(E_CONS, consent='1')
    n_yes = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='{E_CONS}'")[0][0]
    _, html_c, _, _ = cc.req('/checkout-classique/')
    result('(+) Consentement : case affichée, rien sans consentement, capture avec consentement', 'oli_acr_consent' in html_c and n_no == '0' and n_yes == '1', f'sans={b_no} avec={b_yes}')
    php('$s = get_option("oli_acr_settings"); $s["guest_tracking"] = "always"; update_option("oli_acr_settings", $s);')

    # Client connecté (capture au changement de panier) et rôles suivis.
    php('if ( ! get_user_by("login","cliente") ) { $u = wp_create_user("cliente","cliente","cliente@example.com"); wp_update_user(array("ID"=>$u,"role"=>"customer","first_name"=>"Julie")); }')
    cl = Client(); cl.login('cliente', 'cliente'); cl.req('/?add-to-cart=12')
    lrow = sql("SELECT status, user_id > 0, first_name FROM wp_oli_acr_carts WHERE email='cliente@example.com'")
    result('(+) Client connecté : panier capturé à l\'ajout au panier', bool(lrow) and lrow[0][1] == '1', str(lrow))
    php('global $wpdb; $wpdb->delete($wpdb->prefix."oli_acr_carts", array("email"=>"cliente@example.com")); $s = get_option("oli_acr_settings"); $s["roles_mode"]="selected"; $s["roles"]=array("administrator"); update_option("oli_acr_settings", $s);')
    cl2 = Client(); cl2.login('cliente', 'cliente'); cl2.req('/?add-to-cart=11')
    lrow2 = sql("SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='cliente@example.com'")[0][0]
    result('(+) Rôles suivis : un rôle exclu n\'est pas capturé', lrow2 == '0', lrow2)
    php('$s = get_option("oli_acr_settings"); $s["roles_mode"]="all"; $s["roles"]=array(); update_option("oli_acr_settings", $s);')

    # (g) Bouton « Envoyer un test » (admin connecté, formulaire réel avec nonce).
    ca = Client(); ca.login('admin', 'admin')
    s, page, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=templates')
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', page)
    s, _, h, final = ca.req('/wp-admin/admin-post.php', {'action': 'oli_acr_test_email', '_wpnonce': nonce.group(1) if nonce else '', 'template': 'tpl_cart_2', 'to': 'qa-test@example.com'})
    tm = mails_to('qa-test@example.com', '[Test]')
    result('(g) Bouton « Envoyer un test » : courriel reçu à l\'adresse choisie', s == 200 and 'test_sent' in final and len(tm) == 1, f'{final[-40:]} {tm[0]["Subject"] if tm else ""}')

    # Accès shop_manager avec capacité dédiée.
    php('if ( ! get_user_by("login","gerant") ) { $u = wp_create_user("gerant","gerant","gerant@example.com"); wp_update_user(array("ID"=>$u,"role"=>"shop_manager")); }')
    php('$s = get_option("oli_acr_settings"); $s["shop_manager_access"]="no"; update_option("oli_acr_settings", $s); OLI_ACR_Install::sync_shop_manager_cap();')
    cg = Client(); cg.login('gerant', 'gerant')
    s_no, p_no, _, _ = cg.req('/wp-admin/admin.php?page=oli-acr')
    php('$s = get_option("oli_acr_settings"); $s["shop_manager_access"]="yes"; update_option("oli_acr_settings", $s); OLI_ACR_Install::sync_shop_manager_cap();')
    s_yes, p_yes, _, _ = cg.req('/wp-admin/admin.php?page=oli-acr')
    result('(+) Accès shop_manager : refusé par défaut, permis avec l\'option (capacité oli_acr_manage)', s_no == 403 and s_yes == 200 and 'oli-acr-cards' in p_yes, f'sans={s_no} avec={s_yes}')

    # Export et effacement des données personnelles (API vie privée de WordPress).
    exp_json = php(f'$e = apply_filters("wp_privacy_personal_data_exporters", array()); $r = call_user_func($e["oli-abandoned-cart-recovery"]["callback"], "{E_BLOCKS}", 1); echo wp_json_encode($r);')
    ex = json.loads(exp_json)
    era_json = php(f'$e = apply_filters("wp_privacy_personal_data_erasers", array()); $r = call_user_func($e["oli-abandoned-cart-recovery"]["callback"], "{E_BLOCKS}", 1); echo wp_json_encode($r);')
    er = json.loads(era_json)
    left = sql(f"SELECT (SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='{E_BLOCKS}') + (SELECT COUNT(*) FROM wp_oli_acr_log WHERE email='{E_BLOCKS}')")[0][0]
    result('(+) Export des données personnelles', len(ex['data']) >= 3, f'{len(ex["data"])} éléments')
    result('(+) Effacement des données personnelles', er['items_removed'] and left == '0', f'{er} restant={left}')
    s_pol, p_pol, _, _ = ca.req('/wp-admin/options-privacy.php?tab=policyguide')
    if 'Oli Abandoned Cart Recovery' not in p_pol:
        s_pol, p_pol, _, _ = ca.req('/wp-admin/privacy-policy-guide.php')
    pol = '1' if 'Oli Abandoned Cart Recovery' in p_pol else '0'
    result('(+) Texte suggéré pour la politique de confidentialité', pol.strip().endswith('1'), pol)

    # Rapports.
    st = json.loads(php('require_once OLI_ACR_DIR . "includes/admin/class-oli-acr-admin.php"; echo wp_json_encode( OLI_ACR_Admin::stats(30) );'))
    result('(+) Rapports : envoyés, clics, récupérés et montant', st['sent'] >= 3 and st['clicked'] >= 2 and st['recovered_carts'] >= 1 and st['recovered_orders'] >= 1 and st['amount'] > 0, json.dumps(st))

    print('\n==== RÉSUMÉ ====')
    for n, ok, d in RESULTS:
        print(('PASS' if ok else 'FAIL'), n)
    json.dump([{'test': n, 'ok': ok, 'detail': d} for n, ok, d in RESULTS], open(os.path.join(HERE, f'e2e-results-{LOCALE}.json'), 'w'), ensure_ascii=False, indent=1)
    sys.exit(0 if all(ok for _, ok, _ in RESULTS) else 1)

if __name__ == '__main__':
    main()
