const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const url = process.argv[2];
  const outDir = process.argv[3];

  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();

  page.on('response', async (res) => {
    const reqUrl = res.url();
    if (!reqUrl.startsWith('http')) return;

    let localPath = new URL(reqUrl).pathname;
    if (localPath === '/' || localPath.endsWith('/')) localPath = '/index.html';

    const filePath = path.join(outDir, localPath);
    fs.mkdirSync(path.dirname(filePath), { recursive: true });

    try {
      const buf = await res.body();
      fs.writeFileSync(filePath, buf);

      // === FIX DLA PHP/SSR ===
      if (reqUrl === url || localPath.endsWith('.php') || localPath.endsWith('.html')) {
        fs.writeFileSync(path.join(outDir, 'index.html'), buf);
      }
    } catch (e) {}
  });

  await page.goto(url, { waitUntil: 'networkidle', timeout: 120000 });
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(6000);

  const finalHtml = await page.content();
  fs.writeFileSync(path.join(outDir, 'index.html'), finalHtml);
  fs.writeFileSync(path.join(outDir, 'index.php'), finalHtml); // alias dla PHP

  await browser.close();

  console.log(`\nGotowe! Folder: ${outDir} (index.html + index.php)`);
  console.log(`cd ${outDir} && php -S localhost:2137 router.php`);
})();
