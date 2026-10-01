#!/usr/bin/env python3
"""Tests des correctifs QA de la 1.1.0 (R1 à R10, N1, N2, consentement) sur un WordPress LOCAL de test seulement.

Mêmes variables d'environnement que run-e2e.py. Le MU plugin de test tests/stubs/oli-acr-lang-stubs.php doit
être copié dans wp-content/mu-plugins (port SMTP forcé, envoi lent, journal des requêtes, limites relâchées).
Le setup VIDE la boîte Mailpit indiquée et réinitialise les données du plugin.
Usage : run-e2e-r110.py [r1 r2 r3 r4 r5 r6 r7 r8 r9 r10 consent ...]"""
import html as htmllib, importlib.util, json, os, re, subprocess, sys, time, urllib.request, urllib.parse, glob, traceback
HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
spec = importlib.util.spec_from_file_location('e2e', os.path.join(HERE, 'run-e2e.py'))
E = importlib.util.module_from_spec(spec); spec.loader.exec_module(E)
B, MP, WP = E.B, E.MP, E.WP
php, sql, wp, Client, mails_to, mail_get = E.php, E.sql, E.wp, E.Client, E.mails_to, E.mail_get
NODE_PATH = '/usr/local/lib/pnpm/5/.pnpm/playwright-core@1.59.1/node_modules'
RESULTS = []
STAMP = str(int(time.time()))

def result(name, ok, detail=''):
    RESULTS.append((name, bool(ok), detail))
    print(('PASS' if ok else 'FAIL'), '-', name, '-', str(detail)[:700], flush=True)

def php_nodebug(code):
    """wp eval avec WP_DEBUG désactivé (production)."""
    r = subprocess.run(['wp', '--exec=define("WP_DEBUG", false);', 'eval', code], cwd=WP, capture_output=True, text=True)
    return r.stdout.strip()

def opt(name, value):
    if value is None:
        php(f'delete_option("{name}");')
    else:
        php(f'update_option("{name}", {json.dumps(value)});')

def visit(**env):
    e = dict(os.environ, NODE_PATH=NODE_PATH, BASE=B, PRODUCT_URL=B + '/product/tuque-en-laine/', **{k: str(v) for k, v in env.items()})
    out = subprocess.run(['node', os.path.join(HERE, 'r110-visit.js')], env=e, capture_output=True, text=True, timeout=150)
    try:
        return json.loads(out.stdout.strip().splitlines()[-1])
    except Exception:
        return {'error': out.stdout[-300:] + out.stderr[-300:]}

def mp_clear():
    urllib.request.urlopen(urllib.request.Request(MP + '/api/v1/messages', method='DELETE'))

def mp_raw(mid):
    with urllib.request.urlopen(MP + '/api/v1/message/' + mid + '/raw') as r:
        return r.read().decode('utf-8', 'replace')

def reset(mode='consent'):
    mp_clear()
    for o in ('oli_acr_test_smtp_port', 'oli_acr_test_mail_sleep', 'oli_acr_test_rate_limits', 'oli_acr_test_query_log', 'oli_acr_mail_failure', 'oli_acr_notice_consent_migrated', 'oli_acr_process_lock'):
        opt(o, None)
    php('''
    global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}oli_acr_carts"); $wpdb->query("TRUNCATE {$wpdb->prefix}oli_acr_log");
    // TRUNCATE remet les ID à 1 : les sessions WooCommerce persistantes (client connecté) gardent sinon un ancien
    // ID de panier qui pointerait vers le panier d'un autre visiteur du test suivant.
    $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_sessions");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%oli_acr_rl_%'");
    update_option("oli_acr_blocklist", array());
    $s = oli_acr_default_settings();
    $s["guest_tracking"] = "''' + mode + '''";
    $s["abandon_after"] = array("value"=>1,"unit"=>"minutes");
    $s["cron_interval"] = array("value"=>1,"unit"=>"minutes");
    update_option("oli_acr_settings", $s, true);
    $t = OLI_ACR_Templates::default_templates();
    $t["tpl_cart_1"]["delay"] = array("value"=>0,"unit"=>"minutes");
    $t["tpl_order_1"]["active"] = "yes";
    update_option("oli_acr_templates", $t);
    delete_user_meta(2, "_oli_acr_consent"); delete_user_meta(2, "_wc_other/oli-acr/consent");
    foreach ( get_posts(array("post_type"=>"shop_coupon","post_status"=>"any","numberposts"=>-1,"fields"=>"ids")) as $id ) { wp_delete_post($id, true); }
    ''')

def due_cart(email, user_id=0, consent=1):
    """Panier abandonné, dû maintenant (produit 10)."""
    return php('''
    global $wpdb; $now = gmdate("Y-m-d H:i:s", time() - 120);
    $wpdb->insert($wpdb->prefix."oli_acr_carts", array("token"=>wp_generate_password(32,false,false),"session_key"=>"t-".wp_generate_password(8,false,false),"user_id"=>''' + str(user_id) + ''',
      "email"=>"''' + email + '''","first_name"=>"Zoé","cart_contents"=>wp_json_encode(array(array("product_id"=>10,"variation_id"=>0,"quantity"=>1,"name"=>"Tuque","line_total"=>25))),
      "item_count"=>1,"cart_total"=>25,"currency"=>"CAD","language"=>"fr_CA","status"=>"abandoned","consent"=>''' + str(consent) + ''',"abandoned_at"=>$now,"next_send_at"=>$now,"created_at"=>$now,"updated_at"=>$now));
    echo $wpdb->insert_id;''')

