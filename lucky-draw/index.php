<?php
require_once __DIR__ . '/config.php';

// ================= SETTING =================
const FILTER_BRAND        = 'Nafesa';   // Only Brand
const FILTER_PRODUCT_TYPE = 'Normal';   // Only Product Type
const EXCLUDE_PRICE_CODE  = 'FOC';      // Exclude Price Code

// Awalan Order ID bagi setiap region (link admin pulangkan semua company bercampur)
$ORDER_PREFIXES = [
    'MY' => ['MYHQ', 'MYBT'],
    'SG' => ['SGHQ'],
];

$REGIONS = [
    'MY' => 'https://admin.e-saudagaar.com/index.php/report/RepDPProduction/printTaxInvoice?param=TVlfX19fX18wMS0xMC0yMDI2X18zMS0xMC0yMDI2X19fX19fX19fX19fX19fX19fMl9fX19jc2JfX19fX19fX19f',
    'SG' => 'https://admin.e-saudagaar.com/index.php/report/RepDPProduction/printTaxInvoice?param=U0dfX19fX18wMS0xMC0yMDI2X18zMS0xMC0yMDI2X19fX19fX19fX19fX19fX19fMl9fX19jc2JfX19fX19fX19f',
];
// ===========================================

// HTTP tanpa cURL: file_get_contents + cookie disimpan manual dalam fail JSON
const COOKIE_FILE_NAME = 'luckydraw_cookie.json';

function cookieFilePath(): string {
    return sys_get_temp_dir() . '/' . COOKIE_FILE_NAME;
}

