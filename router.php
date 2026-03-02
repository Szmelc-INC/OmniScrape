<?php
/**
 * GROKIPEDIA.COM – ULTIMATE ON-DEMAND MIRROR ROUTER.PHP
 * 
 * Co robi:
 * 1. Dla KAŻDEGO requestu (search, api/full-text-search, _rsc, chunks, css, js, fonty, png, manifest, monitoring itd.)
 *    → najpierw sprawdza cache/cache/[md5(full-url)].html
 * 2. Jeśli cache istnieje → serwuje natychmiast (z poprawnym Content-Type)
 * 3. Jeśli nie ma → proxy do https://grokipedia.com + zapisuje do cache
 * 4. Tylko /api/typeahead zwraca [] (żeby autocomplete nie wywalał błędu)
 * 5. Wszystko inne (w tym /api/full-text-search) = pełny proxy
 * 6. Szczegółowe logi do /tmp/router.log
 * 7. Automatycznie tworzy foldery cache/
 */

$start = microtime(true);

// === PARSOWANIE REQUESTU ===
$uri   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$query = $_SERVER['QUERY_STRING'] ?? '';
$full  = $uri . ($query ? '?' . $query : '');

// === CACHE SETUP ===
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/' . md5($full) . '.html';

// === LOG ===
$logFile = '/tmp/router.log';
$logMsg  = date('H:i:s') . " | " . str_pad($_SERVER['REQUEST_METHOD'], 4) . " " . $full;
file_put_contents($logFile, $logMsg . "\n", FILE_APPEND);

// === SPECJALNE API (tylko typeahead) ===
if (strpos($uri, '/api/typeahead') === 0) {
    header('Content-Type: application/json');
    echo '[]';
    file_put_contents($logFile, $logMsg . " → TYPEAHEAD FAKE []\n", FILE_APPEND);
    exit;
}

// === CACHE HIT? ===
if (file_exists($cacheFile)) {
    // Auto Content-Type
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $ct = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'woff2'=> 'font/woff2',
        'woff' => 'font/woff',
        'svg'  => 'image/svg+xml',
    ][$ext] ?? 'text/html; charset=utf-8';

    header('Content-Type: ' . $ct);
    header('X-Cache: HIT');
    readfile($cacheFile);

    $time = round((microtime(true) - $start) * 1000, 2);
    file_put_contents($logFile, $logMsg . " → CACHE HIT (" . $time . "ms)\n", FILE_APPEND);
    exit;
}

// === PROXY ===
$remote = 'https://grokipedia.com' . $full;

$ch = curl_init($remote);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? '*/*'),
    'Accept-Language: ' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US,en;q=0.9'),
    'Referer: ' . ($_SERVER['HTTP_REFERER'] ?? 'https://grokipedia.com'),
]);

$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($content !== false && $httpCode === 200 && strlen($content) > 50) {
    // Zapisz do cache
    file_put_contents($cacheFile, $content);

    // Content-Type
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $ct = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'woff2'=> 'font/woff2',
        'woff' => 'font/woff',
        'svg'  => 'image/svg+xml',
    ][$ext] ?? 'text/html; charset=utf-8';

    header('Content-Type: ' . $ct);
    header('X-Cache: MISS');
    echo $content;

    $time = round((microtime(true) - $start) * 1000, 2);
    file_put_contents($logFile, $logMsg . " → PROXY + CACHE MISS (" . $time . "ms)\n", FILE_APPEND);
    exit;
}

// === FALLBACK SPA (tylko gdy wszystko padło) ===
header('Content-Type: text/html; charset=utf-8');
header('X-Cache: FALLBACK');
require __DIR__ . '/index.html';

$time = round((microtime(true) - $start) * 1000, 2);
file_put_contents($logFile, $logMsg . " → FALLBACK SPA (" . $time . "ms)\n", FILE_APPEND);
