// Test navigateur sans tête : capture du courriel au checkout classique et en blocs.
const { chromium } = require('playwright-core');
const B = process.env.OLI_ACR_E2E_URL || 'http://localhost:8888';
const SHOTS = process.env.OLI_ACR_E2E_SHOTS || require('os').tmpdir();
const mode = process.argv[2] || 'both'; // classic | blocks | both
const suffix = process.argv[3] || '';

async function run(kind, email, phone) {
  const browser = await chromium.launch({ executablePath: process.env.HOME + '/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1100 } });
  const page = await ctx.newPage();
  const requests = [];
  page.on('request', r => { if (r.url().includes('oli_acr_capture')) requests.push(r.postData() ? 'POST' : 'GET'); });
  await page.goto(B + '/?add-to-cart=12', { waitUntil: 'load' });
  await page.goto(B + '/?add-to-cart=10', { waitUntil: 'load' });
  if (kind === 'classic') {
    await page.goto(B + '/checkout-classique/', { waitUntil: 'networkidle' });
    await page.fill('#billing_email', email);
    if (phone) await page.fill('#billing_phone', phone);
    await page.click('#billing_first_name');
  } else {
    await page.goto(B + '/checkout/', { waitUntil: 'networkidle' });
    await page.waitForSelector('#email', { timeout: 20000 });
    await page.fill('#email', email);
    await page.locator('#email').blur();
    if (phone) {
      const ph = page.locator('#billing-phone, #shipping-phone').first();
      if (await ph.count()) { await ph.fill(phone); await ph.blur(); }
    }
  }
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${SHOTS}/checkout-${kind}${suffix}.png`, fullPage: false });
  console.log(kind, 'capture requests:', requests.length);
  await browser.close();
}

(async () => {
  if (mode === 'classic' || mode === 'both') await run('classic', process.env.EMAIL_C || 'visiteur.classique@example.com', process.env.PHONE || '');
  if (mode === 'blocks' || mode === 'both') await run('blocks', process.env.EMAIL_B || 'visiteur.blocs@example.com', process.env.PHONE || '');
})().catch(e => { console.error(e); process.exit(1); });
