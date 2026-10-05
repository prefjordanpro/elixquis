const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require(process.argv[2]);
(async () => {
  // Lire uniquement la clé publique en mémoire, sans l'afficher ni la sauvegarder.
  const key = execFileSync('php', ['-r', "require 'vendor/autoload.php'; (new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv('.env'); echo $_ENV['SENDCLOUD_PUBLIC_KEY'] ?? '';"], { cwd: process.cwd(), env: { ...process.env, APP_ENV: 'dev' } }).toString().trim();
  if (!key) throw new Error('Clé publique picker absente dans la configuration existante.');
  const browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    await page.goto('https://sendcloud-public.gitlab.io/spp-integration-example/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => !!window.sendcloud?.servicePoints);
    await page.evaluate(key => {
      window.pickerResult = null;
      window.sendcloud.servicePoints.open({ apiKey: key, country: 'FR', language: 'fr-fr', postalCode: '75001', city: 'Paris', carriers: 'chronopost' }, (point, postNumber) => { window.pickerResult = { point, postNumber }; }, errors => { window.pickerFailure = errors; });
    }, key);
    await page.waitForTimeout(3500);
    const result = await page.evaluate(() => ({ frames: [...document.querySelectorAll('iframe')].map(f => ({ origin: f.src ? new URL(f.src, location.href).origin : '', official: /sendcloud\.(sc|com)/.test(f.src), visible: f.getBoundingClientRect().height > 100 })), failures: window.pickerFailure || [] }));
    fs.mkdirSync('docs/branding-review/picker', { recursive: true });
    await page.screenshot({ path: 'docs/branding-review/picker/widget-officiel.png' });
    console.log(JSON.stringify(result));
    if (!result.frames.some(f => f.official && f.visible) || result.failures.length) process.exitCode = 1;
    const frame = page.frameLocator('iframe').first();
    await frame.getByText('MARIA', { exact: true }).click();
    await page.screenshot({ path: 'docs/branding-review/picker/widget-officiel-detail.png' });
    await frame.getByRole('button', { name: /Choisir/ }).click();
    await page.waitForTimeout(2500);
    await page.screenshot({ path: 'docs/branding-review/picker/widget-apres-choix.png' });
    console.log('Callback reçu:', await page.evaluate(() => !!window.pickerResult));
    if (!await page.evaluate(() => !!window.pickerResult)) {
      await frame.getByText('Z ET R', { exact: true }).click();
      await frame.getByRole('button', { name: /Choisir/ }).click();
      await page.waitForTimeout(2500);
    }
    if (!await page.evaluate(() => !!window.pickerResult)) {
      console.log('Sélection réelle bloquée : Sendcloud refuse les relais essayés comme indisponibles.');
      await page.evaluate(() => window.sendcloud.servicePoints.close());
      for (const width of [768, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate(key => window.sendcloud.servicePoints.open({ apiKey: key, country: 'FR', language: 'fr-fr', postalCode: '75001', carriers: 'chronopost' }, () => {}, () => {}), key);
        await page.waitForTimeout(2500);
        await page.screenshot({ path: `docs/branding-review/picker/widget-officiel-${width}.png` });
        console.log({ width, visible: await page.locator('iframe').isVisible() });
        await page.evaluate(() => window.sendcloud.servicePoints.close());
      }
      return;
    }
    const firstId = await page.evaluate(() => window.pickerResult.point.id);
    console.log('Sélection officielle:', await page.evaluate(() => ({ idPresent: Number.isInteger(window.pickerResult.point.id), addressPresent: !!window.pickerResult.point.street && !!window.pickerResult.point.city, carrier: window.pickerResult.point.carrier })));
    await page.evaluate(({ key, firstId }) => {
      window.pickerResult = null;
      window.sendcloud.servicePoints.open({ apiKey: key, country: 'FR', language: 'fr-fr', servicePointId: firstId, carriers: 'chronopost' }, (point, postNumber) => { window.pickerResult = { point, postNumber }; }, errors => { window.pickerFailure = errors; });
    }, { key, firstId });
    for (const name of ['ARCUS', 'KULTUR BACKDOOR', 'La Poste de PARIS CHATELET']) {
      await frame.getByText(name, { exact: true }).first().click();
      await frame.getByRole('button', { name: /Choisir/ }).click();
      await page.waitForTimeout(2000);
      if (await page.evaluate(() => !!window.pickerResult)) break;
    }
    console.log('Changement officiel:', await page.evaluate(firstId => window.pickerResult ? window.pickerResult.point.id !== firstId : 'Relais refusé par Sendcloud', firstId));
    await page.evaluate(() => window.sendcloud.servicePoints.close());
    for (const width of [768, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.evaluate(key => window.sendcloud.servicePoints.open({ apiKey: key, country: 'FR', language: 'fr-fr', postalCode: '75001', carriers: 'chronopost' }, () => {}, () => {}), key);
      await page.waitForTimeout(2000);
      await page.screenshot({ path: `docs/branding-review/picker/widget-officiel-${width}.png` });
      console.log({ width, visible: await page.locator('iframe').isVisible() });
      await page.evaluate(() => window.sendcloud.servicePoints.close());
    }
    await page.evaluate(() => window.sendcloud.servicePoints.close());
  } finally { await browser.close(); }
})().catch(err => { console.error(err.message); process.exitCode = 1; });
