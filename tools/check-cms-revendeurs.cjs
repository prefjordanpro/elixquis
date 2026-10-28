// Uses PHPUnit snapshots and intercepts every request: no server, account or external API.
// node tools/check-cms-revendeurs.cjs <playwright-core> [snapshot-directory]
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { chromium } = require(process.argv[2]);
const snapshots = path.resolve(process.argv[3] || 'var/cms-review/html');
const output = path.resolve('var/cms-review/browser');
const publicRoot = path.resolve('public');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');
const mime = { '.css': 'text/css', '.js': 'application/javascript', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
  const results = [];
  async function prepare(context) {
    await context.addInitScript(() => localStorage.setItem('ageVerified', 'true'));
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.hostname === 'cms.test' && /^\/(home|retailers|admin-home|admin-retailer)$/.test(url.pathname)) {
        return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(snapshots, `${url.pathname.slice(1)}.html`)) });
      }
      if (url.hostname === 'tile.openstreetmap.org') return route.fulfill({ contentType: 'image/png', body: png });
      const local = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
      if (url.hostname === 'cms.test' && local.startsWith(publicRoot + path.sep) && fs.existsSync(local) && fs.statSync(local).isFile()) {
        return route.fulfill({ contentType: mime[path.extname(local)] || 'application/octet-stream', body: fs.readFileSync(local) });
      }
      return route.fulfill({ status: 204, body: '' });
    });
  }
  try {
    const context = await browser.newContext(); await prepare(context);
    const page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    for (const width of [1600, 1280, 1024, 768, 430, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const name of ['home', 'retailers', 'admin-home', 'admin-retailer']) {
        await page.goto(`http://cms.test/${name}`, { waitUntil: 'networkidle' });
        const measure = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
        assert(measure.document <= width + 1, `${name} overflow at ${width}: ${measure.document}`);
        if (name === 'home') {
          if (width < 992) {
            await page.locator('.navbar-toggler').click();
            await page.locator('#navbarCollapse.show').waitFor();
          }
          assert(await page.locator('#navbarCollapse a[href="/nos-revendeurs"]').isVisible(), `Navigation hidden at ${width}`);
          if (width < 992) await page.locator('#navbarCollapse .btn-close').click();
        }
        if (name === 'retailers') {
          assert.equal(await page.locator('.leaflet-marker-icon').count(), 1);
          await page.locator('.retailer-locate').click();
          await page.locator('.leaflet-popup').waitFor();
          const popup = await page.locator('.leaflet-popup').boundingBox();
          assert(popup.x >= 0 && popup.x + popup.width <= width + 1, `Popup overflow at ${width}`);
          await page.locator('#retailers-search').fill('inconnue');
          assert.equal(await page.locator('[data-retailer]:visible').count(), 0);
          await page.locator('#retailers-search').fill('75002');
          assert.equal(await page.locator('[data-retailer]:visible').count(), 2);
          assert.equal(await page.locator('.leaflet-marker-icon').count(), 1);
        }
        if (name === 'admin-home') {
          // Every tab remains reachable at each requested viewport.
          for (const tab of await page.locator('.nav-tabs .nav-link').all()) {
            await tab.click();
            assert(await tab.evaluate(el => el.getAttribute('aria-selected') === 'true'));
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
          }
        }
        await page.screenshot({ path: path.join(output, `${name}-${width}.png`), fullPage: true });
        results.push({ page: name, width, overflow: false });
      }
    }
    assert.deepEqual(errors, [], 'JavaScript errors');
    const noJs = await browser.newContext({ javaScriptEnabled: false }); await prepare(noJs);
    const plain = await noJs.newPage(); await plain.goto('http://cms.test/retailers');
    assert.equal(await plain.locator('[data-retailer]:visible').count(), 2);
    assert(await plain.locator('.retailer-address').first().isVisible());
    results.push({ javaScriptDisabled: true, visibleRetailers: 2 });
    const touch = await browser.newContext({ hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 } });
    await prepare(touch);
    const phone = await touch.newPage(); await phone.goto('http://cms.test/retailers', { waitUntil: 'networkidle' });
    await phone.locator('.leaflet-marker-icon').tap(); await phone.locator('.leaflet-popup').waitFor();
    assert(await phone.locator('.leaflet-popup-content').isVisible());
    results.push({ touch: true, viewport: 390, markerPopup: true });
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify({ checks: results.length, viewports: [1600,1280,1024,768,430,390], javascriptErrors: errors, resultsFile: path.join(output, 'results.json') }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