def cart(cid):
    r = sql(f'SELECT status, emails_sent, fail_count, IFNULL(last_error,""), IFNULL(next_send_at,"NULL"), TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), next_send_at) FROM wp_oli_acr_carts WHERE id={cid}')
    return r[0] if r else None

def wc_log_tail():
    files = sorted(glob.glob(os.path.join(WP, 'wp-content/uploads/wc-logs/oli-abandoned-cart-recovery-*.log')), key=os.path.getmtime)
    return open(files[-1], encoding='utf-8', errors='replace').read()[-3000:] if files else ''

def admin_notices_html():
    ca = Client(); ca.login('admin', 'admin')
    return ca.req('/wp-admin/index.php')[1]

# ------------------------------------------------------------------ R1 --
def t_r1():
    reset()
    email = f'r1-{STAMP}@example.test'
    cid = due_cart(email)
    for f in glob.glob(os.path.join(WP, 'wp-content/uploads/wc-logs/oli-abandoned-cart-recovery-*.log')):
        os.remove(f)
    opt('oli_acr_test_smtp_port', 1099)
    php_nodebug('do_action("oli_acr_process");')
    c1 = cart(cid)
    log = wc_log_tail()
    coupons = php('echo count(get_posts(array("post_type"=>"shop_coupon","post_status"=>"any","numberposts"=>-1)));')
    logrows = sql(f'SELECT COUNT(*) FROM wp_oli_acr_log WHERE object_id={cid}')[0][0]
    notice = admin_notices_html()
    ca0 = Client(); ca0.login('admin', 'admin')
    table = ca0.req('/wp-admin/admin.php?page=oli-acr&tab=carts&status=failed')[1]
    ok1 = c1 and c1[0] == 'failed' and c1[2] == '1' and c1[3] != '' and 240 <= int(c1[5] or 0) <= 330
    result('(R1) Échec SMTP : statut « failed », cause enregistrée, nouvel essai dans 5 min (et non NULL)', ok1, f'carts={c1}')
    result('(R1) Échec journalisé au niveau ERROR dans les journaux WooCommerce, sans WP_DEBUG, avec la cause',
           'ERROR' in log and (f'#{cid} ' in log or f'nº {cid} ' in log or f'n° {cid} ' in log) and ('Connection refused' in log or 'connect' in log.lower()), log[-400:])
    result('(R1) Échec visible dans l\'admin : avis général + statut et cause dans la liste des paniers (filtre « failed »)',
           'oli-acr-mail-failure' in notice and email in table and 'oli-acr-status-failed' in table and (c1[3][:20] in table if c1 else False), f'notice={"oli-acr-mail-failure" in notice} table={email in table}')
    result('(R1) Aucun coupon orphelin ni ligne de journal laissés par l\'envoi raté', logrows == '0' and coupons == '0', f'log={logrows} coupons={coupons}')
    # Le SMTP revient, le délai est passé : la relance part.
    opt('oli_acr_test_smtp_port', None)
    sql(f'UPDATE wp_oli_acr_carts SET next_send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id={cid}')
    php_nodebug('do_action("oli_acr_process");')
    c2 = cart(cid)
    got = mails_to(email)
    result('(R1) SMTP rétabli : le nouvel essai envoie la relance, statut « reminded », compteur remis à zéro',
           c2[0] == 'reminded' and c2[1] == '1' and c2[2] == '0' and len(got) == 1, f'carts={c2} mails={len(got)}')
    # Épuisement : 1 essai + 3 nouveaux essais, puis arrêt.
    email2 = f'r1b-{STAMP}@example.test'
    cid2 = due_cart(email2)
    opt('oli_acr_test_smtp_port', 1099)
    seen = []
    for i in range(4):
        sql(f'UPDATE wp_oli_acr_carts SET next_send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id={cid2}')
        php_nodebug('do_action("oli_acr_process");')
        c = cart(cid2); seen.append((c[2], c[4] != 'NULL', c[5]))
    result('(R1) Délais croissants 5 min, 30 min, 2 h, puis abandon (« failed », plus de nouvel essai)',
           [s[0] for s in seen] == ['1', '2', '3', '4'] and [s[1] for s in seen] == [True, True, True, False]
           and 240 <= int(seen[0][2]) <= 330 and 1700 <= int(seen[1][2]) <= 1830 and 7100 <= int(seen[2][2]) <= 7230, f'{seen}')
    # « Envoyer maintenant » après abandon : compteur remis à zéro.
    opt('oli_acr_test_smtp_port', None)
    ca = Client(); ca.login('admin', 'admin')
    _, lst, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=carts&status=failed')
    mu = re.search(r'href="([^"]*do=send[^"]*cart=' + str(cid2) + r'[^"]*)"', htmllib.unescape(lst)) or re.search(r'href="([^"]*cart=' + str(cid2) + r'[^"]*do=send[^"]*)"', htmllib.unescape(lst))
    ca.req(htmllib.unescape(mu.group(1)) if mu else '/')
    c3 = cart(cid2)
    result('(R1) « Envoyer maintenant » sur un panier en échec : envoi, compteur à zéro', c3[0] == 'reminded' and c3[2] == '0' and len(mails_to(email2)) == 1, f'{c3}')
    # Commande en attente : nouvel essai planifié (méta), puis _oli_acr_done après épuisement.
    oid = php('''$o = wc_create_order(); $o->add_product(wc_get_product(10), 1); $o->set_billing_email("r1o-''' + STAMP + '''@example.test"); $o->set_created_via("checkout");
      $o->set_date_created(time() - 3*HOUR_IN_SECONDS); $o->calculate_totals(); $o->set_status("pending"); $o->save(); echo $o->get_id();''')
    php('$s = get_option("oli_acr_settings"); $s["pending_enabled"] = "yes"; update_option("oli_acr_settings", $s);')
    opt('oli_acr_test_smtp_port', 1099)
    php_nodebug('OLI_ACR_Scheduler::send_due_orders();')
    m1 = php(f'$o = wc_get_order({oid}); echo $o->get_meta("_oli_acr_fail_count"), "|", $o->get_meta("_oli_acr_retry_at") - time(), "|", $o->get_meta("_oli_acr_done");')
    php_nodebug('OLI_ACR_Scheduler::send_due_orders();')
    m2 = php(f'$o = wc_get_order({oid}); echo $o->get_meta("_oli_acr_fail_count");')
    for i in range(3):
        php(f'$o = wc_get_order({oid}); $o->update_meta_data("_oli_acr_retry_at", time() - 1); $o->save();')
        php_nodebug('OLI_ACR_Scheduler::send_due_orders();')
    m3 = php(f'$o = wc_get_order({oid}); echo $o->get_meta("_oli_acr_fail_count"), "|", $o->get_meta("_oli_acr_done");')
    opt('oli_acr_test_smtp_port', None)
    p1 = m1.split('|')
    result('(R1) Commande en attente : échec => nouvel essai dans 5 min (pas avant), abandon après 4 échecs (_oli_acr_done)',
           p1[0] == '1' and 240 <= int(p1[1]) <= 330 and p1[2] == '' and m2 == '1' and m3 == '4|1', f'1er={m1} avant_délai={m2} fin={m3}')
    php(f'wp_delete_post({oid}, true); $o = wc_get_order({oid}); if ($o) {{ $o->delete(true); }} $s = get_option("oli_acr_settings"); $s["pending_enabled"] = "no"; update_option("oli_acr_settings", $s);')

