// Screenshots of the report HTML in print mode, to check the Bangla and the
// tables by eye — the PDF itself cannot be rendered on this machine.
const path = require('path');
const { chromium } = require('playwright-core');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1123, height: 794 } });
  const file = path.join(__dirname, 'out', 'report.html').replace(/\\/g, '/');
  await page.goto('file:///' + file);
  await page.emulateMedia({ media: 'print' });
  await page.evaluate(() => document.fonts.ready);

  const shot = async (locator, name) => {
    const box = await locator.boundingBox();
    await page.screenshot({
      path: path.join(__dirname, 'out', name),
      fullPage: true,
      clip: { x: 0, y: Math.max(0, box.y - 70), width: 1123, height: 794 },
    });
  };

  await page.screenshot({ path: path.join(__dirname, 'out', 'chk-cover.png') });
  await shot(page.locator('table.findings').first(), 'chk-findings.png');
  await shot(page.locator('table.plain').first(), 'chk-plain.png');
  await shot(page.locator('.shots figure').first(), 'chk-shots.png');

  // every table's class against its real column count
  const bad = await page.evaluate(() =>
    [...document.querySelectorAll('table')]
      .filter((t) => !t.classList.contains('counts'))
      .filter((t) => (t.querySelectorAll('thead th').length === 6) !== t.classList.contains('findings'))
      .length);
  const n = await page.evaluate(() => ({
    findings: document.querySelectorAll('table.findings').length,
    plain: document.querySelectorAll('table.plain').length,
    images: document.querySelectorAll('.shots img').length,
  }));
  await browser.close();
  console.log('tables with wrong class:', bad, JSON.stringify(n));
})();
