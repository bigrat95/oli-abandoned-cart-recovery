#!/usr/bin/env python3
"""1.1.0 : met à jour les .po (fr_CA, fr_FR) depuis le .pot avec msgmerge, remplit les nouvelles chaînes, compile les .mo."""
import os, re, subprocess, sys
D = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'languages')
POT = os.path.join(D, 'oli-abandoned-cart-recovery.pot')
NEW = {
 'fr_CA': {
  'Fallback language': 'Langue de repli',
  'Site default language (%s)': 'Langue par défaut du site (%s)',
  'Language used for a cart whose language is unknown or no longer active, and for texts that are missing in a language. Languages detected with: %s.': "Langue utilisée pour un panier dont la langue est inconnue ou n'est plus active, et pour les textes absents dans une langue. Langues détectées avec : %s.",
  'Label of the checkbox shown under the email field (consent mode), for each language. Leave a language empty to use, in order: its WPML or Polylang string translation, the default text translated in that language, then the fallback language text.': "Libellé de la case affichée sous le champ courriel (mode consentement), pour chaque langue. Laissez une langue vide pour utiliser, dans l'ordre : sa traduction de chaîne WPML ou Polylang, le texte par défaut traduit dans cette langue, puis le texte de la langue de repli.",
  '%s — fallback': '%s — repli',
  'Language': 'Langue',
  'No text saved yet for %s. The fields show what is sent in this language now (string translation, translated default text or fallback language). Save to keep your own version.': "Aucun texte enregistré pour l'instant en %s. Les champs montrent ce qui est envoyé dans cette langue en ce moment (traduction de chaîne, texte par défaut traduit ou langue de repli). Enregistrez pour garder votre propre version.",
  'Texts — %s': 'Textes — %s',
  'Settings (all languages)': 'Réglages (toutes les langues)',
  'Coupon discount (e.g. 10 %). A paragraph with this tag is removed when there is no coupon.': "Rabais du coupon (ex. 10 %). Un paragraphe qui contient cette balise est retiré quand il n'y a pas de coupon.",
  '%s%%': '%s %%',
  'Your items are still available.': 'Vos articles sont encore disponibles.',
  'Use this code to get {coupon_amount} off your order:': 'Utilisez ce code pour obtenir {coupon_amount} de rabais sur votre commande :',
 },
}
fr_fr = dict(NEW['fr_CA'])
fr_fr.update({
  'Language used for a cart whose language is unknown or no longer active, and for texts that are missing in a language. Languages detected with: %s.': "Langue utilisée pour un panier dont la langue est inconnue ou n'est plus active, et pour les textes absents dans une langue. Langues détectées avec : %s.",
  'Label of the checkbox shown under the email field (consent mode), for each language. Leave a language empty to use, in order: its WPML or Polylang string translation, the default text translated in that language, then the fallback language text.': "Libellé de la case affichée sous le champ e-mail (mode consentement), pour chaque langue. Laissez une langue vide pour utiliser, dans l'ordre : sa traduction de chaîne WPML ou Polylang, le texte par défaut traduit dans cette langue, puis le texte de la langue de repli.",
})
NEW['fr_FR'] = fr_fr
def esc(s): return s.replace('\\', '\\\\').replace('"', '\\"')
def unesc(s): return s.replace('\\"', '"').replace('\\\\', '\\')
missing = []
for loc, tr in NEW.items():
    po = os.path.join(D, f'oli-abandoned-cart-recovery-{loc}.po')
    subprocess.run(['msgmerge', '--quiet', '--no-fuzzy-matching', '--no-wrap', '--update', '--backup=none', po, POT], check=True)
    txt = open(po, encoding='utf-8').read()
    txt = re.sub(r'"Project-Id-Version: [^"]*"', '"Project-Id-Version: Oli Abandoned Cart Recovery 1.1.0\\\\n"', txt, count=1)
    out = []
    for e in txt.split('\n\n'):
        m = re.search(r'^msgid "(.*)"\nmsgstr ""$', e, re.M)
        if m and m.group(1):
            key = unesc(m.group(1))
            if key in tr:
                e = e.replace('msgid "' + m.group(1) + '"\nmsgstr ""', 'msgid "' + m.group(1) + '"\nmsgstr "' + esc(tr[key]) + '"')
            elif not re.search(r'^msgid_plural', e, re.M):
                missing.append((loc, key))
        out.append(e)
    # Retire les entrées obsolètes (#~).
    out = [e for e in out if not e.lstrip().startswith('#~')]
    open(po, 'w', encoding='utf-8').write('\n\n'.join(out).rstrip('\n') + '\n')
    subprocess.run(['msgfmt', '--check', '-o', po[:-3] + '.mo', po], check=True)
    print('OK', loc)
if missing:
    print('MANQUANTS :', missing); sys.exit(1)
