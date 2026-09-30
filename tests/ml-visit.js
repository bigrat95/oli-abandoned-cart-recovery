// Visiteur réel (Chromium sans tête, non connecté) pour les tests multilingues.
// Env : PRODUCT_URL, CHECKOUT_URL, EMAIL, CHROME. Sortie : une ligne JSON.
const { chromium } = require('playwright-core');
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || (process.env.HOME + '/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome') });
  const page = await (await browser.newContext()).newPage();
  const caps = [];
  page.on('request', r => { if (r.url().includes('oli_acr_capture')) caps.push(r.postData() || ''); });
  await page.goto(process.env.PRODUCT_URL, { waitUntil: 'networkidle' });
  await page.click('.single_add_to_cart_button');
  await page.waitForLoadState('networkidle');
  await page.goto(process.env.CHECKOUT_URL, { waitUntil: 'networkidle' });
  const emailSel = '#email, #billing_email';
  await page.waitForSelector(emailSel, { timeout: 20000 });
  const htmlLang = await page.evaluate(() => document.documentElement.lang);
  const cfgLang = await page.evaluate(() => (window.oliAcrCapture || {}).lang || '');
  await page.fill(emailSel, process.env.EMAIL);
  await page.locator(emailSel).first().blur();
  const consent = page.locator('#oli_acr_consent, input[type="checkbox"][id*="oli-acr"]').first();
  let label = '';
  if (await consent.count()) {
    const id = await consent.getAttribute('id');
    label = (await page.locator(`label[for="${id}"]`).first().textContent().catch(() => '')) || '';
    if (!label) label = await consent.evaluate(el => (el.closest('label, .wc-block-components-checkbox, p') || el.parentElement).textContent);
    await consent.check();
  }
  await page.waitForTimeout(3000);
  const langs = caps.map(c => (c.match(/name="lang"\r\n\r\n([^\r]*)/) || [])[1] || '');
  console.log(JSON.stringify({ htmlLang, cfgLang, label: label.trim(), captures: caps.length, langs }));
  await browser.close();
})().catch(e => { console.log(JSON.stringify({ error: String(e) })); process.exit(1); });