function http(string $url, ?array $post = null, array $headers = []): string {
    $file = cookieFilePath();
    $cookies = [];
    if (file_exists($file)) {
        $c = json_decode((string) file_get_contents($file), true);
        if (is_array($c)) $cookies = $c;
    }

    $lines = $headers;
    $lines[] = 'User-Agent: Mozilla/5.0';
    if ($cookies) {
        $parts = [];
        foreach ($cookies as $k => $v) $parts[] = $k . '=' . $v;
        $lines[] = 'Cookie: ' . implode('; ', $parts);
    }

    $http = [
        'method'          => $post !== null ? 'POST' : 'GET',
        'timeout'         => 60,
        'follow_location' => 1,
        'max_redirects'   => 10,
        'ignore_errors'   => true,   // pulangkan body walaupun 4xx/5xx
    ];
    if ($post !== null) {
        $lines[] = 'Content-Type: application/x-www-form-urlencoded';
        $http['content'] = http_build_query($post);
    }
    $http['header'] = implode("\r\n", $lines);

    $ctx = stream_context_create([
        'http' => $http,
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $err = '';
    if ($body === false) {
        $e = error_get_last();
        $err = $e['message'] ?? 'Unknown stream error';
    }

    $respHeaders = $http_response_header ?? [];
    $code = 0;
    if ($respHeaders && preg_match('/^HTTP\/\S+\s+(\d+)/', $respHeaders[0], $m)) $code = (int) $m[1];

    // Simpan cookie baru (Set-Cookie)
    $changed = false;
    foreach ($respHeaders as $h) {
        if (stripos($h, 'Set-Cookie:') !== 0) continue;
        $pair = explode(';', trim(substr($h, 11)), 2)[0];
        $eq = strpos($pair, '=');
        if ($eq === false) continue;
        $name = trim(substr($pair, 0, $eq));
        if ($name !== '') { $cookies[$name] = trim(substr($pair, $eq + 1)); $changed = true; }
    }
    if ($changed) file_put_contents($file, json_encode($cookies), LOCK_EX);

    $GLOBALS['LAST'] = ['code' => $code, 'url' => $url, 'err' => $err];
    return is_string($body) ? $body : '';
}

// Cara login sama seperti api_loginagents.php (POST AJAX ke sysapp/Login/Login)
const ADMIN_LOGIN_PATH = '/index.php/sysapp/Login/Login';

function adminLogin(): bool {
    $base = rtrim(ADMIN_SOURCE_BASE_URL, '/');
    @unlink(cookieFilePath());   // sesi baru
    http($base . '/');                                        // dapatkan cookie awal

    $resp = http($base . ADMIN_LOGIN_PATH, [
        'cblanguage' => 'en_us',
        'lbusername' => ADMIN_SOURCE_USERNAME,
        'lbpasswd'   => ADMIN_SOURCE_PASSWORD,
    ], [
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
    ]);

    $json = json_decode($resp, true);
    $msg  = is_array($json) && isset($json['msg']) ? strtoupper((string) $json['msg']) : '';
    $ok   = $msg === 'OK'
         || (stripos($resp, 'document.location') !== false && stripos($resp, 'sysapp/desktop') !== false);

    $GLOBALS['LOGIN_INFO'] = [
        'post_to'  => $base . ADMIN_LOGIN_PATH,
        'berjaya'  => $ok,
        'http'     => $GLOBALS['LAST']['code'] ?? null,
        'respons'  => substr(trim(preg_replace('/\s+/', ' ', strip_tags($resp))), 0, 300),
    ];
    return $ok;
}

function fetchReport(string $url): string {
    $html = http($url);
    if (stripos($html, 'Member ID') === false) {   // belum login / sesi tamat
        adminLogin();
        $html = http($url);
    }
    $GLOBALS['DEBUG_HTML'] = $html;
    return $html;
}

function norm(string $s): string {
    $s = str_replace("\xC2\xA0", ' ', $s);
    return strtolower(trim(preg_replace('/[\s\p{Z}]+/u', ' ', $s)));
}

function headerKey(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', norm($s));
}

function closestTable(?DOMNode $n): ?DOMNode {
    while ($n !== null && strtolower($n->nodeName) !== 'table') $n = $n->parentNode;
    return $n;
}

const REQUIRED_HEADERS = [
    'memberid', 'membername', 'orderid', 'itemcode',
    'itemdescription', 'brand', 'producttype', 'pricecode', 'qty',
    'orderdate',
];
const HEADER_ALIASES = [
    'porducttype' => 'producttype',   // typo dalam report asal
    'memberno'    => 'memberid',
    'membercode'  => 'memberid',
    'orderno'     => 'orderid',
    'description' => 'itemdescription',
    'quantity'    => 'qty',
];

// Cari map header daripada satu baris. Hanya ambil kemunculan pertama setiap header.
function headerMap(array $cells): array {
    $map = [];
    foreach ($cells as $i => $text) {
        $k = headerKey($text);
        $k = HEADER_ALIASES[$k] ?? $k;
        if (in_array($k, REQUIRED_HEADERS, true) && !isset($map[$k])) $map[$k] = $i;
    }
    return $map;
}

function parseRows(string $html): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new DOMXPath($dom);

    $headerMap = null;
    $headerTable = null;
    $best = [];
    $rows = [];

    // Satu pass sahaja ikut susunan dokumen: ayat/title di atas diabaikan,
    // header sebenar dikesan sebagai baris yang ada semua nama kolum diperlukan.
    foreach ($xpath->query('//tr') as $tr) {
        // Baris yang mengandungi table lain (layout wrapper) dilangkau supaya tak duplicate
        if ($xpath->query('.//table', $tr)->length > 0) continue;

        $cells = [];
        foreach ($xpath->query('./th|./td', $tr) as $c) $cells[] = norm($c->textContent);
        if (!$cells) continue;

        $map = headerMap($cells);
        if (count($map) > count($best)) $best = $map;

        if (count($map) === count(REQUIRED_HEADERS)) {
            if ($headerMap === null) {           // header sebenar dijumpai
                $headerMap = $map;
                $headerTable = closestTable($tr);
            }
            continue;                            // langkau header berulang (cth. setiap page cetakan)
        }
        if ($headerMap === null) continue;       // masih di bahagian atas report
        $t = closestTable($tr);
        if ($headerTable === null || $t === null || !$t->isSameNode($headerTable)) continue;

        $get = function (string $name) use ($headerMap, $cells) {
            return $cells[$headerMap[$name]] ?? '';
        };
        $rows[] = [
            'id'    => strtoupper($get('memberid')),
            'name'  => strtoupper($get('membername')),
            'order' => strtoupper($get('orderid')),
            'code'  => strtoupper($get('itemcode')),
            'desc'  => strtoupper($get('itemdescription')),
            'brand' => $get('brand'),
            'type'  => $get('producttype'),
            'price' => $get('pricecode'),
            'qty'   => (int) str_replace(',', '', $get('qty')),
            'datetime' => $get('orderdate'),
        ];
    }

    $GLOBALS['PARSE_DIAGNOSTICS'] = [
        'tables'             => $dom->getElementsByTagName('table')->length,
        'header_found'       => $headerMap !== null,
        'recognized_headers' => array_keys($best),
        'missing_headers'    => array_values(array_diff(REQUIRED_HEADERS, array_keys($headerMap ?? $best))),
        'rows_before_filter' => count($rows),
    ];
    $GLOBALS['PARSE_MISSING_HEADERS'] = $headerMap === null
        ? array_values(array_diff(REQUIRED_HEADERS, array_keys($best)))
        : [];
    return $rows;
}

function luckyDateTime(string $value): ?DateTimeImmutable
{
    if (str_contains($value, "\0")) return null;

    $timezone = new DateTimeZone('Asia/Singapore');

    foreach ([
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i',
        'Y-m-d H:i:s',
        'd-m-Y H:i:s',
        'd/m/Y H:i:s',
        'd-m-Y H:i',
        'd/m/Y H:i',
        'd/m/Y h:i:s a',
        'd-m-Y h:i:s a',
    ] as $format) {
        $date = DateTimeImmutable::createFromFormat(
            '!' . $format,
            trim($value),
            $timezone
        );

        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date !== false
            && ($errors === false
                || ($errors['warning_count'] === 0
                    && $errors['error_count'] === 0))
        ) {
            return $date;
        }
    }

    return null;
}

