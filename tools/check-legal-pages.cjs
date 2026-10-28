// Uses test snapshots; all requests are intercepted, no external service is contacted.
// node tools/check-legal-pages.cjs <playwright-core> [widths]
const fs = require('fs'), path = require('path'), assert = require('assert/strict');
const { chromium } = require(process.argv[2]);
const snapshots = path.resolve('var/legal-review/html'), output = path.resolve('var/legal-review/browser'), root = path.resolve('public');
const mime = { '.css':'text/css', '.js':'application/javascript', '.jpg':'image/jpeg', '.png':'image/png', '.svg':'image/svg+xml', '.woff2':'font/woff2' };
(async () => {
  fs.mkdirSync(output, { recursive:true });
  const browser = await chromium.launch({ executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless:true });
  const results = [], errors = [];
  async function intercept(context) {
    await context.addInitScript(() => localStorage.setItem('ageVerified', 'true'));
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.hostname !== 'legal.test') return route.fulfill({ status:204, body:'' });
      const snapshot = path.resolve(snapshots, url.pathname.slice(1) + '.html');
      if (snapshot.startsWith(snapshots + path.sep) && fs.existsSync(snapshot)) return route.fulfill({ contentType:'text/html', body:fs.readFileSync(snapshot) });
      const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
      if (file.startsWith(root + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) return route.fulfill({ contentType:mime[path.extname(file)] || 'application/octet-stream', body:fs.readFileSync(file) });
      return route.fulfill({ status:204, body:'' });
    });
  }
  try {
    const context = await browser.newContext(); await intercept(context);
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    const screens = fs.readdirSync(snapshots).filter(file => file.endsWith('.html')).map(file => file.slice(0,-5));
    const widths = process.argv[3] ? process.argv[3].split(',').map(Number) : [1600,1280,1024,768,430,390];
    for (const width of widths) {
      await page.setViewportSize({ width, height:1000 });
      for (const screen of screens) {
        await page.goto(`http://legal.test/${screen}`, { waitUntil:'networkidle' });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${screen}: horizontal overflow at ${width}`);
        if (screen === 'footer-published') {
          assert.equal(await page.locator('.footer-grid > div').count(), 4);
          const columns = await page.locator('.footer-grid').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length);
          assert.equal(columns, width >= 992 ? 4 : (width >= 576 ? 2 : 1));
          assert.equal(await page.locator('.legal-pending').count(), 0);
        }
        if (screen === 'legal-edit') {
          assert(await page.locator('trix-editor').isVisible());
          assert.match(await page.locator('trix-editor').innerText(), /989 318 589/);
          assert.equal(await page.locator('button[type="submit"]').filter({ hasText:'Publier' }).isDisabled(), true);
          assert.doesNotMatch(await page.locator('trix-editor').innerText(), /<h2>|<p>/);
        }
        if (screen === 'legal-checkout') {
          const checkbox = page.locator('input[name="accept_terms"]');
          assert.equal(await checkbox.isChecked(), false);
          assert.equal(await checkbox.getAttribute('required'), '');
        }
        await page.screenshot({ path:path.join(output, `${screen}-${width}.png`), fullPage:true });
        results.push({ screen, width, overflow:false });
      }
    }
    const noJs = await browser.newContext({ javaScriptEnabled:false, viewport:{width:390,height:900} }); await intercept(noJs);
    const plain = await noJs.newPage(); await plain.goto('http://legal.test/legal-checkout');
    assert(await plain.locator('input[name="accept_terms"]').isVisible());
    assert.equal(await plain.locator('input[name="accept_terms"]').isChecked(), false);
    results.push({ javaScriptDisabled:true, consentVisible:true });
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify({ screens:screens.length, checks:results.length, widths, javascriptErrors:errors, results:path.join(output,'results.json') },null,2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
