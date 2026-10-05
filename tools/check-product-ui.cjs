const fs = require('fs'), path = require('path'), http = require('http');
const { chromium } = require(process.argv[2]);
const exportsDir = process.argv[3], output = 'docs/branding-review/fiche-produit';
const publicDir = path.resolve('public');
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const file = url.pathname.startsWith('/review/') ? path.join(exportsDir, path.basename(url.pathname)) : path.join(publicDir, decodeURIComponent(url.pathname));
  if (!fs.existsSync(file)) return res.writeHead(404).end();
  const mime = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'application/javascript', '.jpg': 'image/jpeg', '.png': 'image/png' };
  res.setHeader('Content-Type', mime[path.extname(file)] || 'application/octet-stream');
  res.end(fs.readFileSync(file));
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
  const page = await browser.newPage();
  await page.addInitScript(() => localStorage.setItem('ageVerified', 'true'));
  const errors = [], results = [], controls = [];
  page.on('pageerror', err => errors.push(err.message));
  try {
    for (const width of [1440, 1024, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const name of ['produit', 'produit-indisponible']) {
        await page.goto(`http://127.0.0.1:${server.address().port}/review/${name}.html`, { waitUntil: 'networkidle' });
        const check = await page.evaluate(() => {
          const img = document.querySelector('.product-detail-photo img');
          const back = document.querySelector('.product-back-link');
          const button = document.querySelector('.buy-row button[type="submit"]');
          const r = button.getBoundingClientRect();
          return { overflow: document.documentElement.scrollWidth > innerWidth, back: back.textContent.trim(), href: back.getAttribute('href'), backHeight: back.getBoundingClientRect().height,
            imageWidth: img.getBoundingClientRect().width, imageAlt: img.alt, missingImage: !img.complete || img.naturalWidth === 0, buttonHeight: r.height, disabled: button.disabled,
            photoRadius: getComputedStyle(document.querySelector('.product-detail-photo')).borderRadius, buyRadius: getComputedStyle(document.querySelector('.buy-box')).borderRadius };
        });
        results.push({ width, name, ...check });
        await page.screenshot({ path: path.join(output, `${name}-${width}.png`), fullPage: true });
        const input = page.locator('input[name="quantity"]');
        const minus = page.locator('[data-quantity-step="-1"]');
        const plus = page.locator('[data-quantity-step="1"]');
        if (name === 'produit') {
          const maximum = Number(await input.getAttribute('max'));
          const minimumDisabled = await minus.isDisabled();
          await plus.click();
          const increased = await input.inputValue() === '2';
          await input.fill(String(maximum)); await input.dispatchEvent('change');
          const maximumDisabled = await plus.isDisabled();
          await minus.click();
          const decreased = await input.inputValue() === String(maximum - 1);
          await input.fill('0'); await input.dispatchEvent('change');
          const clampedMinimum = await input.inputValue() === '1';
          await input.fill(String(maximum + 10)); await input.dispatchEvent('change');
          const clampedMaximum = await input.inputValue() === String(maximum);
          controls.push({ name: `Quantité ${width}px`, valid: minimumDisabled && increased && maximumDisabled && decreased && clampedMinimum && clampedMaximum });
        } else {
          controls.push({ name: `Rupture ${width}px`, valid: await input.isDisabled() && await minus.isDisabled() && await plus.isDisabled() });
        }
        if (width === 1440) {
          for (const selector of ['.product-back-link', ...(name === 'produit' ? ['.buy-row button[type="submit"]', '[data-quantity-step="-1"]'] : [])]) {
            const el = page.locator(selector).first();
            await page.mouse.move(0, 0); await page.locator('body').click({ position: { x: 1, y: 1 } });
            const read = () => el.evaluate(el => { const s = getComputedStyle(el); return { color: s.color, background: s.backgroundColor, outline: s.outlineWidth, focused: el.matches(':focus-visible') }; });
            await page.waitForTimeout(220); const initial = await read();
            await el.hover(); await page.waitForTimeout(220); const hover = await read();
            await page.mouse.move(0, 0); await page.keyboard.press('Tab'); await el.focus(); await page.waitForTimeout(220); const focus = await read();
            controls.push({ name, selector, initial, hover, focus, valid: focus.focused && parseFloat(focus.outline) >= 2 && (initial.color !== hover.color || initial.background !== hover.background) });
          }
        }
      }
    }
    fs.writeFileSync(path.join(output, 'controles.json'), JSON.stringify({ results, controls, errors }, null, 2));
    const failures = results.filter(r => r.overflow || r.missingImage || !r.imageAlt || r.buttonHeight < 44 || r.backHeight < 44 || r.href !== (r.name === 'produit' ? '/categorie/aux-fruits' : '/catalogue'));
    console.log(JSON.stringify({ pages: results.length, failures, controls, errors }, null, 2));
    if (failures.length || errors.length || controls.some(c => !c.valid)) process.exitCode = 1;
  } finally { await browser.close(); server.close(); }
})().catch(err => { console.error(err); server.close(); process.exitCode = 1; });