function luckyReportUrl(
    string $url,
    DateTimeImmutable $from,
    DateTimeImmutable $to
): string {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $param = $query['param'] ?? null;
    $decoded = is_string($param)
        ? base64_decode($param, true)
        : false;
    
    if ($decoded === false) {
        throw new RuntimeException('Invalid report URL parameter.');
    }

    // Request tarikh yang dipilih di external report.
    // Masa yang tepat berlaku locally
    $dates = [$from->format('d-m-Y'), $to->format('d-m-Y')];
    $index = 0;

    $decoded = preg_replace_callback(
        '/\d{2}-\d{2}-\d{4}/',
        function () use (&$index, $dates) {
            return $dates[$index++];
        },
        $decoded,
        2,
        $count
    );

    if ($count !== 2) {
        throw new RuntimeException('Report URL dates were not found.');
    }

    $query['param'] = base64_encode($decoded);

    return explode('?', $url, 2)[0] . '?' . http_build_query($query);
}

function luckyLink(array $changes = []): string
{
    global $view, $mode, $fromInput, $toInput;

    $params = array_replace([
        'region' => $view,
        'mode' => $mode,
        'from' => $fromInput,
        'to' => $toInput,
    ], $changes);

    return htmlspecialchars(
        '?' . http_build_query($params),
        ENT_QUOTES,
        'UTF-8'
    );
}

