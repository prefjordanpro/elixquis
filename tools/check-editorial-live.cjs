const fs = require('fs');
const path = require('path');
const { chromium } = require(process.argv[2]);
(async () => {
  const browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
  const page = await browser.newPage();
  await page.addInitScript(() => localStorage.setItem('ageVerified', 'true'));
  const output = 'docs/branding-review/live';
  fs.mkdirSync(output, { recursive: true });
  const results = [];
  try {
    for (const width of [1440, 1024, 768, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto('http://localhost/elixquis/public/', { waitUntil: 'networkidle' });
      const result = await page.evaluate(() => ({
        css: [...document.querySelectorAll('link[rel="stylesheet"]')].map(el => el.href),
        sections: [...document.querySelectorAll('.story-band, .brand-story')].map(section => {
          const grid = section.querySelector('.editorial-grid');
          const g = grid.getBoundingClientRect();
          const p = section.querySelector('.editorial-photo img').getBoundingClientRect();
          const c = section.querySelector('.editorial-copy').getBoundingClientRect();
          return { name: section.className, display: getComputedStyle(grid).display, width: g.width, imageWidth: p.width, imageHeight: p.height, gap: getComputedStyle(grid).columnGap,
            valid: getComputedStyle(grid).display === 'grid' && (innerWidth < 768 ? c.bottom <= p.top : (innerWidth < 1024 || p.width < g.width / 2) && (section.classList.contains('story-band') ? p.right <= c.left : c.right <= p.left)) };
        })
      }));
      results.push({ width, ...result });
      for (const selector of ['.story-band', '.brand-story']) await page.locator(selector).screenshot({ path: path.join(output, `${selector.slice(1)}-${width}.png`) });
    }
    fs.writeFileSync(path.join(output, 'mesures.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
    if (results.some(r => r.sections.length !== 2 || r.sections.some(s => !s.valid))) process.exitCode = 1;
  } finally { await browser.close(); }
})().catch(err => { console.error(err); process.exitCode = 1; });