# ------------------------------------------------------------------ R2 --
def t_r2():
    reset('always')
    # Site 1.0.x : pas d'option de version, mode « toujours ».
    php('delete_option("oli_acr_version"); OLI_ACR_Install::maybe_upgrade();')
    mode = php('echo oli_acr_get_setting("guest_tracking");')
    flag = php('echo get_option("oli_acr_notice_consent_migrated") ? "1" : "0";')
    notice = admin_notices_html()
    result('(R2) Mise à jour depuis 1.0.x en mode « toujours » : passage au consentement (interrupteur ON) + avis',
           mode == 'consent' and flag == '1' and 'oli-acr-consent-migrated' in notice and 'oli-acr-consent-off' not in notice, f'mode={mode} avis={flag}')
    ca = Client(); ca.login('admin', 'admin')
    _, page, _, _ = ca.req('/wp-admin/index.php')
    m = re.search(r'href="([^"]*oli_acr_dismiss_notice[^"]*consent_migrated[^"]*)"', page)
    ca.req(htmllib.unescape(m.group(1)) if m else '/')
    flag2 = php('echo get_option("oli_acr_notice_consent_migrated") ? "1" : "0";')
    _, page2, _, _ = ca.req('/wp-admin/index.php')
    result('(R2) Avis affiché sur le tableau de bord jusqu\'à ce qu\'on le ferme (lien avec nonce)',
           bool(m) and flag2 == '0' and 'oli-acr-consent-migrated' not in page2, f'lien={bool(m)} après={flag2}')
    # Déjà en 1.1.0 : un choix « toujours » délibéré n'est pas modifié.
    php('$s = get_option("oli_acr_settings"); $s["guest_tracking"] = "always"; update_option("oli_acr_settings", $s); OLI_ACR_Install::migrate();')
    mode2 = php('echo oli_acr_get_setting("guest_tracking");')
    sec = Client().req('/wp-admin/admin-post.php?action=oli_acr_dismiss_notice&notice=consent_migrated')[0]
    result('(R2) Migration une seule fois (version < 1.1.0) ; fermeture refusée sans session/nonce', mode2 == 'always' and sec in (400, 403, 302), f'mode={mode2} sans_session={sec}')
    reset()