// ---------- Proses ----------
$errors = [];
$regionInput = $_GET['region'] ?? 'ALL';
$view = is_string($regionInput) ? strtoupper(trim($regionInput)) : '';
if ($view !== 'ALL' && !array_key_exists($view, $REGIONS)) {
    $errors[] = 'Region tidak sah. Sila pilih Semua, MY atau SG.';
    $view = 'ALL';
}
$entries = []; // 1 unit = 1 baris
$debug = isset($_GET['debug']);
// item   = 1 baris setiap Member + Item Code
// member = 1 baris setiap Member sahaja
// unit   = 1 baris setiap unit (DEFAULT: qty 100 = 100 baris)
$mode = in_array($_GET['mode'] ?? '', ['member', 'item'], true) ? $_GET['mode'] : 'unit';
$agg = [];
$seenHash = [];
$GLOBALS['NOTES'] = [];

$fromInput = $_GET['from'] ?? '';
$toInput = $_GET['to'] ?? '';
$fromTime = null;
$toTime = null;

if (!is_string($fromInput) || !is_string($toInput)) {
    $errors[] = 'Tarikh dan masa tidak sah.';
    $fromInput = '';
    $toInput = '';
} else {
    $fromInput = trim($fromInput);
    $toInput = trim($toInput);

    if ($fromInput !== '' || $toInput !== '') {
        $fromTime = luckyDateTime($fromInput);
        $toTime = luckyDateTime($toInput);

        if (!$fromTime || !$toTime) {
            $errors[] = 'Sila masukkan tarikh dan masa mula serta tamat yang sah.';
        } elseif ($fromTime > $toTime) {
            $errors[] = 'Masa mula mesti sebelum atau sama dengan masa tamat.';
        }
    }
}

