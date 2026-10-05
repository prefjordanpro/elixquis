/* Revue locale de pages exportées par UxPresentationTest, sans services externes.
 * node tools/check-branding.cjs <exports> <sortie> <module-playwright-core> [before]
 * Le dernier argument sert les CSS du HEAD initial pour les captures avant.
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');
const [exportsDir, outputDir, playwrightModule, before] = process.argv.slice(2);
if (!exportsDir || !outputDir || !playwrightModule) throw new Error('Arguments : exports, sortie, module playwright-core');
const { chromium } = require(path.resolve(playwrightModule));
const root = path.resolve(__dirname, '..');
const mime = { '.css': 'text/css', '.js': 'application/javascript', '.jpg': 'image/jpeg', '.png': 'image/png', '.svg': 'image/svg+xml', '.html': 'text/html; charset=utf-8' };
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  let filename;
  if (url.pathname.startsWith('/review/')) filename = path.resolve(exportsDir, path.basename(url.pathname));
  else filename = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
  if (!filename.startsWith(path.resolve(exportsDir) + path.sep) && !filename.startsWith(path.join(root, 'public') + path.sep)) { res.writeHead(403).end(); return; }
  if (!fs.existsSync(filename)) { res.writeHead(404).end(); return; }
  res.setHeader('Content-Type', mime[path.extname(filename)] || 'application/octet-stream');
  if (before && filename.endsWith('.css')) {
    const relative = path.relative(root, filename).split(path.sep).join('/');
    res.end(execFileSync('git', ['show', 'HEAD:' + relative], { cwd: root }));
  } else res.end(fs.readFileSync(filename));
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  fs.mkdirSync(outputDir, { recursive: true });
  const base = `http://127.0.0.1:${server.address().port}`;
  const executablePath = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe'].find(fs.existsSync);
  const browser = await chromium.launch({ executablePath, headless: true });
  const context = await browser.newContext();
  await context.addInitScript(() => localStorage.setItem('ageVerified', 'true'));
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const results = [];
  try {
    const names = fs.readdirSync(exportsDir).filter(name => name.endsWith('.html') && name !== 'administration.html' && name !== 'sendcloud-admin.html');
    for (const width of [1440, 1024, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const name of names) {
        await page.goto(`${base}/review/${name}`, { waitUntil: 'load' });
        // Les diapositives masquées restent normalement en lazy loading.
        // Charger leurs sources pour distinguer une image différée d'un fichier absent.
        await page.evaluate(async () => {
          await Promise.all([...document.images].map(async img => {
            img.loading = 'eager';
            try { await img.decode(); } catch {}
          }));
        });
        const check = await page.evaluate(() => ({
          overflow: document.documentElement.scrollWidth > innerWidth,
          missingImages: [...document.images].filter(img => !img.complete || img.naturalWidth === 0).length,
          title: document.querySelector('main h1')?.textContent,
          headerBackground: getComputedStyle(document.querySelector('.site-header')).backgroundColor,
          headerHeight: Math.round(document.querySelector('.site-header').getBoundingClientRect().height),
          editorialLayout: [...document.querySelectorAll('.story-band, .brand-story')].map(section => {
            const photo = section.querySelector('.editorial-photo img').getBoundingClientRect();
            const copy = section.querySelector('.editorial-copy').getBoundingClientRect();
            const grid = section.querySelector('.editorial-grid').getBoundingClientRect();
            const gap = parseFloat(getComputedStyle(section.querySelector('.editorial-grid')).columnGap);
            const desktop = innerWidth >= 1024;
            return { section: section.className, sectionWidth: grid.width, imageWidth: photo.width, textWidth: copy.width, imageHeight: photo.height, imageShare: photo.width / grid.width, columnGap: gap,
              valid: (desktop ? grid.width <= 1181 && photo.width <= 521 && photo.width / grid.width < .5 && photo.height >= 339 && photo.height <= 421 && gap >= 48 && gap <= 72 : photo.height <= 361) && (innerWidth < 768 ? copy.bottom <= photo.top : section.classList.contains('story-band') ? photo.right <= copy.left : copy.right <= photo.left) };
          }),
          heroLayout: (() => {
            const copy = document.querySelector('.hero-copy'), stage = document.querySelector('.hero-stage');
            if (!copy || !stage) return null;
            const c = copy.getBoundingClientRect(), s = stage.getBoundingClientRect();
            const image = document.querySelector('.hero-image').getBoundingClientRect();
            return { imageWidth: Math.round(image.width), imageHeight: Math.round(image.height),
              valid: innerWidth >= 992 ? s.left >= c.right - 1 && s.width >= (c.width + s.width) * .55 : s.top >= c.bottom - 1 && s.top - c.bottom <= 2 };
          })(),
          overflowing: [...document.querySelectorAll('main *, header *')].filter(el => {
            const r = el.getBoundingClientRect(); return r.width > 0 && r.right > innerWidth + 1;
          }).slice(0, 6).map(el => el.className),
        }));
        results.push({ name, width, ...check });
        if (['accueil.html', 'accueil-carrousel.html', 'catalogue.html', 'produit.html', 'checkout.html', 'sendcloud-relais.html', 'compte-commandes.html', 'panier.html'].includes(name) && (['accueil.html', 'accueil-carrousel.html'].includes(name) || [1440, 390].includes(width))) {
          await page.screenshot({ path: path.join(outputDir, `${path.basename(name, '.html')}-${width}.png`), fullPage: true });
          if (name === 'accueil-carrousel.html') await page.locator('.home-hero').screenshot({ path: path.join(outputDir, `hero-${width}.png`) });
        }
      }
    }
    const controls = [];
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(`${base}/review/accueil.html`);
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    const style = el => { const s = getComputedStyle(el); return [s.backgroundColor, s.color, s.borderBottomColor, s.boxShadow].join('|'); };
    for (const selector of ['.hero-copy .btn-brand', '.section-heading .btn-outline-secondary', '.story-band .btn-outline-primary', '.brand-story .editorial-link', '.product-discover', '.category-tile']) {
      const el = page.locator(selector).first();
      await page.mouse.move(0, 0);
      await el.scrollIntoViewIfNeeded();
      await page.waitForTimeout(250);
      const normal = await el.evaluate(style);
      await el.hover(); await page.waitForTimeout(250);
      const hovered = await el.evaluate(style);
      await page.mouse.move(0, 0);
      await page.keyboard.press('Tab'); await el.focus(); await page.waitForTimeout(250);
      const focused = await el.evaluate(style);
      controls.push({ name: `Survol/focus ${selector}`, passed: normal !== hovered && normal !== focused && await el.evaluate(el => el.matches(':focus-visible') && getComputedStyle(el).outlineStyle !== 'none') });
      await el.evaluate(el => el.blur());
    }
    await page.setViewportSize({ width: 320, height: 1000 });
    await page.goto(`${base}/review/catalogue.html`);
    await page.locator('[data-product-search]').fill('aucun-resultat-xyz');
    controls.push({ name: 'Recherche vide', passed: await page.locator('[data-product-empty]').isVisible() });
    await page.locator('[data-filter-reset]').click();
    controls.push({ name: 'Réinitialisation filtre', passed: await page.locator('[data-product-search]').inputValue() === '' && await page.locator('[data-product]').first().isVisible() });
    await page.goto(`${base}/review/accueil.html`);
    await page.locator('.navbar-toggler').click();
    controls.push({ name: 'Menu mobile', passed: await page.locator('#navbarCollapse').isVisible() });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    controls.push({ name: 'Transitions réduites', passed: await page.locator('.btn').first().evaluate(el => getComputedStyle(el).transitionDuration === '0s') });
    if (fs.existsSync(path.join(exportsDir, 'accueil-carrousel.html'))) {
      await page.goto(`${base}/review/accueil-carrousel.html`);
      controls.push({ name: 'Indicateurs fins sans carré parasite', passed: await page.locator('.carousel-indicators button').first().evaluate(el => getComputedStyle(el).backgroundClip === 'padding-box') });
      await page.waitForTimeout(5500);
      controls.push({ name: 'Carrousel immobile en mouvement réduit', passed: await page.locator('.carousel-item').first().evaluate(el => el.classList.contains('active')) });
      await page.locator('.carousel-control-next').click();
      await page.waitForTimeout(300);
      controls.push({ name: 'Flèche du carrousel', passed: await page.locator('.carousel-item').nth(1).evaluate(el => el.classList.contains('active')) });
      await page.locator('[data-bs-slide-to="0"]').click();
      await page.waitForTimeout(300);
      controls.push({ name: 'Indicateur du carrousel', passed: await page.locator('.carousel-item').first().evaluate(el => el.classList.contains('active')) });
      await page.emulateMedia({ reducedMotion: 'no-preference' });
      await page.reload();
      await page.mouse.move(0, 0);
      await page.waitForTimeout(5600);
      controls.push({ name: 'Défilement automatique', passed: await page.locator('.carousel-item').nth(1).evaluate(el => el.classList.contains('active')) });
    }
    fs.writeFileSync(path.join(outputDir, 'controles.json'), JSON.stringify({ mode: 'HTML rendus par tests SQLite isolés ; actions métier non exécutées dans le navigateur', results, controls, errors }, null, 2));
    const failures = results.filter(r => r.overflow || r.missingImages || r.headerHeight > 78 || r.heroLayout?.valid === false || r.editorialLayout.some(e => !e.valid));
    console.log(JSON.stringify({ pages: results.length, failures, controls, errors }, null, 2));
    if (failures.length || errors.length || controls.some(c => !c.passed)) process.exitCode = 1;
  } finally {
    await browser.close();
    server.close();
  }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
