// Visiteur réel (Chromium sans tête) pour les tests R6 et consentement de la 1.1.0.
// Env : PRODUCT_URL, CHECKOUT_URL, EMAIL, BAD_NONCE (1 = nonce de la page remplacé par un nonce périmé),
//       CHECK (1 = cocher la case), LOGIN_USER / LOGIN_PASS (client connecté), BASE. Sortie : une ligne JSON.
const { chromium } = require('playwright-core');
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || (process.env.HOME + '/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome') });
  const page = await (await browser.newContext()).newPage();
  const caps = [];
  page.on('response', r => { if (r.url().includes('oli_acr_capture') || r.url().includes('oli_acr_nonce')) caps.push((r.url().includes('oli_acr_nonce') ? 'nonce:' : 'capture:') + r.status()); });
  if (process.env.LOGIN_USER) {
    await page.goto(process.env.BASE + '/wp-login.php');
    await page.fill('#user_login', process.env.LOGIN_USER);
    await page.fill('#user_pass', process.env.LOGIN_PASS);
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');
  }
  await page.goto(process.env.PRODUCT_URL, { waitUntil: 'networkidle' });
  await page.click('.single_add_to_cart_button');
  await page.waitForLoadState('networkidle');
  await page.goto(process.env.CHECKOUT_URL, { waitUntil: 'networkidle' });
  const emailSel = '#email, #billing_email';
  await page.waitForSelector(emailSel, { timeout: 20000 });
  if (process.env.BAD_NONCE === '1') {
    await page.evaluate(() => { window.oliAcrCapture.nonce = '0123456789'; });
  }
  await page.waitForTimeout(1500);
  const consent = page.locator('#oli_acr_consent, input[type="checkbox"][id*="oli-acr"]').first();
  const hasBox = (await consent.count()) > 0;
  let labelHtml = '';
  if (hasBox) {
    labelHtml = await consent.evaluate(el => {
      const lab = document.querySelector('label[for="' + el.id + '"]') || el.closest('label') || el.parentElement;
      const span = lab.querySelector('.wc-block-components-checkbox__label');
      return (span || lab).innerHTML;
    });
  }
  const cur = await page.inputValue(emailSel).catch(() => '');
  if (process.env.EMAIL && cur !== process.env.EMAIL) {
    await page.fill(emailSel, process.env.EMAIL);
  }
  await page.locator(emailSel).first().blur();
  if (hasBox && process.env.CHECK === '1') {
    await consent.check();
  }
  await page.waitForTimeout(3500);
  console.log(JSON.stringify({ hasBox, labelHtml, caps }));
  await browser.close();
})().catch(e => { console.log(JSON.stringify({ error: String(e) })); process.exit(1); });