foreach (($errors ? [] : $REGIONS) as $code => $url) {
    if ($view !== 'ALL' && $view !== $code) continue;
    try {
        if ($fromTime && $toTime) {
            $url = luckyReportUrl($url, $fromTime, $toTime);
        }

        $rows = parseRows(fetchReport($url));
    } catch (Throwable $error) {
        $errors[] = "$code: " . $error->getMessage();
        continue;
    }
    $n = count($rows);
    // (a) Satu page yang mengandungi report yang sama dua kali (cth. versi skrin + cetak)
    if ($n > 0 && $n % 2 === 0 && array_slice($rows, 0, $n / 2) == array_slice($rows, $n / 2)) {
        $rows = array_slice($rows, 0, $n / 2);
        $GLOBALS['NOTES'][] = "$code: report dikesan berganda dalam page, separuh dibuang.";
    }
    if ($debug && !isset($_GET['csv'])) {
        echo '<pre style="background:#eee;padding:8px;white-space:pre-wrap">'
           . htmlspecialchars("[$code] HTTP " . ($GLOBALS['LAST']['code'] ?? '') . ' | URL: ' . ($GLOBALS['LAST']['url'] ?? '')
           . ' | ralat: ' . ($GLOBALS['LAST']['err'] ?? '') . "\nUsername set: " . (ADMIN_SOURCE_USERNAME !== '' ? 'ya' : 'TIDAK (env kosong!)')
           . "\nLogin: " . json_encode($GLOBALS['LOGIN_INFO'] ?? 'tak perlu login', JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
           . "\nParser: " . json_encode($GLOBALS['PARSE_DIAGNOSTICS'] ?? [])
           . "\nNota: " . json_encode($GLOBALS['NOTES'] ?? [])
           . "\nPanjang HTML: " . strlen($GLOBALS['DEBUG_HTML'])
           . "\n<table: " . substr_count(strtolower($GLOBALS['DEBUG_HTML']), '<table')
           . " | <tr: " . substr_count(strtolower($GLOBALS['DEBUG_HTML']), '<tr')
           . " | <iframe: " . substr_count(strtolower($GLOBALS['DEBUG_HTML']), '<iframe')
           . " | ada 'Member ID': " . (stripos($GLOBALS['DEBUG_HTML'], 'Member ID') !== false ? 'ya' : 'tidak')
           . " | ada <input password>: " . (preg_match('/type=["\']?password/i', $GLOBALS['DEBUG_HTML']) ? 'ya (page login)' : 'tidak')
           . "\n--- Teks page (700 aksara pertama) ---\n"
           . substr(trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $GLOBALS['DEBUG_HTML'])))), 0, 700)) . '</pre>';
    }
    if (!$rows) {
        $missing = $GLOBALS['PARSE_MISSING_HEADERS'] ?? [];
        if ($missing) {
            $errors[] = "$code: header jadual tidak lengkap (perlu: " . implode(', ', $missing) . ").";
        } else {
            $GLOBALS['NOTES'][] = "$code: tiada rekod pada laporan.";
        }
        continue;
    }
    $prefixes = $ORDER_PREFIXES[$code] ?? [];
    $outside = 0;
    foreach ($rows as $r) {
        if ($r['id'] === '' || $r['qty'] < 1) continue;
        $okRegion = false;
        foreach ($prefixes as $pf) if (strpos($r['order'], $pf) === 0) { $okRegion = true; break; }
        if (!$okRegion) { $outside++; continue; }
        if (strtolower($r['brand']) !== strtolower(FILTER_BRAND)) continue;
        if (strtolower($r['type'])  !== strtolower(FILTER_PRODUCT_TYPE)) continue;
        if (strtolower($r['price']) === strtolower(EXCLUDE_PRICE_CODE)) continue;
        if ($fromTime && $toTime) {
            $rowTime = luckyDateTime($r['datetime']);

            if (!$rowTime) {
                $errors[] = "$code: tarikh/masa tidak sah untuk order {$r['order']}.";
                break;
            }

            // Inclusive: include both the starting and ending second.
            if ($rowTime < $fromTime || $rowTime > $toTime) {
                continue;
            }
        }
        if ($mode === 'unit') {
            for ($i = 0; $i < $r['qty']; $i++) {
                $entries[] = ['id'=>$r['id'],'name'=>$r['name'],'order'=>$r['order'],'code'=>$r['code'],'desc'=>$r['desc'],'region'=>$code,'qty'=>1];
            }
            continue;
        }
        $key = $mode === 'member' ? $r['id'] : $r['id'] . '|' . $r['code'];
        if (!isset($agg[$key])) {
            $agg[$key] = ['id'=>$r['id'],'name'=>$r['name'],'order'=>[],'code'=>[],'desc'=>[],'region'=>$code,'qty'=>0];
        }
        $agg[$key]['qty'] += $r['qty'];
        $agg[$key]['order'][$r['order']] = 1;
        $agg[$key]['code'][$r['code']]   = 1;
        $agg[$key]['desc'][$r['desc']]   = 1;
    }
    $GLOBALS['NOTES'][] = "$code: $outside baris order region lain diabaikan (awalan dibenarkan: " . implode('/', $prefixes) . ").";
}

if ($errors) {
    $entries = [];
    $agg = [];
}

foreach ($agg as $a) {
    $entries[] = [
        'id' => $a['id'],
        'name' => $a['name'],
        'region' => $a['region'],
        'qty' => $a['qty'],
        'order' => implode(', ', array_keys($a['order'])),
        'code' => implode(', ', array_keys($a['code'])),
        'desc' => implode(', ', array_keys($a['desc'])),
    ];
}
usort($entries, fn($a, $b) => [$a['id'],$a['order'],$a['code']] <=> [$b['id'],$b['order'],$b['code']]);

if (isset($_GET['csv']) && $errors) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $errors);
    exit;
}

