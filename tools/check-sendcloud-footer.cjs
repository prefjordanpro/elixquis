const fs = require('fs'), path = require('path'), http = require('http');
const { chromium } = require(process.argv[2]);
const directory = process.argv[3], mode = process.argv[4] || 'after';
const output = 'docs/branding-review/footer-sendcloud';
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const file = url.pathname.startsWith('/review/') ? path.join(directory, path.basename(url.pathname)) : path.join(process.cwd(), 'public', url.pathname);
  if (!fs.existsSync(file)) return res.writeHead(404).end();
  res.setHeader('Content-Type', { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'application/javascript', '.jpg': 'image/jpeg' }[path.extname(file)] || 'application/octet-stream');
  res.end(fs.readFileSync(file));
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
  const page = await browser.newPage();
  await page.addInitScript(() => localStorage.setItem('ageVerified', 'true'));
  const results = [];
  try {
    const names = fs.readdirSync(directory).filter(name => name.endsWith('.html') && !['administration.html', 'sendcloud-admin.html'].includes(name));
    for (const width of [1440, 768, 390]) for (const height of [900, 1600]) for (const name of names) {
      await page.setViewportSize({ width, height });
      await page.goto(`http://127.0.0.1:${server.address().port}/review/${name}`, { waitUntil: 'networkidle' });
      const metrics = await page.evaluate(() => {
        const body = getComputedStyle(document.body), main = getComputedStyle(document.querySelector('main'));
        const footer = document.querySelector('.site-footer').getBoundingClientRect();
        const bottom = footer.bottom + scrollY;
        const el = document.querySelector('.site-footer');
        const css = getComputedStyle(el);
        return { blankAfterFooter: Math.max(0, Math.max(innerHeight, document.documentElement.scrollHeight) - bottom), footerBottom: bottom, viewportHeight: innerHeight, bodyDisplay: body.display, mainMinHeight: main.minHeight, overflow: document.documentElement.scrollWidth > innerWidth,
          footerCount: document.querySelectorAll('.site-footer').length, complete: !!el.querySelector('.footer-intro') && el.querySelectorAll('.footer-title').length === 2 && !!el.querySelector('.legal-pending') && el.textContent.includes('Vente interdite aux mineurs'), padding: css.padding, margin: css.marginTop };
      });
      results.push({ width, height, name, ...metrics });
      if (height === 900 && ['accueil.html', 'panier.html', 'checkout.html', 'sendcloud-adresse.html', 'confirmation.html'].includes(name)) await page.screenshot({ path: path.join(output, `${mode}-${name}-${width}.png`), fullPage: true });
    }
    fs.writeFileSync(path.join(output, `${mode}.json`), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
    if (mode === 'after' && results.some(r => r.blankAfterFooter > 1 || r.overflow || r.footerCount !== 1 || !r.complete)) process.exitCode = 1;
  } finally { await browser.close(); server.close(); }
})().catch(err => { console.error(err); server.close(); process.exitCode = 1; });
