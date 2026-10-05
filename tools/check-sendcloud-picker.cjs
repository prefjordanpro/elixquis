const fs = require('fs'), path = require('path'), http = require('http');
const { chromium } = require(process.argv[2]);
const exportsDir = process.argv[3], output = 'docs/branding-review/picker';
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const file = url.pathname.startsWith('/review/') ? path.join(exportsDir, path.basename(url.pathname)) : path.join(process.cwd(), 'public', url.pathname);
  if (!fs.existsSync(file)) return res.writeHead(404).end();
  res.setHeader('Content-Type', { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'application/javascript', '.jpg': 'image/jpeg' }[path.extname(file)] || 'application/octet-stream');
  res.end(fs.readFileSync(file));
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
  const page = await browser.newPage(); const results = [], errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.addInitScript(() => {
    localStorage.setItem('ageVerified', 'true');
    // Interface officielle simulée : tester nos callbacks sans créer d'envoi réel.
    window.sendcloud = { servicePoints: { open(config, success, failure) { window.picker = { config, success, failure }; }, close() {} } };
  });
  await page.route('**/commande/sendcloud', async route => {
    const data = route.request().postData() || '';
    const id = /name="point"\r?\n\r?\n84(?:\r?\n)/.test(data) ? 84 : 42;
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ point: { id, name: id === 42 ? 'Relais initial' : 'Nouveau relais', address: { street: 'Rue du Relais', house_number: '2', postal_code: '75001', city: 'Paris', country_code: 'FR' }, carrier: { code: 'mondial_relay' }, post_number: '' } }) });
  });
  try {
    for (const width of [1440, 1024, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(`http://127.0.0.1:${server.address().port}/review/sendcloud-relais.html`, { waitUntil: 'networkidle' });
      const button = page.locator('[data-open-picker]');
      await button.click();
      const opened = await page.evaluate(() => window.picker.config.carriers === 'mondial_relay' && window.picker.config.language === 'fr-fr');
      await page.evaluate(() => window.picker.success({ id: 42, name: '<script>FAUX</script>' }, ''));
      await page.waitForFunction(() => document.querySelector('[name="point"]').value === '42' || (document.querySelector('[data-picker-status]').textContent && document.querySelector('[data-picker-status]').textContent !== 'Vérification du point relais…'));
      if (await page.locator('[name="point"]').inputValue() !== '42') throw new Error(await page.locator('[data-picker-status]').textContent());
      const selected = await page.locator('[data-point-name]').textContent() === 'Relais initial';
      await button.click();
      const recentered = await page.evaluate(() => window.picker.config.servicePointId === 42);
      await page.evaluate(() => window.picker.success({ id: 84 }, ''));
      await page.waitForFunction(() => document.querySelector('[name="point"]').value === '84');
      const changed = await page.locator('[data-point-name]').textContent() === 'Nouveau relais';
      await button.click(); await page.evaluate(() => window.picker.failure(['Closed']));
      const preserved = await page.locator('[name="point"]').inputValue() === '84';
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
      results.push({ width, opened, selected, recentered, changed, preserved, overflow });
      await page.screenshot({ path: path.join(output, `selection-${width}.png`), fullPage: true });
      await page.goto(`http://127.0.0.1:${server.address().port}/review/sendcloud-relais-selectionne.html`, { waitUntil: 'networkidle' });
      await page.reload();
      if (await page.locator('[name="point"]').inputValue() !== '42') throw new Error('Sélection non restaurée');
    }
    fs.writeFileSync(path.join(output, 'controles.json'), JSON.stringify({ mode: 'callbacks picker et réponse serveur simulés ; validations API couvertes par PHPUnit', results, errors }, null, 2));
    console.log(JSON.stringify({ results, errors }, null, 2));
    if (errors.length || results.some(r => !r.opened || !r.selected || !r.recentered || !r.changed || !r.preserved || r.overflow)) process.exitCode = 1;
  } finally { await browser.close(); server.close(); }
})().catch(e => { console.error(e); server.close(); process.exitCode = 1; });
