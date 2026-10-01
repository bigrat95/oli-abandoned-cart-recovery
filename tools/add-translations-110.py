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
 'Capture guest carts': 'Capter les paniers des invités',
 'Require consent': 'Exiger le consentement',
 'Show a consent checkbox at checkout and save an email and cart (guests and logged-in customers) only when it is checked (recommended, on by default)': "Afficher une case de consentement au paiement et n'enregistrer un courriel et un panier (invités et clients connectés) que si elle est cochée (recommandé, activé par défaut)",
 'Consent is turned off.': 'Le consentement est désactivé.',
 'Emails and carts of guests and logged-in customers are saved without consent. Quebec Law 25 and the GDPR generally require explicit consent before saving this data for marketing reminders. By turning consent off, you, the site owner, are responsible for having another legal basis.': "Les courriels et les paniers des invités et des clients connectés sont enregistrés sans consentement. La Loi 25 (Québec) et le RGPD exigent généralement un consentement explicite avant d'enregistrer ces données pour des relances marketing. En désactivant le consentement, vous, propriétaire du site, êtes responsable d'avoir une autre base légale.",
 'Turning this off saves the data of guests and logged-in customers without consent: you then become responsible for compliance with Quebec Law 25 and the GDPR.': 'Si vous le désactivez, les données des invités et des clients connectés sont enregistrées sans consentement : vous devenez alors responsable du respect de la Loi 25 (Québec) et du RGPD.',
 'Empty field: %s': 'Champ vide : %s',
 'Label of the consent checkbox shown under the email field, for each language. Links (for example to your privacy policy), bold and italic are allowed. Leave a language empty to use, in order: its WPML or Polylang string translation, the default text translated in that language, then the fallback language text.': "Libellé de la case de consentement affichée sous le champ courriel, pour chaque langue. Les liens (par exemple vers votre politique de confidentialité), le gras et l'italique sont permis. Laissez une langue vide pour utiliser, dans l'ordre : sa traduction de chaîne WPML ou Polylang, le texte par défaut traduit dans cette langue, puis le texte de la langue de repli.",
 'Oli Abandoned Cart Recovery: consent is turned off.': 'Oli Abandoned Cart Recovery : le consentement est désactivé.',
 'Emails and carts of guests and logged-in customers are saved without asking for consent. Quebec Law 25 and the GDPR generally require explicit consent before saving this data for marketing reminders. You are responsible for having another legal basis.': "Les courriels et les paniers des invités et des clients connectés sont enregistrés sans demander leur consentement. La Loi 25 (Québec) et le RGPD exigent généralement un consentement explicite avant d'enregistrer ces données pour des relances marketing. Vous êtes responsable d'avoir une autre base légale.",
 'Turn consent back on': 'Réactiver le consentement',
 'Oli Abandoned Cart Recovery 1.1.0: consent is now required.': 'Oli Abandoned Cart Recovery 1.1.0 : le consentement est maintenant exigé.',
 'Your store saved guest carts without consent ("Always" mode). To comply with Quebec Law 25 and the GDPR, the update turned consent on: guests and logged-in customers are now tracked only when they check the consent box at checkout. You can turn it off again in the settings, under your own responsibility.': "Votre boutique enregistrait les paniers des invités sans consentement (mode « Toujours »). Pour respecter la Loi 25 (Québec) et le RGPD, la mise à jour a activé le consentement : les invités et les clients connectés ne sont maintenant suivis que s'ils cochent la case de consentement au paiement. Vous pouvez le désactiver de nouveau dans les réglages, sous votre responsabilité.",
 'Review the settings': 'Vérifier les réglages',
 'Dismiss': 'Fermer',
 'Oli Abandoned Cart Recovery: some reminders could not be sent.': "Oli Abandoned Cart Recovery : certaines relances n'ont pas pu être envoyées.",
 '%1$d failure(s), last one on %2$s:': '%1$d échec(s), le dernier le %2$s :',
 'Failed reminders are retried automatically. Check your mail settings (SMTP).': "Les relances en échec sont réessayées automatiquement. Vérifiez vos réglages d'envoi (SMTP).",
 'Failed carts': 'Paniers en échec',
 'Logs': 'Journaux',
 'Unknown notice.': 'Avis inconnu.',
 '%1$d failed attempt(s), next attempt: %2$s': '%1$d essai(s) en échec, prochain essai : %2$s',
 '%d failed attempt(s), no further attempt. Use "Send next reminder now" after fixing your mail settings.': "%d essai(s) en échec, aucun autre essai. Utilisez « Envoyer la prochaine relance maintenant » après avoir corrigé vos réglages d'envoi.",
 'Leave this field empty': 'Laissez ce champ vide',
 'wp_mail() returned false (no error message was given by the mailer).': "wp_mail() a renvoyé false (aucun message d'erreur fourni par le service d'envoi).",
 'Unknown error': 'Erreur inconnue',
 'Reminder for cart #%1$d (template %2$s) could not be sent, attempt %3$d: %4$s. Next attempt: %5$s.': "La relance du panier nº %1$d (modèle %2$s) n'a pas pu être envoyée, essai %3$d : %4$s. Prochain essai : %5$s.",
 'none (gave up)': 'aucun (abandon)',
 'Reminder for pending order #%1$d (template %2$s) could not be sent, attempt %3$d: %4$s. Next attempt: %5$s.': "La relance de la commande en attente nº %1$d (modèle %2$s) n'a pas pu être envoyée, essai %3$d : %4$s. Prochain essai : %5$s.",
 'Send failed': "Échec d'envoi",
 },
}
fr_fr = dict(NEW['fr_CA'])
fr_fr.update({
  'Language used for a cart whose language is unknown or no longer active, and for texts that are missing in a language. Languages detected with: %s.': "Langue utilisée pour un panier dont la langue est inconnue ou n'est plus active, et pour les textes absents dans une langue. Langues détectées avec : %s.",
  'Label of the checkbox shown under the email field (consent mode), for each language. Leave a language empty to use, in order: its WPML or Polylang string translation, the default text translated in that language, then the fallback language text.': "Libellé de la case affichée sous le champ e-mail (mode consentement), pour chaque langue. Laissez une langue vide pour utiliser, dans l'ordre : sa traduction de chaîne WPML ou Polylang, le texte par défaut traduit dans cette langue, puis le texte de la langue de repli.",
})
for _k, _v in list(fr_fr.items()):
    fr_fr[_k] = _v.replace('un courriel', 'un e-mail').replace('Les courriels', 'Les e-mails').replace('champ courriel', 'champ e-mail').replace('nº', 'n°')
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