// ---------- Export CSV ----------
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="lucky-draw.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Member ID', 'Member Name', 'Order ID', 'Item Code', 'Item Description', 'Total Qty']);
    foreach ($entries as $m) fputcsv($o, [$m['id'], $m['name'], $m['order'], $m['code'], $m['desc'], $m['qty']]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lucky Draw - Nafesa</title>
<style>
  body{font-family:system-ui,sans-serif;margin:24px;max-width:900px}
  table{border-collapse:collapse;width:100%}
  th,td{border:1px solid #ccc;padding:6px 10px;text-align:left}
  th{background:#f3f3f3}
  .btn{padding:8px 14px;border:0;border-radius:6px;background:#222;color:#fff;text-decoration:none;cursor:pointer;font-size:14px}
  #winner{font-size:28px;font-weight:bold;margin:16px 0;color:#0a7d33}
  .err{color:#b00}
</style>
</head>
<body>
<h2>Lucky Draw – Nafesa (Normal, bukan FOC)</h2>
<form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:16px 0">
    <input type="hidden" name="region" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="mode" value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">
    <label>
        Dari<br>
        <input type="text" name="from" placeholder="19-10-2026 22:00:00" pattern="[0-9]{2}-[0-9]{2}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}" title="Format: DD-MM-YYYY HH:MM:SS (24-hour time)" maxlength="19" autocomplete="off" spellcheck="false" required style="width:230px;padding:8px;font:inherit" value="<?= htmlspecialchars($fromTime ? $fromTime->format('d-m-Y H:i:s') : $fromInput, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label>
        Hingga<br>
        <input type="text" name="to" placeholder="19-10-2026 23:00:00" pattern="[0-9]{2}-[0-9]{2}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}" title="Format: DD-MM-YYYY HH:MM:SS (24-hour time)" maxlength="19" autocomplete="off" spellcheck="false" required style="width:230px;padding:8px;font:inherit" value="<?= htmlspecialchars($toTime ? $toTime->format('d-m-Y H:i:s') : $toInput, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <button class="btn" type="submit">Tapis</button>
    <a class="btn" href="<?= luckyLink(['from' => '', 'to' => '']) ?>">
        Reset
    </a>
</form>

<?php if ($fromTime && $toTime && !$errors): ?>
    <p>
        Tempoh CDO:
        <b><?= $fromTime->format('d-m-Y H:i:s') ?></b>
        hingga
        <b><?= $toTime->format('d-m-Y H:i:s') ?></b>
        (MY/SG)
    </p>
<?php endif; ?>
<p>
    Region:
    <a href="<?= luckyLink(['region' => 'ALL']) ?>">Semua</a> |
    <a href="<?= luckyLink(['region' => 'MY']) ?>">MY</a> |
    <a href="<?= luckyLink(['region' => 'SG']) ?>">SG</a>
    &nbsp;
    <a class="btn" href="<?= luckyLink(['csv' => 1]) ?>">Export CSV</a>
</p>
<p>
    Kiraan:
    <a href="<?= luckyLink(['mode' => 'item']) ?>">Member + Item</a> |
    <a href="<?= luckyLink(['mode' => 'member']) ?>">Member sahaja</a> |
    <a href="<?= luckyLink(['mode' => 'unit']) ?>">Setiap unit</a>
</p>
<?php foreach ($errors as $e): ?><p class="err"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
<div id="winner"></div>
<p><b>Jumlah baris / tiket: <?= count($entries) ?></b></p>
<table>
  <tr><th>Member ID</th><th>Member Name</th><th>Order ID</th><th>Item Code</th><th>Item Description</th><th>Total Qty</th></tr>
  <?php foreach ($entries as $m): ?>
  <tr>
    <td><?= htmlspecialchars($m['id']) ?></td>
    <td><?= htmlspecialchars($m['name']) ?></td>
    <td><?= htmlspecialchars($m['order']) ?></td>
    <td><?= htmlspecialchars($m['code']) ?></td>
    <td><?= htmlspecialchars($m['desc']) ?></td>
    <td><?= (int) $m['qty'] ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<script>
const members = <?= json_encode($entries) ?>;
function draw(){
  if(!members.length){alert('Tiada peserta');return;}
  const buf = new Uint32Array(1); crypto.getRandomValues(buf);
  const w = members[buf[0] % members.length];
  document.getElementById('winner').textContent = '🎉 ' + w.id + ' – ' + w.name;
}
</script>
</body>
</html>
