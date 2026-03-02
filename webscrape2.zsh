function webscrape() {
  if [[ -z $1 ]]; then
    print -P "%F{red}Użycie: webscrape <URL>%f"
    return 1
  fi

  if ! command -v node &> /dev/null || ! command -v npm &> /dev/null; then
    print -P "%F{red}Node.js/npm nie znaleziony.%f"
    return 1
  fi

  local url=$1
  local host=${url#*://}
  host=${host%%/*}
  local dir="${host//:/_}"
  local outdir="$(pwd)/$dir"

  print -P "%F{blue}Mirroring %B$url%b → %B$outdir%b (PHP/SSR + uniwersalny router)%f"

  # === CACHE mirror.js ===
  local mirror_js="/tmp/omniscrape-mirror.js"
  if [[ ! -f "$mirror_js" ]]; then
    print -P "%F{yellow}Pobieranie mirror.js z repo (pierwszy raz)...%f"
    if ! curl -sL -f -o "$mirror_js" "https://raw.githubusercontent.com/Szmelc-INC/OmniScrape/main/mirror.js"; then
      print -P "%F{red}Nie można pobrać mirror.js – wrzuć nową wersję na repo%f"
      return 1
    fi
    print -P "%F{green}mirror.js zapisany w cache%f"
  fi

  local tmp=$(mktemp -d /tmp/playwright-scrape.XXXXXX)
  trap 'rm -rf "$tmp"' EXIT

  cd "$tmp" || return 1

  npm init -y >/dev/null 2>&1
  npm install playwright --no-audit --no-fund --silent
  npx playwright install chromium --with-deps 2>/dev/null || npx playwright install chromium

  cp "$mirror_js" ./mirror.js

  node ./mirror.js "$url" "$outdir"

  print -P "%F{yellow}Pobieranie router.php z repo...%f"
  curl -sL -f -o "$outdir/router.php" "https://raw.githubusercontent.com/Szmelc-INC/OmniScrape/main/router.php" || 
    print -P "%F{red}Błąd pobierania router.php%f"

  # === UNIWERSALNY ROUTER DLA NIE-GROKIPEDIA ===
  if [[ $host != "grokipedia.com" ]]; then
    cat > "$outdir/router.php" << 'EOF'
<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($uri === '/' || $uri === '') $uri = '/index.html';

$file = __DIR__ . $uri;

if (file_exists($file) && !is_dir($file)) {
  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  $ct = [
    'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'woff' => 'font/woff',
    'woff2' => 'font/woff2', 'php' => 'text/html; charset=utf-8'
  ][$ext] ?? 'text/html; charset=utf-8';
  header('Content-Type: ' . $ct);
  readfile($file);
  exit;
}

// Fallback dla SPA i PHP SSR
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/index.html';
?>
EOF
    print -P "%F{green}Zastosowano uniwersalny router (dla PHP/SSR)%f"
  else
    print -P "%F{green}Zachowano proxy-router dla grokipedia.com%f"
  fi

  cd - >/dev/null

  print -P "%F{green}Mirroring zakończone!%f"
  print -P "%F{yellow}Uruchom:%f"
  print -P "  cd $dir && php -S localhost:2137 router.php"
}
