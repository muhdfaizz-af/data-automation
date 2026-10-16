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

function http(string $url, ?array $post = null, array $headers = []): string {
    $jar = sys_get_temp_dir() . '/luckydraw_cookie.txt';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $out = curl_exec($ch);
    $GLOBALS['LAST'] = [
        'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'url'  => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'err'  => curl_error($ch),
    ];
    curl_close($ch);
    return $out === false ? '' : $out;
}

// Cara login sama seperti api_loginagents.php (POST AJAX ke sysapp/Login/Login)
const ADMIN_LOGIN_PATH = '/index.php/sysapp/Login/Login';

function adminLogin(): bool {
    $base = rtrim(ADMIN_SOURCE_BASE_URL, '/');
    @unlink(sys_get_temp_dir() . '/luckydraw_cookie.txt');   // sesi baru
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

// ---------- Proses ----------
$view = strtoupper($_GET['region'] ?? 'ALL');
$errors = [];
$entries = []; // 1 unit = 1 baris
$debug = isset($_GET['debug']);
// item   = 1 baris setiap Member + Item Code
// member = 1 baris setiap Member sahaja
// unit   = 1 baris setiap unit (DEFAULT: qty 100 = 100 baris)
$mode = in_array($_GET['mode'] ?? '', ['member', 'item'], true) ? $_GET['mode'] : 'unit';
$agg = [];
$seenHash = [];
$GLOBALS['NOTES'] = [];

foreach ($REGIONS as $code => $url) {
    if ($view !== 'ALL' && $view !== $code) continue;
    $rows = parseRows(fetchReport($url));
    $n = count($rows);
    // (a) Satu page yang mengandungi report yang sama dua kali (cth. versi skrin + cetak)
    if ($n > 0 && $n % 2 === 0 && array_slice($rows, 0, $n / 2) == array_slice($rows, $n / 2)) {
        $rows = array_slice($rows, 0, $n / 2);
        $GLOBALS['NOTES'][] = "$code: report dikesan berganda dalam page, separuh dibuang.";
    }
    if ($debug) {
        echo '<pre style="background:#eee;padding:8px;white-space:pre-wrap">'
           . htmlspecialchars("[$code] HTTP " . ($GLOBALS['LAST']['code'] ?? '') . ' | URL: ' . ($GLOBALS['LAST']['url'] ?? '')
           . ' | curl err: ' . ($GLOBALS['LAST']['err'] ?? '') . "\nUsername set: " . (ADMIN_SOURCE_USERNAME !== '' ? 'ya' : 'TIDAK (env kosong!)')
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
        $errors[] = $missing
            ? "$code: header jadual tidak lengkap (perlu: " . implode(', ', $missing) . ")."
            : "$code: tiada rekod pada laporan.";
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
foreach ($agg as $a) {
    $entries[] = [
        'id' => $a['id'], 'name' => $a['name'], 'region' => $a['region'], 'qty' => $a['qty'],
        'order' => implode(', ', array_keys($a['order'])),
        'code'  => implode(', ', array_keys($a['code'])),
        'desc'  => implode(', ', array_keys($a['desc'])),
    ];
}
usort($entries, fn($a, $b) => [$a['id'],$a['order'],$a['code']] <=> [$b['id'],$b['order'],$b['code']]);

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
<p>
  Region:
  <a href="?region=ALL&mode=<?= $mode ?>">Semua</a> |
  <a href="?region=MY&mode=<?= $mode ?>">MY</a> |
  <a href="?region=SG&mode=<?= $mode ?>">SG</a>
  &nbsp; <a class="btn" href="?region=<?= htmlspecialchars($view) ?>&mode=<?= $mode ?>&csv=1">Export CSV</a>
  <button class="btn" onclick="draw()">Cabut Pemenang</button>
</p>
<p>Kiraan:
  <a href="?region=<?= htmlspecialchars($view) ?>&mode=item"><?= $mode==='item' ? '<b>Member + Item</b>' : 'Member + Item' ?></a> |
  <a href="?region=<?= htmlspecialchars($view) ?>&mode=member"><?= $mode==='member' ? '<b>Member sahaja</b>' : 'Member sahaja' ?></a> |
  <a href="?region=<?= htmlspecialchars($view) ?>&mode=unit"><?= $mode==='unit' ? '<b>Setiap unit</b>' : 'Setiap unit' ?></a>
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