const fs = require('fs'), path = require('path'), http = require('http');
const { chromium } = require(process.argv[2]);
const exportsDir = process.argv[3], output = 'docs/packaging-review';
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const file = url.pathname.startsWith('/review/') ? path.join(exportsDir, path.basename(url.pathname)) : path.join(process.cwd(), 'public', url.pathname);
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return res.writeHead(404).end();
  res.setHeader('Content-Type', { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'application/javascript', '.jpg': 'image/jpeg' }[path.extname(file)] || 'application/octet-stream');
  res.end(fs.readFileSync(file));
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
  try {
    const page = await browser.newPage(); const results = [];
    await page.addInitScript(() => localStorage.setItem('ageVerified', 'true'));
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const name of ['emballages-liste', 'emballage-formulaire', 'commande-multicolis', 'checkout-colisage']) {
        await page.goto(`http://127.0.0.1:${server.address().port}/review/${name}.html`, { waitUntil: 'networkidle' });
        await page.screenshot({ path: path.join(output, `${name}-${width}.png`), fullPage: true });
        results.push({ name, width, overflow: await page.evaluate(() => document.documentElement.scrollWidth > innerWidth) });
      }
    }
    fs.writeFileSync(path.join(output, 'controles.json'), JSON.stringify({ mode: 'HTML réellement rendu par Symfony, API et base de test isolées', results }, null, 2));
    console.log(JSON.stringify(results));
    if (results.some(r => r.overflow)) process.exitCode = 1;
  } finally { await browser.close(); server.close(); }
})().catch(e => { console.error(e.message); server.close(); process.exitCode = 1; });
