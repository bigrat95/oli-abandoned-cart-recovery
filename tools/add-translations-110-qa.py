#!/usr/bin/env python3
"""1.1.0 (revue QA, B2 à B5) : nouvelles chaînes fr_CA / fr_FR, singuliers et pluriels ; msgmerge + msgfmt."""
import os, re, subprocess, sys
D = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'languages')
POT = os.path.join(D, 'oli-abandoned-cart-recovery.pot')
SING = {
 'Oli Abandoned Cart Recovery: carts on translated checkout pages are not captured.': 'Oli Abandoned Cart Recovery : les paniers des pages de paiement traduites ne sont pas captés.',
 'Polylang is active without "Polylang for WooCommerce", so WooCommerce does not recognize the translated checkout pages: the capture script is not loaded there and no cart is saved in those languages. Install "Polylang for WooCommerce", or link the translated cart and checkout pages to WooCommerce with the woocommerce_get_checkout_page_id filter (see the plugin FAQ).':
   "Polylang est actif sans « Polylang for WooCommerce » : WooCommerce ne reconnaît donc pas les pages de paiement traduites. Le script de capture n'y est pas chargé et aucun panier n'est enregistré dans ces langues. Installez « Polylang for WooCommerce », ou reliez les pages panier et paiement traduites à WooCommerce avec le filtre woocommerce_get_checkout_page_id (voir la FAQ de l'extension).",
 'Polylang for WooCommerce': 'Polylang for WooCommerce',
 'No coupon will be created:': 'Aucun coupon ne sera créé :',
 'the email does not contain {coupon} or {coupon_code} (%s). Add one of these tags to the content to send the coupon.': "le courriel ne contient ni {coupon} ni {coupon_code} (%s). Ajoutez l'une de ces balises au contenu pour envoyer le coupon.",
 'Error:': 'Erreur :',
}
PLUR = {
 ('%1$d failure, the last one: %2$s.', '%1$d failures, the last one: %2$s.'): ('%1$d échec, le dernier : %2$s.', '%1$d échecs, le dernier : %2$s.'),
 ('%d day', '%d days'): ('%d jour', '%d jours'),
 ('%d hour', '%d hours'): ('%d heure', '%d heures'),
 ('%d minute', '%d minutes'): ('%d minute', '%d minutes'),
}
def esc(s): return s.replace('\\', '\\\\').replace('"', '\\"')
def unesc(s): return s.replace('\\"', '"').replace('\\\\', '\\')
missing = []
for loc in ('fr_CA', 'fr_FR'):
    sing = {k: (v.replace('le courriel', "l'e-mail") if loc == 'fr_FR' else v) for k, v in SING.items()}
    po = os.path.join(D, f'oli-abandoned-cart-recovery-{loc}.po')
    subprocess.run(['msgmerge', '--quiet', '--no-fuzzy-matching', '--no-wrap', '--update', '--backup=none', po, POT], check=True)
    txt = open(po, encoding='utf-8').read()
    out = []
    for e in txt.split('\n\n'):
        mp = re.search(r'^msgid "(.*)"\nmsgid_plural "(.*)"\nmsgstr\[0\] ""\nmsgstr\[1\] ""$', e, re.M)
        m = re.search(r'^msgid "(.*)"\nmsgstr ""$', e, re.M)
        if mp:
            key = (unesc(mp.group(1)), unesc(mp.group(2)))
            if key in PLUR:
                a, b = PLUR[key]
                e = e.replace(mp.group(0), f'msgid "{mp.group(1)}"\nmsgid_plural "{mp.group(2)}"\nmsgstr[0] "{esc(a)}"\nmsgstr[1] "{esc(b)}"')
            else:
                missing.append((loc, key))
        elif m and m.group(1):
            key = unesc(m.group(1))
            if key in sing:
                e = e.replace(m.group(0), 'msgid "' + m.group(1) + '"\nmsgstr "' + esc(sing[key]) + '"')
            else:
                missing.append((loc, key))
        out.append(e)
    out = [e for e in out if not e.lstrip().startswith('#~')]
    open(po, 'w', encoding='utf-8').write('\n\n'.join(out).rstrip('\n') + '\n')
    subprocess.run(['msgfmt', '--check', '--statistics', '-o', po[:-3] + '.mo', po], check=True)
    print('OK', loc)
if missing:
    print('MANQUANTS :', missing); sys.exit(1)
