// Snapshot-based browser checks; every request is intercepted, including external hosts.
// node tools/check-backoffice.cjs <playwright-core>
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { chromium } = require(process.argv[2]);
const root = path.resolve('public');
const input = path.resolve('var/backoffice-review/html');
const output = path.resolve('var/backoffice-review/browser');
const mime = { '.css': 'text/css', '.js': 'application/javascript', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
  const results = [], errors = [];
  try {
    const context = await browser.newContext();
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.hostname !== 'backoffice.test') return route.fulfill({ status: 204, body: '' });
      const snapshot = path.resolve(input, url.pathname.slice(1) + '.html');
      if (snapshot.startsWith(input + path.sep) && fs.existsSync(snapshot)) return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(snapshot) });
      const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
      if (file.startsWith(root + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) return route.fulfill({ contentType: mime[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
      if (url.pathname.startsWith('/uploads/')) return route.fulfill({ contentType: 'image/jpeg', body: fs.readFileSync(path.join(root, 'images/editorial/degustation-ambree-v2.jpg')) });
      return route.fulfill({ status: 204, body: '' });
    });
    let currentScreen = '';
    const page = await context.newPage(); page.on('pageerror', error => errors.push({ screen: currentScreen, message: error.message, stack: error.stack }));
    const screens = fs.readdirSync(input).filter(f => f.endsWith('.html')).map(f => f.slice(0, -5)).filter(name => !process.argv[4] || name === process.argv[4]);
    const widths = process.argv[3] ? process.argv[3].split(',').map(Number) : [1600, 1280, 1024, 768, 430, 390];
    for (const width of widths) {
      await page.setViewportSize({ width, height: 1000 });
      for (const screen of screens) {
        currentScreen = screen;
        const hash = screen === 'product-edit' ? '#Product_promotionSelection' : '';
        await page.goto(`http://backoffice.test/${screen}${hash}`, { waitUntil: 'networkidle' });
        const size = await page.evaluate(() => ({ width: innerWidth, document: document.documentElement.scrollWidth }));
        if (size.document > width + 1) console.log(await page.evaluate(() => [...document.querySelectorAll('body *')].map(el => ({ tag: el.tagName, class: el.className, right: el.getBoundingClientRect().right, width: el.getBoundingClientRect().width, min: getComputedStyle(el).minWidth, max: getComputedStyle(el).maxWidth, overflow: getComputedStyle(el).overflowX })).filter(el => el.right > innerWidth + 1).slice(0, 15)));
        assert(size.document <= width + 1, `${screen}: overflow ${size.document} at ${width}`);
        assert(await page.locator('.content-header').isVisible(), `${screen}: heading hidden`);
        if (screen === 'dashboard' && await page.locator('#navigation-toggler').isVisible()) {
          await page.locator('#navigation-toggler').click();
          assert(await page.locator('#main-menu').getByText('Stock', { exact: true }).isVisible(), `Stock navigation hidden at ${width}`);
          await page.locator('.modal-backdrop').click({ position: { x: width - 5, y: 20 } });
        }
        if (screen === 'product-edit') assert(await page.locator('#Product_promotionSelection').isVisible(), `Promotion tab not opened at ${width}`);
        if (screen === 'shipping') {
          const mode = page.locator('[data-commercial-shipping-mode]'), threshold = page.locator('[data-commercial-shipping-threshold]');
          for (const value of ['disabled', 'all', 'threshold']) { await mode.selectOption(value); assert.equal(await threshold.isVisible(), value === 'threshold'); }
        }
        if (screen === 'code') {
          await page.locator('[data-promo-code-type]').selectOption('fixed');
          assert.match(await page.locator('[data-promo-code-value] label').textContent(), /€/);
          await page.locator('[data-promo-code-type]').selectOption('percent');
          assert.match(await page.locator('[data-promo-code-value] label').textContent(), /%/);
        }
        if (screen === 'product-new' || screen === 'home-editor') {
          for (const tab of await page.locator('.nav-tabs .nav-link').all()) { await tab.click(); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)); }
        }
        if (screen === 'product-new') {
          await page.locator('.nav-tabs .nav-link').first().click();
          await page.locator('[data-image-replacement]').setInputFiles(path.join(root, 'images/editorial/degustation-ambree-v2.jpg'));
          assert(await page.locator('[data-new-image-preview]').isVisible(), 'New image preview missing');
        }
        if (screen === 'product-existing') {
          assert.match(await page.locator('trix-editor').innerText(), /Recette artisanale aux fruits/);
          assert.doesNotMatch(await page.locator('trix-editor').innerText(), /<div>|<strong>/);
        }
        await page.screenshot({ path: path.join(output, `${screen}-${width}.png`), fullPage: true });
        results.push({ screen, width, overflow: false });
      }
    }
    assert.deepEqual(errors, [], 'Unexpected JavaScript errors');
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify({ screens: screens.length, checks: results.length, widths, javascriptErrors: errors, results: path.join(output, 'results.json') }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