# ------------------------------------------------------------------ R3 --
def t_r3():
    reset()
    wp('wc', 'hpos', 'sync', check=False)
    out = wp('wc', 'hpos', 'disable', check=False)
    hpos = php('echo OLI_ACR_Scheduler::hpos_enabled() ? "on" : "off";')
    php('$s = get_option("oli_acr_settings"); $s["pending_enabled"] = "yes"; $s["pending_after"] = array("value"=>1,"unit"=>"hours"); update_option("oli_acr_settings", $s);')
    ids = php('''$ids = array();
      for ($i = 0; $i < 55; $i++) { $o = wc_create_order(); $o->add_product(wc_get_product(10), 1); $o->set_billing_email("r3-done-$i@example.test"); $o->set_created_via("checkout");
        $o->set_date_created(time() - 3*DAY_IN_SECONDS + $i); $o->update_meta_data("_oli_acr_done", 1); $o->calculate_totals(); $o->set_status("pending"); $o->save(); $ids[] = $o->get_id(); }
      $o = wc_create_order(); $o->add_product(wc_get_product(10), 1); $o->set_billing_email("r3-new-''' + STAMP + '''@example.test"); $o->set_created_via("checkout");
      $o->set_date_created(time() - 2*DAY_IN_SECONDS); $o->calculate_totals(); $o->set_status("pending"); $o->save(); $ids[] = $o->get_id(); echo implode(",", $ids);''').split(',')
    target = ids[-1]
    res = php('''$w = array(); add_action("doing_it_wrong_run", function($f, $m) use (&$w) { $w[] = $f . ": " . $m; }, 10, 2);
      $n = OLI_ACR_Scheduler::send_due_orders(); $o = wc_get_order(''' + target + ''');
      $c = OLI_ACR_Scheduler::orders_query(array("status"=>"pending","limit"=>100,"return"=>"ids"), "_oli_acr_sent", "EXISTS");
      echo json_encode(array("sent"=>$n, "target"=>$o->get_meta("_oli_acr_sent"), "wrong"=>$w, "exists"=>wc_get_orders($c)));''')
    try:
        d = json.loads(res)
    except Exception:
        d = {'raw': res}
    result('(R3) HPOS désactivé : aucun avertissement « meta_query », condition appliquée',
           hpos == 'off' and d.get('wrong') == [] and d.get('exists') == [int(target)], f'hpos={hpos} {out[-80:]} wrong={d.get("wrong")} exists={d.get("exists")}')
    result('(R3) Pas de famine : 55 vieilles commandes déjà traitées, la commande plus récente est relancée (lot de 50)',
           d.get('sent') == 1 and d.get('target') == ['tpl_order_1'] or (d.get('sent') == 1 and d.get('target')), f'{d}')
    php('foreach (array(' + ','.join(ids) + ') as $id) { $o = wc_get_order($id); if ($o) { $o->delete(true); } } $s = get_option("oli_acr_settings"); $s["pending_enabled"] = "no"; update_option("oli_acr_settings", $s);')
    wp('wc', 'hpos', 'sync', check=False)
    wp('wc', 'hpos', 'enable', check=False)
    hpos2 = php('echo OLI_ACR_Scheduler::hpos_enabled() ? "on" : "off";')
    res2 = php('''$w = array(); add_action("doing_it_wrong_run", function($f, $m) use (&$w) { $w[] = $f; }, 10, 2); OLI_ACR_Scheduler::send_due_orders(); OLI_ACR_Scheduler::cleanup_pending_orders(); echo count($w);''')
    result('(R3) HPOS réactivé : mêmes requêtes sans avertissement', hpos2 == 'on' and res2 == '0', f'hpos={hpos2} wrong={res2}')

# ------------------------------------------------------------------ R4 --
def t_r4():
    reset()
    a = php('echo OLI_ACR_Scheduler::acquire_lock() ? "1" : "0";')  # verrou laissé (processus « tué »)
    b = php('echo OLI_ACR_Scheduler::acquire_lock() ? "1" : "0";')
    val = php('echo get_option("oli_acr_process_lock");')
    cid = due_cart(f'r4-lock-{STAMP}@example.test')
    php('do_action("oli_acr_process");')
    blocked = cart(cid)[1]
    sql("UPDATE wp_options SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 1), '|', UNIX_TIMESTAMP() - 1) WHERE option_name = 'oli_acr_process_lock'")
    php('do_action("oli_acr_process");')
    after = cart(cid)[1]
    left = php('echo false === get_option("oli_acr_process_lock") ? "libre" : "pris";')
    ttl = int(val.split('|')[1]) - int(time.time()) if '|' in val else -1
    result('(R4) Verrou atomique avec durée de vie (10 min) : 2e passage refusé, verrou expiré repris, libéré à la fin',
           a == '1' and b == '0' and blocked == '0' and after == '1' and left == 'libre' and 540 <= ttl <= 610, f'a={a} b={b} ttl={ttl} bloqué={blocked} repris={after} {left}')
    # Deux passages simultanés (sans verrou) avec un SMTP lent : chaque panier est réclamé avant l'envoi, aucun doublon.
    mp_clear()
    emails = [f'r4-{i}-{STAMP}@example.test' for i in range(6)]
    for e in emails:
        due_cart(e)
    opt('oli_acr_test_mail_sleep', 1)
    procs = [subprocess.Popen(['wp', 'eval', 'OLI_ACR_Scheduler::send_due_carts();'], cwd=WP, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) for _ in range(2)]
    for p in procs:
        p.wait()
    opt('oli_acr_test_mail_sleep', None)
    counts = [len(mails_to(e)) for e in emails]
    result('(R4) Deux passages simultanés, SMTP lent : chaque panier réclamé, 6 courriels pour 6 paniers, 0 doublon', counts == [1] * 6, f'{counts}')
    # Budget de temps : un lot s'arrête avant la fin du verrou et reprend au passage suivant.
    mp_clear(); reset()
    for i in range(3):
        due_cart(f'r4-budget-{i}-{STAMP}@example.test')
    opt('oli_acr_test_mail_sleep', 2)
    php('add_filter("oli_acr_time_budget", function() { return 1; }); do_action("oli_acr_process");')
    opt('oli_acr_test_mail_sleep', None)
    n1 = sql("SELECT COUNT(*) FROM wp_oli_acr_carts WHERE emails_sent = 1")[0][0]
    # Le passage suivant peut être celui du planificateur réel (WP-Cron/Action Scheduler déclenché par le trafic
    # des tests précédents) : s'il détient le verrou, notre passage s'arrête, on attend qu'il termine.
    for _ in range(6):
        php('do_action("oli_acr_process");')
        n2 = sql("SELECT COUNT(*) FROM wp_oli_acr_carts WHERE emails_sent = 1")[0][0]
        if n2 == '3':
            break
        time.sleep(2)
    states = sql("SELECT id, status, emails_sent, fail_count, IFNULL(last_error,''), IFNULL(next_send_at,'NULL'), UTC_TIMESTAMP() FROM wp_oli_acr_carts WHERE email LIKE 'r4-budget-%' ORDER BY id")
    result('(R4) Budget de temps : le passage s\'arrête avant l\'expiration du verrou, le suivant termine', n1 in ('1', '2') and n2 == '3', f'1er={n1} 2e={n2} {states}')
    old = php('echo get_transient("oli_acr_lock") === false ? "absent" : "présent";')
    result('(R4) Ancien transient « oli_acr_lock » plus utilisé', old == 'absent' and 'oli_acr_lock\'' not in open(os.path.join(ROOT, 'includes/class-oli-acr-scheduler.php')).read(), old)

