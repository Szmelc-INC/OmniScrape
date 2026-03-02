<?php
/**
 * UNIVERSAL MIRROR ROUTER v9002
 * Działa dla dowolnej strony – wystarczy skopiować folder + ten plik
 */

$startTime = microtime(true);

// === UNIVERSAL CONFIG ===
$folderDomain = basename(__DIR__);
$ORIGIN = 'https://' . $folderDomain;   // AUTO z nazwy folderu

// Ręczne nadpisanie (odkomentuj i zmień):
// $ORIGIN = 'https://twoja-strona.com';

// Odczyt z pliku .origin jeśli istnieje (najwygodniejsze)
if (file_exists(__DIR__ . '/.origin')) {
    $ORIGIN = trim(file_get_contents(__DIR__ . '/.origin'));
}

// === HAR LOGGER (Po3) ===
class HarLogger {
    private static $entries = [];
    public static function add($req, $res, $hit = false) {
        self::$entries[] = [
            'startedDateTime' => gmdate('c'),
            'time' => round((microtime(true) - $GLOBALS['startTime']) * 1000, 2),
            'request' => $req,
            'response' => $res,
            'cacheHit' => $hit
        ];
    }
    public static function save() {
        if (empty(self::$entries)) return;
        $har = ['log' => ['version' => '1.2', 'creator' => ['name' => 'Grok Universal Mirror', 'version' => '9002'], 'entries' => self::$entries]];
        file_put_contents(__DIR__ . '/har.json', json_encode($har, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents(__DIR__ . '/xhar.json', json_encode($har, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents(__DIR__ . '/network.js', "const networkLog = " . json_encode(self::$entries, JSON_PRETTY_PRINT) . ";\n");
    }
}
register_shutdown_function(['HarLogger', 'save']);

// === PARSOWANIE + REAL PATH (Po1) ===
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$query  = $_SERVER['QUERY_STRING'] ?? '';
$full   = $uri . ($query ? '?' . $query : '');

$dir = __DIR__ . dirname($uri);
if (!is_dir($dir)) mkdir($dir, 0755, true);

$filename = basename($uri) ?: 'index.html';
if ($query) {
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $query);
    $safe = substr($safe, 0, 120);
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $filename = $name . ($ext ? '.' . $ext : '') . '-q-' . $safe . ($ext ? '' : '.html');
}
$localFile = $dir . '/' . $filename;

// === COOKIE JAR (Po2 – logowanie działa na każdej stronie) ===
$cookieJar = __DIR__ . '/cookies.txt';
if (!file_exists($cookieJar)) touch($cookieJar);

// === LOG ===
file_put_contents('/tmp/router.log', date('H:i:s') . " | $method $ORIGIN$full\n", FILE_APPEND);

// === CACHE HIT ===
if (file_exists($localFile)) {
    $ext = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
    $ctMap = ['css'=>'text/css','js'=>'application/javascript','json'=>'application/json','png'=>'image/png','jpg'=>'image/jpeg','woff2'=>'font/woff2','woff'=>'font/woff'];
    header('Content-Type: ' . ($ctMap[$ext] ?? 'text/html; charset=utf-8'));
    header('X-Cache: HIT');
    readfile($localFile);
    HarLogger::add(['method'=>$method, 'url'=>$full], ['status'=>200], true);
    exit;
}

// === CURL z gzip decode ===
$ch = curl_init($ORIGIN . $full);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_ENCODING, '');   // automatyczna dekompresja gzip

$headers = [];
foreach (getallheaders() as $k => $v) {
    if (strtolower($k) !== 'host' && strtolower($k) !== 'content-length') {
        $headers[] = "$k: $v";
    }
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

if (in_array($method, ['POST','PUT','PATCH'])) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}

$content   = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctHeader  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// === HEADLESS FALLBACK (Po4) ===
if ($httpCode !== 200 || strlen($content) < 100) {
    $fallback = '/tmp/universal-fallback.js';
    if (!file_exists($fallback)) {
        file_put_contents($fallback, '
const { chromium } = require("playwright");
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.goto(process.argv[1] + process.argv[2], { waitUntil: "networkidle" });
  console.log(await page.content());
  await browser.close();
})();
        ');
    }
    $content = shell_exec("node " . escapeshellarg($fallback) . " " . escapeshellarg($ORIGIN) . " " . escapeshellarg($full));
    $httpCode = 200;
    file_put_contents('/tmp/router.log', date('H:i:s') . " | HEADLESS FALLBACK $full\n", FILE_APPEND);
}

// === ZAPIS + RESPONSE ===
if ($httpCode === 200 && strlen($content) > 50) {
    file_put_contents($localFile, $content);

    $ext = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
    $ctMap = ['css'=>'text/css','js'=>'application/javascript','json'=>'application/json','png'=>'image/png','jpg'=>'image/jpeg','woff2'=>'font/woff2'];
    $ct = $ctMap[$ext] ?? ($ctHeader ?: 'text/html; charset=utf-8');

    header('Content-Type: ' . $ct);
    header('X-Cache: MISS');
    echo $content;

    HarLogger::add(['method'=>$method, 'url'=>$full], ['status'=>$httpCode, 'contentType'=>$ct]);
    exit;
}

// === SPA FALLBACK ===
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/index.html';
HarLogger::add(['url'=>$full], ['status'=>200, 'content'=>'SPA fallback']);
