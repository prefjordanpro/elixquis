// Browser review of SQLite test exports only. All external requests are blocked.
// node tools/check-invoicing.cjs <absolute-playwright-core-path>
const fs = require('fs'), path = require('path'), assert = require('assert/strict');
const { chromium } = require(process.argv[2]);
const root = path.resolve('public'), input = path.resolve('var/invoice-review/html'), output = path.resolve('var/invoice-review/browser');
const mime = {'.css':'text/css', '.js':'application/javascript', '.png':'image/png', '.jpg':'image/jpeg', '.svg':'image/svg+xml', '.woff2':'font/woff2', '.pdf':'application/pdf'};
(async () => {
  fs.mkdirSync(output, {recursive:true});
  const browser = await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless:true});
  const checks = [], errors = [];
  try {
    const context = await browser.newContext();
    await context.addInitScript(() => localStorage.setItem('ageVerified', 'true'));
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.hostname !== 'invoice.test') return route.fulfill({status:204, body:''});
      const snapshot = path.resolve(input, url.pathname.slice(1) + '.html');
      if (snapshot.startsWith(input + path.sep) && fs.existsSync(snapshot)) return route.fulfill({contentType:'text/html', body:fs.readFileSync(snapshot)});
      const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
      if (file.startsWith(root + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) return route.fulfill({contentType:mime[path.extname(file)] || 'application/octet-stream', body:fs.readFileSync(file)});
      return route.fulfill({status:204, body:''});
    });
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    for (const width of [1440,768,390,320]) {
      await page.setViewportSize({width, height:1000});
      for (const screen of ['admin-invoices','admin-invoice','customer-orders','customer-order']) {
        await page.goto('http://invoice.test/' + screen, {waitUntil:'networkidle'});
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${screen}: overflow at ${width}`);
        assert.match(await page.locator('body').innerText(), /FAC-\d{4}-\d{6}|Télécharger ma facture/);
        if (screen === 'admin-invoice') assert.equal(await page.locator('.content-body form').count(), 0);
        await page.screenshot({path:path.join(output, `${screen}-${width}.png`), fullPage:true});
        checks.push({screen,width,overflow:false});
      }
    }
    await page.setViewportSize({width:794,height:1123});
    await page.goto('http://invoice.test/invoice-pdf', {waitUntil:'networkidle'});
    assert.match(await page.locator('body').innerText(), /FACTURE/);
    assert.match(await page.locator('body').innerText(), /Reste à payer : 0,00/);
    await page.screenshot({path:path.join(output,'invoice-a4-html.png'),fullPage:true});
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output,'results.json'), JSON.stringify({checks, pdfHtml:true, javascriptErrors:errors},null,2));
    console.log(JSON.stringify({checks:checks.length+1,widths:[1440,768,390,320],javascriptErrors:errors}));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