# ------------------------------------------------------------------ R5 --
def t_r5():
    reset()
    email = f'r5-{STAMP}@example.test'
    due_cart(email)
    php('do_action("oli_acr_process");')
    m = mails_to(email)
    raw = mp_raw(m[0]['ID']) if m else ''
    full = mail_get(m[0]['ID']) if m else {}
    text = full.get('Text', '')
    ok = 'multipart/alternative' in raw and 'text/plain' in raw and 'text/html' in raw and 'oli_acr_recover=' in text and '<' not in text.replace('<http', '') and 'Zoé' in text
    result('(R5) Courriel multipart/alternative : partie texte lisible (sans balises, lien de récupération en clair) + partie HTML', ok, text[:400].replace('\n', ' ⏎ '))
    other = php('var_export(wp_mail("r5-other@example.test", "Autre", "Corps <b>HTML</b>", array("Content-Type: text/html; charset=UTF-8", "From: Test <test@example.test>")));')
    time.sleep(1)
    m2 = mails_to('r5-other@example.test')
    raw2 = mp_raw(m2[0]['ID']) if m2 else ''
    result('(R5) Les autres courriels du site ne reçoivent pas la partie texte du plugin', bool(m2) and 'multipart/alternative' not in raw2, f'envoi={other} reçus={len(m2)}')

# ------------------------------------------------------------------ R6 --
def t_r6():
    reset()
    c = Client()
    c.req('/?add-to-cart=10')
    heads = {}
    for path in ('/checkout/', '/checkout-classique/'):
        s, _, h, _ = c.req(path)
        heads[path] = (s, h.get('Cache-Control', ''))
    st, body, h, _ = c.req('/?wc-ajax=oli_acr_nonce')
    nonce_ok = st == 200 and '"nonce"' in body and 'no-store' in h.get('Cache-Control', '') + ''.join(h.get('Cache-Control', '')) or ('no-cache' in h.get('Cache-Control', '') and '"nonce"' in body)
    result('(R6) Pages de paiement (principale et 2e page au code court) en no-cache ; point « oli_acr_nonce » non mis en cache',
           all('no-cache' in v[1] for v in heads.values()) and nonce_ok, f'{heads} nonce={st} {h.get("Cache-Control")}')
    php('$s = get_option("oli_acr_settings"); $s["guest_tracking"] = "always"; update_option("oli_acr_settings", $s);')
    email = f'r6-{STAMP}@example.test'
    v = visit(CHECKOUT_URL=B + '/checkout-classique/', EMAIL=email, BAD_NONCE=1)
    n = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email = '{email}'")[0][0]
    result('(R6) Nonce périmé (page en cache) : 403, nonce frais demandé, nouvel essai réussi, panier capté',
           'capture:403' in v.get('caps', []) and 'nonce:200' in v.get('caps', []) and 'capture:200' in v.get('caps', []) and n == '1', f'{v} lignes={n}')

# ------------------------------------------------------------------ R7 --
def t_r7():
    txt = open(os.path.join(ROOT, 'readme.txt'), encoding='utf-8').read()
    bad = ['as soon as it is typed… even for guests', 'The email is saved as soon as it is typed']
    result('(R7) readme : plus de « enregistré dès qu\'il est tapé » sans mention du consentement ; consentement décrit',
           not any(b in txt for b in bad) and txt.lower().count('consent') >= 8 and 'Law 25' in txt, f'consent×{txt.lower().count("consent")}')

# ------------------------------------------------------------------ R8 --
def t_r8():
    reset()
    qlog = '/tmp/oli-acr-r8-queries.log'
    def count_front():
        if os.path.exists(qlog):
            os.remove(qlog)
        opt('oli_acr_test_query_log', qlog)
        Client().req('/product/tuque-en-laine/')
        opt('oli_acr_test_query_log', None)
        return open(qlog).read().splitlines() if os.path.exists(qlog) else []
    # État d'une installation 1.0.x : options lues à chaque page en autoload=off.
    php('wp_set_option_autoload_values(array("oli_acr_settings"=>false,"oli_acr_db_version"=>false,"oli_acr_version"=>false,"oli_acr_languages_signature"=>false));')
    before = count_front()
    php('delete_option("oli_acr_version"); OLI_ACR_Install::maybe_upgrade();')
    after = count_front()
    after2 = count_front()
    auto = sql("SELECT option_name, autoload FROM wp_options WHERE option_name IN ('oli_acr_settings','oli_acr_db_version','oli_acr_version','oli_acr_languages_signature')")
    result('(R8) Front : requêtes d\'options du plugin, 1.0.x vs 1.1.0 après migration (autoload)',
           len(before) >= 2 and len(after2) == 0 and all(a[1] in ('on', 'yes', 'auto-on') for a in auto), f'avant={len(before)} après_migration={len(after)} ensuite={len(after2)} {auto} {before[:3]}')

