function webscrape2() {
  if [[ -z $1 ]]; then
    print -P "%F{red}Użycie: webscrape2 <URL>%f"
    return 1
  fi

  local url=$1
  local host=${url#*://}
  host=${host%%/*}
  local dir="${host//:/_}"
  local outdir="$(pwd)/$dir"

  print -P "%F{blue}Mirroring %B$url%b → %B$outdir/%b (RSC fix – pełny HTML)%f"

  # Arch deps (tylko jeśli potrzeba)
  if [[ -f /etc/arch-release ]]; then
    print -P "%F{yellow}Sprawdzam deps...%f"
    sudo pacman -S --needed --noconfirm nss nspr at-spi2-core libcups libdrm dbus libxcb \
      libxkbcommon libx11 libxcomposite libxdamage libxext libxfixes libxrandr mesa pango cairo alsa-lib gtk3
  fi

  local tmp=$(mktemp -d /tmp/playwright-scrape.XXXXXX)
  cd "$tmp" || return 1

  npm init -y >/dev/null 2>&1
  npm install playwright --no-audit --no-fund --silent
  npx playwright install chromium

  cat > mirror.js << 'EOL'
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

  // 1. Zapisujemy wszystkie assety i API
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
    } catch (e) {}
  });

  await page.goto(url, { waitUntil: 'networkidle', timeout: 120000 });

  // 2. Pełna hydracja + scroll (żeby wszystkie lazy chunks się załadowały)
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(6000);

  // 3. Zapisujemy WYRERENDEROWANY HTML (to jest klucz!)
  const finalHtml = await page.content();
  fs.writeFileSync(path.join(outDir, 'index.html'), finalHtml);

  await browser.close();

  // router.php
  fs.writeFileSync(path.join(outDir, 'router.php'), `<?php
\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
\$file = __DIR__ . \$uri;
if (file_exists(\$file) && !is_dir(\$file)) {
    if (strpos(\$uri, '/api/') === 0) { header('Content-Type: application/json'); echo '{}'; exit; }
    return false;
}
if (strpos(\$uri, '/api/') === 0) { header('Content-Type: application/json'); echo '{}'; exit; }
require __DIR__ . '/index.html';
`);

  console.log("\\n✅ Gotowe! Folder: $outDir");
  console.log("cd $outDir && php -S localhost:2137 router.php");
})();
EOL

  node mirror.js "$url" "$outdir"

  cd - >/dev/null
  rm -rf "$tmp"

  print -P "%F{green}Mirroring zakończone!%f"
  print -P "%F{yellow}Uruchom:%f"
  print -P "  cd $dir && php -S localhost:2137 router.php"
}
