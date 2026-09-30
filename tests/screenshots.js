// Captures d'écran de l'admin et des courriels (box locale seulement).
const { chromium } = require('playwright-core');
const B = 'http://localhost:8888';
const MP = 'http://127.0.0.1:8025';
const OUT = process.argv[2] || require('path').join(process.cwd(), 'screenshots');

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.HOME + '/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
  const page = await ctx.newPage();
  await page.goto(B + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
  const shots = [
    ['admin-1-tableau-de-bord', '/wp-admin/admin.php?page=oli-acr&tab=dashboard&period=30'],
    ['admin-2-paniers', '/wp-admin/admin.php?page=oli-acr&tab=carts'],
    ['admin-3-modele-courriel', '/wp-admin/admin.php?page=oli-acr&tab=templates&edit=tpl_cart_2'],
    ['admin-4-reglages', '/wp-admin/admin.php?page=oli-acr&tab=settings'],
    ['admin-5-journal', '/wp-admin/admin.php?page=oli-acr&tab=log'],
    ['admin-6-commandes-en-attente', '/wp-admin/admin.php?page=oli-acr&tab=pending'],
    ['admin-7-recuperes', '/wp-admin/admin.php?page=oli-acr&tab=recovered'],
    ['admin-8-modeles', '/wp-admin/admin.php?page=oli-acr&tab=templates'],
    ['admin-9-courriel-admin-woocommerce', '/wp-admin/admin.php?page=wc-settings&tab=email&section=oli_acr_email_admin_recovered'],
  ];
  for (const [name, url] of shots) {
    await page.goto(B + url, { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: '.woocommerce-layout__header, #wpadminbar .ab-top-secondary{display:none!important} .notice:not(.oli-acr-wrap .notice){display:none!important}' }).catch(() => {});
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
    console.log('ok', name);
  }
  // Courriels : rendu HTML depuis Mailpit.
  const list = await (await fetch(MP + '/api/v1/messages?limit=200')).json();
  const pick = (re) => list.messages.find(m => re.test(m.Subject) && (re.source.startsWith('^\\[Test') || !/^\[Test\]/.test(m.Subject)));
  const mails = [
    ['courriel-1-relance-1', pick(/oublié quelque chose|something in your cart/)],
    ['courriel-2-relance-2-coupon', pick(/petit quelque chose|little something/)],
    ['courriel-3-commande-en-attente', pick(/attend son paiement|awaiting payment/)],
    ['courriel-4-avis-admin', pick(/Vente récupérée|Recovered sale/)],
    ['courriel-5-test', pick(/^\[Test\]/)],
  ];
  for (const [name, m] of mails) {
    if (!m) { console.log('absent', name); continue; }
    const p2 = await ctx.newPage();
    await p2.setViewportSize({ width: 800, height: 900 });
    await p2.goto(`${MP}/view/${m.ID}.html`, { waitUntil: 'networkidle' });
    await p2.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
    await p2.close();
    console.log('ok', name, '-', m.Subject);
  }
  // Page de désabonnement.
  const u = list.messages.find(m => /oublié|something/.test(m.Subject));
  if (u) {
    const full = await (await fetch(`${MP}/api/v1/message/${u.ID}`)).json();
    const link = (full.HTML.match(/href="([^"]*oli_acr_unsub[^"]*)"/) || [])[1];
    if (link) {
      const p3 = await ctx.newPage();
      await p3.goto(link.replace(/&amp;/g, '&'));
      await p3.screenshot({ path: `${OUT}/front-desabonnement.png` });
      console.log('ok front-desabonnement');
    }
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