# ------------------------------------------------------------------ R9 --
def t_r9():
    reset('always')
    opt('oli_acr_test_rate_limits', 'default')
    c = Client(); c.req('/?add-to-cart=10')
    codes = []
    for i in range(4):
        _, html, _, _ = c.req('/checkout-classique/')
        cfg = json.loads(re.search(r'var oliAcrCapture = (\{.*?\});', html).group(1))
        s, body, _, _ = c.req(cfg['endpoint'].replace('\\/', '/'), {'nonce': cfg['nonce'], 'email': f'r9-s{i}-{STAMP}@example.test'})
        codes.append(s)
    same = c.req(cfg['endpoint'].replace('\\/', '/'), {'nonce': cfg['nonce'], 'email': f'r9-s0-{STAMP}@example.test'})[0]
    result('(R9) Par session : 3 adresses distinctes par heure, la 4e refusée (429), une adresse déjà vue reste permise', codes == [200, 200, 200, 429] and same == 200, f'{codes} même={same}')
    # Par IP : 20 adresses distinctes par heure, toutes sessions confondues.
    codes2 = []
    for i in range(6):
        cl = Client(); cl.req('/?add-to-cart=10')
        _, html, _, _ = cl.req('/checkout-classique/')
        cfg = json.loads(re.search(r'var oliAcrCapture = (\{.*?\});', html).group(1))
        for j in range(3):
            codes2.append(cl.req(cfg['endpoint'].replace('\\/', '/'), {'nonce': cfg['nonce'], 'email': f'r9-ip{i}-{j}-{STAMP}@example.test'})[0])
    # 3 déjà vues (session 1) + 18 = 21e adresse => refus à partir de la 18e requête de cette boucle.
    result('(R9) Par IP : 20 adresses distinctes par heure, la 21e refusée (429)', codes2[:17] == [200] * 17 and codes2[17] == 429, f'{codes2}')
    php('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'_transient%oli_acr_rl_%\'");')
    # Requêtes par IP : 60 par 10 minutes.
    cl = Client(); cl.req('/?add-to-cart=10')
    _, html, _, _ = cl.req('/checkout-classique/')
    cfg = json.loads(re.search(r'var oliAcrCapture = (\{.*?\});', html).group(1))
    codes3 = [cl.req(cfg['endpoint'].replace('\\/', '/'), {'nonce': cfg['nonce'], 'email': f'r9-req-{STAMP}@example.test'})[0] for _ in range(61)]
    result('(R9) Par IP : 60 requêtes par 10 minutes, la 61e refusée (429)', codes3[:60] == [200] * 60 and codes3[60] == 429, f'200×{codes3.count(200)} 429×{codes3.count(429)}')
    php('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'_transient%oli_acr_rl_%\'");')
    hp_email = f'r9-hp-{STAMP}@example.test'
    s, body, _, _ = cl.req(cfg['endpoint'].replace('\\/', '/'), {'nonce': cfg['nonce'], 'email': hp_email, 'oli_acr_hp': 'http://spam'})
    n = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email = '{hp_email}'")[0][0]
    _, html, _, _ = cl.req('/checkout-classique/')
    result('(R9) Piège à robots : champ caché rempli => réponse neutre, rien d\'enregistré ; champ présent au checkout classique',
           s == 200 and '"captured":false' in body and n == '0' and 'id="oli_acr_hp"' in html, f'{s} {body} lignes={n}')
    opt('oli_acr_test_rate_limits', None)
    php('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'_transient%oli_acr_rl_%\'");')

# ------------------------------------------------- Consentement (éditeur) --
def t_consent():
    reset()
    ca = Client(); ca.login('admin', 'admin')
    _, page, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=settings')
    form = dict(re.findall(r'<input type="hidden" (?:id="[^"]*" )?name="(_wpnonce|_wp_http_referer|action)" value="([^"]*)"', page))
    fields = {k: v for k, v in re.findall(r'name="(s\[[^"]+\])" value="([^"]*)"', page)}
    loc = php('echo OLI_ACR_Lang::fallback_language();')
    editor = re.search(r'<textarea[^>]*name="s\[consent_texts\]\[' + loc + r'\]"', page) is not None and 'wp-editor-area' in page
    html_in = 'J\'accepte les <a href="https://example.test/confidentialite" target="_blank">rappels</a> <strong>par courriel</strong><script>alert(1)</script> <a href="javascript:alert(2)" onclick="x()">x</a> <img src=x onerror=alert(3)>'
    data = {'action': 'oli_acr_save_settings', '_wpnonce': form.get('_wpnonce', ''), '_wp_http_referer': form.get('_wp_http_referer', ''),
            's[enabled]': 'yes', 's[guest_capture]': 'capture', 's[consent_required]': ['no', 'yes'], f's[consent_texts][{loc}]': html_in,
            's[abandon_after][value]': '1', 's[abandon_after][unit]': 'minutes', 's[cron_interval][value]': '1', 's[cron_interval][unit]': 'minutes', 's[retention_days]': '365'}
    ca.req('/wp-admin/admin-post.php', data)
    saved = php(f'$s = get_option("oli_acr_settings"); echo $s["consent_texts"]["{loc}"] ?? "";')
    mode = php('echo oli_acr_get_setting("guest_tracking");')
    ok = ('<a href="https://example.test/confidentialite" target="_blank" rel="noopener noreferrer">rappels</a>' in saved and '<strong>par courriel</strong>' in saved
          and 'script' not in saved and 'javascript' not in saved and 'onclick' not in saved and 'onerror' not in saved and '<img' not in saved)
    result('(Consentement) Éditeur par langue (wp_editor) ; enregistré avec wp_kses : liens (href/target/rel), strong, em seulement', editor and ok and mode == 'consent', f'éditeur={editor} mode={mode} enregistré={saved}')
    c = Client(); c.req('/?add-to-cart=10'); _, chtml, _, _ = c.req('/checkout-classique/')
    result('(Consentement) Checkout classique : libellé avec le lien, HTML filtré', 'href="https://example.test/confidentialite"' in chtml and '<script>alert' not in chtml and 'javascript:' not in chtml, re.search(r'oli_acr_consent[^\n]{0,300}', chtml).group(0)[:300] if 'oli_acr_consent' in chtml else 'absent')
    v = visit(CHECKOUT_URL=B + '/checkout/', EMAIL=f'cons-b-{STAMP}@example.test', CHECK=1)
    result('(Consentement) Checkout en blocs : libellé enrichi (lien cliquable) et capture après la case', '<a href="https://example.test/confidentialite"' in v.get('labelHtml', '') and 'capture:200' in v.get('caps', []), f'{v}')
    # Champ vidé : texte par défaut traduit (pas figé).
    data[f's[consent_texts][{loc}]'] = ''
    _, page, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=settings')
    data['_wpnonce'] = dict(re.findall(r'<input type="hidden" (?:id="[^"]*" )?name="(_wpnonce|_wp_http_referer|action)" value="([^"]*)"', page)).get('_wpnonce', '')
    ca.req('/wp-admin/admin-post.php', data)
    fr = php('echo oli_acr_consent_text("fr_CA");'); en = php('echo oli_acr_consent_text("en_US");')
    result('(Consentement) Champ vide : texte par défaut traduisible (__()) dans chaque langue', fr.startswith('Enregistrer mon courriel') and en.startswith('Save my email'), f'fr={fr} en={en}')
    # Interrupteur OFF : avertissement sur la page des réglages et avis général permanent.
    data['s[consent_required]'] = 'no'
    _, page, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=settings')
    data['_wpnonce'] = dict(re.findall(r'<input type="hidden" (?:id="[^"]*" )?name="(_wpnonce|_wp_http_referer|action)" value="([^"]*)"', page)).get('_wpnonce', '')
    ca.req('/wp-admin/admin-post.php', data)
    mode2 = php('echo oli_acr_get_setting("guest_tracking");')
    _, sp, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=settings')
    _, dash, _, _ = ca.req('/wp-admin/index.php')
    _, plugins, _, _ = ca.req('/wp-admin/plugins.php')
    dismiss = 'oli_acr_dismiss_notice&amp;notice=consent_off' in dash
    result('(Consentement) Interrupteur OFF : mode « always », avertissement Loi 25/RGPD sur les réglages + avis général permanent (non fermable)',
           mode2 == 'always' and sp.count('oli-acr-consent-off') >= 2 and ('Law 25' in sp or 'Loi 25' in sp) and 'oli-acr-consent-off' in dash and 'oli-acr-consent-off' in plugins and not dismiss,
           f'mode={mode2} réglages={sp.count("oli-acr-consent-off")} tableau={"oli-acr-consent-off" in dash} extensions={"oli-acr-consent-off" in plugins}')
    data['s[consent_required]'] = ['no', 'yes']
    _, page, _, _ = ca.req('/wp-admin/admin.php?page=oli-acr&tab=settings')
    data['_wpnonce'] = dict(re.findall(r'<input type="hidden" (?:id="[^"]*" )?name="(_wpnonce|_wp_http_referer|action)" value="([^"]*)"', page)).get('_wpnonce', '')
    ca.req('/wp-admin/admin-post.php', data)
    _, dash2, _, _ = ca.req('/wp-admin/index.php')
    result('(Consentement) Interrupteur ON par défaut (installation neuve) ; remis ON : plus d\'avertissement',
           php('echo oli_acr_default_settings()["guest_tracking"];') == 'consent' and php('echo oli_acr_get_setting("guest_tracking");') == 'consent' and 'oli-acr-consent-off' not in dash2, '')

# ------------------------------------------------- R10 : clients connectés --
def t_r10():
    reset('consent')
    def login_client():
        c = Client(); c.login('cliente', 'cliente'); return c
    def rows():
        return sql("SELECT COUNT(*), IFNULL(MAX(consent),-1) FROM wp_oli_acr_carts WHERE user_id = 2")[0]
    def ajax(c, consent):
        _, html, _, _ = c.req('/checkout-classique/')
        cfg = json.loads(re.search(r'var oliAcrCapture = (\{.*?\});', html).group(1))
        s, body, _, _ = c.req(cfg['endpoint'].replace('\\/', '/'), {'nonce': cfg['nonce'], 'email': 'cliente@example.com', 'consent': consent})
        return cfg, body, html
    c = login_client(); c.req('/?add-to-cart=10'); c.req('/?add-to-cart=11')
    r0 = rows()
    cfg, b_no, html = ajax(c, '0')
    r1 = rows()
    cfg, b_yes, _ = ajax(c, '1')
    r2 = rows()
    meta = php('echo get_user_meta(2, "_oli_acr_consent", true) ? "1" : "0";')
    result('(R10) Interrupteur ON, client connecté : case affichée, rien de suivi avant la case, suivi après (consentement mémorisé)',
           str(cfg.get('consent')) == '1' and 'oli_acr_consent' in html and r0[0] == '0' and r1[0] == '0' and 'no_consent' in b_no and r2 == ['1', '1'] and meta == '1',
           f'avant={r0} sans_case={r1} {b_no} avec_case={r2} méta={meta}')
    c2 = login_client(); c2.req('/?add-to-cart=12')
    _, html2, _, _ = c2.req('/checkout-classique/')
    pre = re.search(r'<input[^>]*id="oli_acr_consent"[^>]*>', html2)
    result('(R10) Consentement déjà donné : panier suivi sans nouvelle case à cocher (case précochée, retirable)', pre and 'checked' in pre.group(0) and rows()[0] == '1', pre.group(0) if pre else 'absent')
    # Retrait : case décochée => panier oublié et consentement effacé.
    ajax(c2, '0')
    r3 = rows(); meta2 = php('echo get_user_meta(2, "_oli_acr_consent", true) ? "1" : "0";')
    result('(R10) Retrait du consentement par un client connecté : panier supprimé, méta effacée', r3[0] == '0' and meta2 == '0', f'{r3} méta={meta2}')
    # Panier connecté capté avant la 1.1.0 (consent=1 automatique, sans case) : plus relancé.
    cid = due_cart('cliente@example.com', user_id=2, consent=1)
    php('do_action("oli_acr_process");')
    legacy = cart(cid)
    result('(R10) Panier de client connecté sans consentement mémorisé (capté par la 1.0.x) : aucune relance', legacy[1] == '0' and len(mails_to('cliente@example.com')) == 0, f'{legacy}')
    php('update_user_meta(2, "_oli_acr_consent", time());')
    sql(f'UPDATE wp_oli_acr_carts SET next_send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id={cid}')
    php('do_action("oli_acr_process");')
    result('(R10) Client connecté qui a consenti : relance envoyée', cart(cid)[1] == '1' and len(mails_to('cliente@example.com')) == 1, f'{cart(cid)}')
    # Invité, interrupteur ON : case requise.
    g = Client(); g.req('/?add-to-cart=10')
    ge = f'r10-g-{STAMP}@example.test'
    b0 = g.capture_classic(ge, consent='0'); n0 = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='{ge}'")[0][0]
    b1 = g.capture_classic(ge, consent='1'); n1 = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='{ge}' AND consent=1")[0][0]
    result('(R10) Interrupteur ON, invité : rien sans case, suivi avec la case', n0 == '0' and n1 == '1', f'{b0} {b1}')
    # Interrupteur OFF : invités et connectés suivis sans case.
    reset('always')
    c3 = login_client(); c3.req('/?add-to-cart=10')
    _, html3, _, _ = c3.req('/checkout-classique/')
    r4 = rows()
    cfg3 = json.loads(re.search(r'var oliAcrCapture = (\{.*?\});', html3).group(1))
    g2 = Client(); g2.req('/?add-to-cart=10')
    ge2 = f'r10-g2-{STAMP}@example.test'
    g2.capture_classic(ge2)
    n2 = sql(f"SELECT COUNT(*) FROM wp_oli_acr_carts WHERE email='{ge2}'")[0][0]
    result('(R10) Interrupteur OFF : client connecté suivi sans case, invité capté sans case, aucune case affichée',
           r4[0] == '1' and n2 == '1' and str(cfg3.get('consent')) == '0' and 'id="oli_acr_consent"' not in html3, f'connecté={r4} invité={n2} cfg={cfg3.get("consent")}')
    # Interrupteur OFF : relances envoyées sans consentement, au client connecté (sans méta) comme à l'invité.
    php('delete_user_meta(2, "_oli_acr_consent");')
    mp_clear()
    cid_u = due_cart('cliente@example.com', user_id=2, consent=0)
    cid_g = due_cart(f'r10-g3-{STAMP}@example.test', consent=0)
    php('do_action("oli_acr_process");')
    result('(R10) Interrupteur OFF : relance envoyée au client connecté sans consentement mémorisé et à l\'invité sans case',
           cart(cid_u)[1] == '1' and cart(cid_g)[1] == '1' and len(mails_to('cliente@example.com')) == 1 and len(mails_to(f'r10-g3-{STAMP}@example.test')) == 1,
           f'connecté={cart(cid_u)} invité={cart(cid_g)}')
    reset('consent')

TESTS = {'r1': t_r1, 'r2': t_r2, 'r3': t_r3, 'r4': t_r4, 'r5': t_r5, 'r6': t_r6, 'r7': t_r7, 'r8': t_r8, 'r9': t_r9, 'consent': t_consent, 'r10': t_r10}
if __name__ == '__main__':
    for name in (sys.argv[1:] or list(TESTS)):
        print(f'\n===== {name} =====', flush=True)
        try:
            TESTS[name]()
        except Exception as e:
            result(f'({name}) exception', False, traceback.format_exc()[-800:])
    for o in ('oli_acr_test_smtp_port', 'oli_acr_test_mail_sleep', 'oli_acr_test_rate_limits', 'oli_acr_test_query_log'):
        opt(o, None)
    print()
    for n, ok, _ in RESULTS:
        print('PASS' if ok else 'FAIL', n)
    print(f'{sum(1 for r in RESULTS if r[1])}/{len(RESULTS)}')
