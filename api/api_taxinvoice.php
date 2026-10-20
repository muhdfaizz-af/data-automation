<?php
/**
 * Import Tax Invoice report into `order_items`.
 *
 * Cron usage (default = ADMIN_SOURCE_DEFAULT_FROM / ADMIN_SOURCE_DEFAULT_TO):
 *   php api/api_taxinvoice.php
 * Custom range:
 *   php api/api_taxinvoice.php 2026-10-01 2026-10-19
 * One company only (default = MY and SG):
 *   php api/api_taxinvoice.php 2026-10-01 2026-10-19 SG
 *
 * REQUIRES the matching orders to exist already: run api_orderhistory.php for the same date range FIRST.
 * Items for an order are replaced (delete + insert) the first time that order appears in a run.
 *
 * - Logs in to the admin source, fetches the report (xlsx / html table / csv auto-detected).
 * - Same column mapping as the upload page.
 * - Safe to re-run: identical report content is skipped by file_hash; otherwise items are rebuilt per order.
 * - Works from CLI/cron or browser, same as api_loginagents.php: ?from=YYYY-MM-DD&to=YYYY-MM-DD&company=MY|SG.
 */

require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/*
define('ADMIN_SOURCE_DEFAULT_FROM', date('Y-m-d', strtotime('yesterday')));
define('ADMIN_SOURCE_DEFAULT_TO', date('Y-m-d', strtotime('yesterday')));
*/
define('ADMIN_SOURCE_DEFAULT_FROM', '2026-10-01');
define('ADMIN_SOURCE_DEFAULT_TO', '2026-10-19');
define('ADMIN_SOURCE_LOGIN_PATH', '/index.php/sysapp/Login/Login');

define('ADMIN_SOURCE_COMPANIES', ['MY', 'SG']);

define('ADMIN_SOURCE_REPORT_PATH', '/index.php/report/RepDPProduction/printTaxInvoice');

/**
 * Builds the same base64 `param` the admin system uses. Decoded it looks like:
 *   MY______01-10-2026__20-10-2026__________________2____csb__________
 * i.e. COMPANY + 6x"_" + FROM (d-m-Y) + 2x"_" + TO (d-m-Y) + 18x"_" + "2" + 4x"_" + "csb" + 10x"_"
 */
function reportUrlFor(string $from, string $to, string $company): string
{
    $fromDmy = DateTime::createFromFormat('Y-m-d', $from)->format('d-m-Y');
    $toDmy = DateTime::createFromFormat('Y-m-d', $to)->format('d-m-Y');
    $raw = $company . str_repeat('_', 6) . $fromDmy . '__' . $toDmy
        . str_repeat('_', 18) . '2' . str_repeat('_', 4) . 'csb' . str_repeat('_', 10);
    // Same as the admin system's own links: plain base64 (only '+' is escaped so it is not read as a space).
    return buildAdminUrl(ADMIN_SOURCE_REPORT_PATH) . '?param=' . str_replace('+', '%2B', base64_encode($raw));
}

define('API_LOG_DIR', __DIR__ . '/logs');
define('API_LOG_PREFIX', 'taxinvoice_');
define('API_COOKIE_FILE', sys_get_temp_dir() . '/taxinvoice_cookie.json');
define('API_HTTP_TIMEOUT', 300);
define('API_HEADER_HINTS', ['commissionmonth', 'itemcode']);
define('API_COMMIT_EVERY', 1000);

/* =======================================================================
 * LOGGING / ACCESS / LOCK
 * ===================================================================== */

function writeApiLog(string $message, string $level = 'INFO'): void
{
    if (!is_dir(API_LOG_DIR)) {
        @mkdir(API_LOG_DIR, 0775, true);
    }
    $logFile = API_LOG_DIR . '/' . API_LOG_PREFIX . date('Y-m-d') . '.log';
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/** Prevents two runs of the same script overlapping (cookie file + DB upserts). */
function acquireLock(string $name)
{
    $fp = fopen(sys_get_temp_dir() . '/' . $name . '.lock', 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another run of ' . $name . ' is still in progress.');
    }
    return $fp;
}

function resolveDates(): array
{
    $from = (string) ($_GET['from'] ?? ($_SERVER['argv'][1] ?? ADMIN_SOURCE_DEFAULT_FROM));
    $to   = (string) ($_GET['to']   ?? ($_SERVER['argv'][2] ?? ADMIN_SOURCE_DEFAULT_TO));

    foreach ([$from, $to] as $d) {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        if (!$dt || $dt->format('Y-m-d') !== $d) {
            throw new InvalidArgumentException('Invalid date "' . $d . '". Use Y-m-d.');
        }
    }
    if ($from > $to) {
        throw new InvalidArgumentException('from date is after to date.');
    }
    return [$from, $to];
}

function resolveCompanies(): array
{
    $req = strtoupper(trim((string) ($_GET['company'] ?? ($_SERVER['argv'][3] ?? ''))));
    if ($req === '' || $req === 'ALL') {
        return ADMIN_SOURCE_COMPANIES;
    }
    if (!in_array($req, ADMIN_SOURCE_COMPANIES, true)) {
        throw new InvalidArgumentException('Unknown company "' . $req . '". Use ' . implode(' / ', ADMIN_SOURCE_COMPANIES) . '.');
    }
    return [$req];
}

/* =======================================================================
 * HTTP layer (cURL-free): file_get_contents + manual cookie jar (JSON)
 * ===================================================================== */

function loadCookiesFromFile(string $cookieFile): array
{
    if (!file_exists($cookieFile)) {
        return [];
    }
    $cookies = json_decode((string) file_get_contents($cookieFile), true);
    return is_array($cookies) ? $cookies : [];
}

function saveCookiesToFile(string $cookieFile, array $cookies): void
{
    file_put_contents($cookieFile, json_encode($cookies), LOCK_EX);
}

function cookieHeaderString(array $cookies): string
{
    $parts = [];
    foreach ($cookies as $name => $value) {
        $parts[] = $name . '=' . $value;
    }
    return implode('; ', $parts);
}

function parseSetCookieHeaders(array $headers): array
{
    $cookies = [];
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $cookiePart = trim(substr($header, strlen('Set-Cookie:')));
            $nameValue = explode(';', $cookiePart, 2)[0];
            $eqPos = strpos($nameValue, '=');
            if ($eqPos !== false) {
                $name = trim(substr($nameValue, 0, $eqPos));
                $value = trim(substr($nameValue, $eqPos + 1));
                if ($name !== '') {
                    $cookies[$name] = $value;
                }
            }
        }
    }
    return $cookies;
}

function httpRequest(string $method, string $url, string $cookieFile, array $options = []): array
{
    $cookies = loadCookiesFromFile($cookieFile);

    $headerLines = [];
    foreach (($options['headers'] ?? []) as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }
    if (!empty($cookies)) {
        $headerLines[] = 'Cookie: ' . cookieHeaderString($cookies);
    }

    $body = null;
    if ($method === 'POST' && isset($options['postFields'])) {
        $body = $options['postFields'];
        $hasContentType = false;
        foreach ($headerLines as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType) {
            $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'timeout' => API_HTTP_TIMEOUT,
            'follow_location' => 1,
            'max_redirects' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];
    if ($body !== null) {
        $contextOptions['http']['content'] = $body;
    }

    $responseBody = @file_get_contents($url, false, stream_context_create($contextOptions));
    $error = '';
    $httpCode = 0;

    if ($responseBody === false) {
        $lastErr = error_get_last();
        $error = $lastErr['message'] ?? 'Unknown stream error';
    }

    $responseHeaders = $http_response_header ?? [];
    if (!empty($responseHeaders) && preg_match('/^HTTP\/\S+\s+(\d+)/', $responseHeaders[0], $m)) {
        $httpCode = (int) $m[1];
    }

    $newCookies = parseSetCookieHeaders($responseHeaders);
    if (!empty($newCookies)) {
        saveCookiesToFile($cookieFile, array_merge($cookies, $newCookies));
    }

    return [
        'http_code' => $httpCode,
        'body' => is_string($responseBody) ? $responseBody : '',
        'error' => $error,
        'headers' => $responseHeaders,
    ];
}

/* =======================================================================
 * Login
 * ===================================================================== */

function buildAdminUrl(string $path): string
{
    return rtrim(ADMIN_SOURCE_BASE_URL, '/') . '/' . ltrim($path, '/');
}

function parseCsrfToken(string $html): string
{
    $patterns = [
        '/name=["\']_token["\']\s+value=["\']([^"\']+)["\']/i',
        '/name=["\']csrf["\']\s+value=["\']([^"\']+)["\']/i',
        '/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i',
        '/<meta\s+name=["\']csrf-token["\']\s+content=["\']([^"\']+)["\']/i',
        '/<input[^>]+name=["\'](csrf|_token|csrf_token)["\'][^>]+value=["\']([^"\']+)["\']/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            return trim($matches[2] ?? $matches[1] ?? '');
        }
    }
    return '';
}

function extractFormAction(string $html, string $default): string
{
    if (preg_match('/<form\b[^>]*action=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
        return $m[1];
    }
    return $default;
}

function looksLikeLoginPage(string $html): bool
{
    return preg_match('/<input[^>]+name=["\']?lbusername["\']?/i', $html) === 1
        || preg_match('/<input[^>]+name=["\']?lbpasswd["\']?/i', $html) === 1;
}

function loginAndGetSession(string $cookieFile): void
{
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }

    $lastError = 'No login candidate was reachable.';

    foreach (['/', '/index.php'] as $path) {
        $url = buildAdminUrl($path);
        $page = httpRequest('GET', $url, $cookieFile);

        if ($page['http_code'] >= 400 || $page['error'] !== '') {
            $lastError = $page['error'] !== '' ? $page['error'] : 'HTTP ' . $page['http_code'];
            continue;
        }

        $payload = [
            'cblanguage' => 'en_us',
            'lbusername' => ADMIN_SOURCE_USERNAME,
            'lbpasswd' => ADMIN_SOURCE_PASSWORD,
        ];
        $csrfToken = parseCsrfToken($page['body']);
        if ($csrfToken !== '') {
            $payload['_token'] = $csrfToken;
            $payload['csrf'] = $csrfToken;
            $payload['csrf_token'] = $csrfToken;
        }

        $actionUrl = extractFormAction($page['body'], $url);
        $submitUrl = preg_match('/^https?:\/\//i', $actionUrl) ? $actionUrl : buildAdminUrl($actionUrl);
        if (strpos($submitUrl, 'sysapp/Login/Login') !== false || strpos($actionUrl, 'sysapp/Login/Login') !== false) {
            $submitUrl = buildAdminUrl(ADMIN_SOURCE_LOGIN_PATH);
        }

        $postResult = httpRequest('POST', $submitUrl, $cookieFile, [
            'postFields' => http_build_query($payload),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json, text/plain, */*',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ]);

        if ($postResult['error'] !== '') {
            $lastError = $postResult['error'];
            continue;
        }

        $body = strtolower($postResult['body']);
        $decoded = json_decode($postResult['body'], true);
        $jsonMsg = is_array($decoded) && isset($decoded['msg']) ? strtoupper((string) $decoded['msg']) : '';

        $desktop = httpRequest('GET', buildAdminUrl('/index.php/sysapp/Desktop'), $cookieFile);
        $desktopBody = strtolower($desktop['body']);
        $desktopSuccess = $desktop['http_code'] < 400
            && !looksLikeLoginPage($desktop['body'])
            && (strpos($desktopBody, 'sysapp/desktop') !== false
                || strpos($desktopBody, 'desktop') !== false
                || strpos($desktopBody, 'logout') !== false);

        $success = $jsonMsg === 'OK'
            || (strpos($body, 'document.location') !== false && strpos($body, 'index.php/sysapp/desktop') !== false)
            || $desktopSuccess;

        if ($success) {
            return;
        }

        $loginPageStillVisible = looksLikeLoginPage($postResult['body'])
            || preg_match('/<form[^>]*name=["\']?login/i', $postResult['body']) === 1;

        if (!$loginPageStillVisible && !$desktopSuccess) {
            return;
        }

        foreach (['invalid credentials', 'username or password', 'login failed', 'incorrect password',
                  'wrong password', 'please sign in', 'login to continue', 'invalid code', 'warning'] as $marker) {
            if (strpos($body, $marker) !== false || strpos($desktopBody, $marker) !== false) {
                $lastError = 'Login failed: ' . $marker;
                writeApiLog('Login response body: ' . trim(substr($postResult['body'], 0, 2000)), 'DEBUG');
                break 2;
            }
        }
    }

    throw new RuntimeException('Unable to login to admin source. Last error: ' . $lastError);
}

/* =======================================================================
 * Report fetch + parse (xlsx / html table / csv are auto-detected)
 * ===================================================================== */

function fetchReport(string $from, string $to, string $company): string
{
    $url = reportUrlFor($from, $to, $company);
    writeApiLog('Fetching report path: ' . (string) parse_url($url, PHP_URL_PATH));

    $result = httpRequest('GET', $url, API_COOKIE_FILE);

    if ($result['error'] !== '') {
        throw new RuntimeException('Fetch report failed: ' . $result['error']);
    }
    if ($result['http_code'] >= 400) {
        throw new RuntimeException('Fetch report failed with HTTP status ' . $result['http_code']);
    }
    if (looksLikeLoginPage($result['body'])) {
        throw new RuntimeException('Report returned the login page - session not accepted.');
    }
    return $result['body'];
}

function rowIsEmpty(array $cells): bool
{
    foreach ($cells as $c) {
        if (trim((string) $c) !== '') {
            return false;
        }
    }
    return true;
}

function readXlsxRows(string $body): array
{
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Reader\Xlsx')) {
        throw new RuntimeException('Report is .xlsx but PhpSpreadsheet is not installed (vendor/autoload.php).');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'rpt_');
    file_put_contents($tmp, $body);
    try {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        return $reader->load($tmp)->getActiveSheet()->toArray(null, true, true, false);
    } finally {
        @unlink($tmp);
    }
}

function readHtmlRows(string $body): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body);
    libxml_clear_errors();

    $rows = [];
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $node) {
            if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), ['td', 'th'], true)) {
                $cells[] = trim(preg_replace('/\s+/', ' ', $node->textContent ?? ''));
            }
        }
        if ($cells) {
            $rows[] = $cells;
        }
    }
    return $rows;
}

function readCsvRows(string $body): array
{
    $firstLine = '';
    foreach (preg_split('/\r\n|\r|\n/', $body) as $l) {
        if (trim($l) !== '') {
            $firstLine = $l;
            break;
        }
    }
    $delimiter = ',';
    $best = 0;
    foreach ([',', ';', "\t", '|'] as $d) {
        $n = substr_count($firstLine, $d);
        if ($n > $best) {
            $best = $n;
            $delimiter = $d;
        }
    }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    $rows = [];
    while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
        $rows[] = array_map(static fn ($c) => (string) $c, $cells);
    }
    fclose($fh);
    return $rows;
}

/** Returns rows where rows[0] is the header row (located using $hints = normalized header names). */
function parseReportToRows(string $body, array $hints): array
{
    if (substr($body, 0, 2) === 'PK') {
        $rows = readXlsxRows($body);
    } else {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        if (trim($body) === '') {
            return [];
        }
        $rows = preg_match('/<\s*(table|tr)\b/i', $body) === 1 ? readHtmlRows($body) : readCsvRows($body);
    }

    foreach ($rows as $i => $cells) {
        $norm = array_map('normalizeHeader', $cells);
        if (array_intersect($hints, $norm)) {
            return array_values(array_filter(array_slice($rows, $i), static fn ($r) => !rowIsEmpty($r)));
        }
    }
    return [];
}

/* =======================================================================
 * Value helpers (same behaviour as the upload page)
 * ===================================================================== */

function normalizeHeader($header): string
{
    if ($header === null) {
        return '';
    }
    $header = str_replace("\xEF\xBB\xBF", '', trim((string) $header));
    return preg_replace('/[^a-z0-9]/', '', strtolower($header));
}

function getRowValue(array $row, array $headerMap, array $keys)
{
    foreach ($keys as $key) {
        $n = normalizeHeader($key);
        if (isset($headerMap[$n])) {
            return $row[$headerMap[$n]] ?? '';
        }
    }
    return '';
}

function parseDate($value): ?string
{
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i:s');
    }
    if ($value === null || trim((string) $value) === '') {
        return null;
    }
    $value = trim((string) $value);
    if (is_numeric($value) && $value > 40000 && $value < 60000) {
        return date('Y-m-d H:i:s', (int) (($value - 25569) * 86400));
    }
    foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
              'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date !== false) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function parseAmount($value): float
{
    if ($value === null || trim((string) $value) === '') {
        return 0.00;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }
    return (float) str_replace(['RM', 'SGD', 'USD', ',', ' ', '(', ')', '"'], '', trim((string) $value));
}

function getCompany(string $orderId, PDO $pdo): array
{
    static $cache = [];

    $orderId = strtoupper(trim($orderId));
    $prefix = 'MYHQ';
    if (strpos($orderId, 'MYHQ') === 0) $prefix = 'MYHQ';
    elseif (strpos($orderId, 'MYBT') === 0) $prefix = 'MYBT';
    elseif (strpos($orderId, 'SGHQ') === 0 || strpos($orderId, 'SG') === 0) $prefix = 'SGHQ';
    elseif (strpos($orderId, 'BN') === 0) $prefix = 'BNHQ';

    if (isset($cache[$prefix])) {
        return $cache[$prefix];
    }

    $map = [
        'MYHQ' => ['code' => 'MY', 'currency' => 'MYR'],
        'MYBT' => ['code' => 'MY', 'currency' => 'MYR'],
        'SGHQ' => ['code' => 'SG', 'currency' => 'SGD'],
        'BNHQ' => ['code' => 'BN', 'currency' => 'BND'],
    ];
    $companyCode = $map[$prefix]['code'];
    $currency = $map[$prefix]['currency'];

    $stmt = $pdo->prepare('SELECT id, invoice_prefix FROM companies WHERE company_code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$companyCode]);
    $result = $stmt->fetch();

    if (!$result) {
        $pdo->prepare('INSERT INTO companies (company_code, company_name, currency_code, invoice_prefix) VALUES (?, ?, ?, ?)')
            ->execute([$companyCode, $companyCode === 'MY' ? 'Malaysia' : $companyCode, $currency, $prefix]);
        $companyId = (int) $pdo->lastInsertId();
    } else {
        $companyId = (int) $result['id'];
        $prefixes = array_map('trim', explode(',', (string) $result['invoice_prefix']));
        if (!in_array($prefix, $prefixes, true)) {
            $prefixes[] = $prefix;
            $pdo->prepare('UPDATE companies SET invoice_prefix = ? WHERE id = ?')->execute([implode(',', $prefixes), $companyId]);
        }
    }

    return $cache[$prefix] = [
        'company_id' => $companyId,
        'company_code' => $companyCode,
        'currency_code' => $currency,
        'invoice_prefix' => $prefix,
    ];
}

/* =======================================================================
 * Database / import_batches
 * ===================================================================== */

function buildPdo(): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** True if this exact report content was already imported successfully. Otherwise frees the hash for reuse. */
function batchAlreadyImported(PDO $pdo, string $hash): bool
{
    $stmt = $pdo->prepare('SELECT id, status FROM import_batches WHERE file_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $existing = $stmt->fetch();
    if (!$existing) {
        return false;
    }
    if ($existing['status'] === 'completed') {
        return true;
    }
    $pdo->prepare('UPDATE import_batches SET file_hash = NULL WHERE id = ?')->execute([$existing['id']]);
    return false;
}

function createBatch(PDO $pdo, int $companyId, string $fileType, string $filename, string $hash, int $totalRows): int
{
    $pdo->prepare(
        'INSERT INTO import_batches (company_id, file_type, original_filename, file_hash, total_rows, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$companyId, $fileType, $filename, $hash, $totalRows, 'processing']);
    return (int) $pdo->lastInsertId();
}

function finishBatch(PDO $pdo, int $batchId, int $success, int $failed): string
{
    $status = $failed > 0 ? 'completed_with_errors' : 'completed';
    $pdo->prepare('UPDATE import_batches SET successful_rows = ?, failed_rows = ?, status = ?, imported_at = NOW() WHERE id = ?')
        ->execute([$success, $failed, $status, $batchId]);
    return $status;
}

function failBatch(PDO $pdo, int $batchId, string $message): void
{
    try {
        $pdo->prepare('UPDATE import_batches SET status = ?, error_message = ? WHERE id = ?')
            ->execute(['failed', $message, $batchId]);
    } catch (Throwable $e) {
        writeApiLog('Could not mark batch ' . $batchId . ' as failed: ' . $e->getMessage(), 'ERROR');
    }
}

function outputResult(array $result): void
{
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        echo $json . PHP_EOL;
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
}

function runMain(callable $main): void
{
    try {
        $main();
    } catch (Throwable $e) {
        writeApiLog('Fatal error: ' . $e->getMessage(), 'ERROR');
        $response = ['success' => false, 'message' => $e->getMessage()];
        if (PHP_SAPI === 'cli') {
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit(1);
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* =======================================================================
 * Main
 * ===================================================================== */

/** True for footer/summary rows such as "Company Total : 1,688,703.30". These are not data and are ignored silently. */
function isTotalRow(array $row): bool
{
    foreach ($row as $cell) {
        if (preg_match('/^\s*(company\s+|grand\s+|sub\s*)?total\s*:/i', (string) $cell) === 1) {
            return true;
        }
    }
    return false;
}

function importTaxInvoice(PDO $pdo, string $companyCode, string $from, string $to): array
{
    $body = fetchReport($from, $to, $companyCode);
    $rows = parseReportToRows($body, API_HEADER_HINTS);

    if (count($rows) < 2) {
        writeApiLog($companyCode . ': report has no data rows for this range.', 'WARN');
        return ['company' => $companyCode, 'imported_rows' => 0, 'message' => 'No tax invoice rows in report for this range.'];
    }

    $headerMap = [];
    foreach ($rows[0] as $i => $h) {
        $headerMap[normalizeHeader($h)] = $i;
    }
    $hasItemCode = isset($headerMap['itemcode']) || isset($headerMap['sku']) || isset($headerMap['itemno']);
    $hasOrderId = isset($headerMap['orderid']) || isset($headerMap['orderno']);
    if (!isset($headerMap['commissionmonth']) || !$hasItemCode || !$hasOrderId) {
        throw new RuntimeException('Unexpected Tax Invoice headers: ' . implode(', ', $rows[0]));
    }

    $dataRows = array_slice($rows, 1);
    $hash = hash('sha256', $body);
    if (batchAlreadyImported($pdo, $hash)) {
        writeApiLog($companyCode . ': identical report already imported. Skipped.');
        return ['company' => $companyCode, 'skipped' => true, 'message' => 'Report content already imported.'];
    }

    $firstOrderId = '';
    foreach ($dataRows as $r) {
        $firstOrderId = trim((string) getRowValue($r, $headerMap, ['orderid', 'orderno']));
        if ($firstOrderId !== '') {
            break;
        }
    }
    $firstCompany = getCompany($firstOrderId, $pdo);

    $batchId = createBatch(
        $pdo,
        $firstCompany['company_id'],
        'TAX_INVOICE',
        'api_taxinvoice_' . $companyCode . '_' . $from . '_' . $to . '_' . date('His') . '.txt',
        $hash,
        count($dataRows)
    );
    writeApiLog($companyCode . ': created import batch ID ' . $batchId . ' rows=' . count($dataRows));

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, commission_month, cdo, cdo_created_date, product_type, price_code,
            item_code, item_description, brand, email, total_weight, qty, bv, pv, total_bv, total_pv,
            total_retail_price, discount, invoice_amount, total_invoice_amount_paid, order_processed_location)
         VALUES (' . implode(', ', array_fill(0, 21, '?')) . ')'
    );
    $orderLookup = $pdo->prepare('SELECT id FROM orders WHERE company_id = ? AND order_id = ? LIMIT 1');
    $deleteItems = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');

    $g = static fn (array $row, array $keys) => getRowValue($row, $headerMap, $keys);
    $success = 0;
    $failed = 0;
    $inTx = 0;
    $replaced = [];
    $orderIdCache = [];
    $missingOrders = [];

    try {
        $pdo->beginTransaction();

        foreach ($dataRows as $n => $row) {
            $orderId = trim((string) $g($row, ['orderid', 'orderno']));
            $itemCode = trim((string) $g($row, ['itemcode', 'sku', 'itemno']));

            if (isTotalRow($row) || ($orderId === '' && $itemCode === '')) {
                continue; // footer / blank row
            }

            if ($orderId === '' || $itemCode === '') {
                $failed++;
                writeApiLog($companyCode . ': skipped row ' . ($n + 2) . ': missing order id / item code', 'WARN');
                continue;
            }

            try {
                $comp = getCompany($orderId, $pdo);
                $cacheKey = $comp['company_id'] . '|' . $orderId;
                if (!array_key_exists($cacheKey, $orderIdCache)) {
                    $orderLookup->execute([$comp['company_id'], $orderId]);
                    $orderIdCache[$cacheKey] = $orderLookup->fetchColumn();
                }
                $orderDbId = $orderIdCache[$cacheKey];

                if (!$orderDbId) {
                    $failed++;
                    $missingOrders[$orderId] = true;
                    continue;
                }

                if (!isset($replaced[$orderDbId])) {
                    $deleteItems->execute([$orderDbId]);
                    $replaced[$orderDbId] = true;
                }

                $itemStmt->execute([
                    $orderDbId, $g($row, ['commissionmonth']), $g($row, ['cdo']),
                    parseDate($g($row, ['cdocreateddate'])), $g($row, ['producttype', 'porducttype']), $g($row, ['pricecode']),
                    $itemCode, $g($row, ['itemdescription']), $g($row, ['brand']), $g($row, ['email']),
                    parseAmount($g($row, ['totalweight'])), (int) parseAmount($g($row, ['qty'])),
                    parseAmount($g($row, ['bv'])), parseAmount($g($row, ['pv'])),
                    parseAmount($g($row, ['totalbv'])), parseAmount($g($row, ['totalpv'])),
                    parseAmount($g($row, ['totalretailprice'])), parseAmount($g($row, ['discount'])),
                    parseAmount($g($row, ['invoiceamount'])), parseAmount($g($row, ['totalinvoiceamountpaid'])),
                    $g($row, ['orderprocessedlocation']),
                ]);
                $success++;
            } catch (PDOException $e) {
                $failed++;
                writeApiLog($companyCode . ': row ' . ($n + 2) . ' (' . $orderId . ') failed: ' . $e->getMessage(), 'ERROR');
            }

            if (++$inTx >= API_COMMIT_EVERY) {
                $pdo->commit();
                $pdo->beginTransaction();
                $inTx = 0;
            }
        }

        $pdo->commit();
        $status = finishBatch($pdo, $batchId, $success, $failed);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        failBatch($pdo, $batchId, $e->getMessage());
        throw $e;
    }

    if ($missingOrders) {
        writeApiLog($companyCode . ': ' . count($missingOrders) . ' order(s) not found in orders table - run api_orderhistory.php first. Sample: '
            . implode(', ', array_slice(array_keys($missingOrders), 0, 20)), 'WARN');
    }
    writeApiLog($companyCode . ': import finished. imported=' . $success . ' failed=' . $failed . ' batch_id=' . $batchId . ' status=' . $status);

    return [
        'company' => $companyCode,
        'batch_id' => $batchId,
        'report_rows_found' => count($dataRows),
        'imported_rows' => $success,
        'failed_rows' => $failed,
        'orders_not_found' => count($missingOrders),
    ];
}

function main(): void
{
    @ini_set('max_execution_time', '0');
    @ini_set('memory_limit', '2048M');

    [$from, $to] = resolveDates();
    $companies = resolveCompanies();
    $lock = acquireLock('taxinvoice');
    writeApiLog('Starting tax invoice import. from=' . $from . ' to=' . $to . ' companies=' . implode(',', $companies));

    $pdo = buildPdo();
    loginAndGetSession(API_COOKIE_FILE);
    writeApiLog('Session login succeeded.');

    $results = [];
    $hadError = false;
    foreach ($companies as $code) {
        try {
            $results[] = importTaxInvoice($pdo, $code, $from, $to);
        } catch (Throwable $e) {
            $hadError = true;
            writeApiLog($code . ': ' . $e->getMessage(), 'ERROR');
            $results[] = ['company' => $code, 'error' => $e->getMessage()];
        }
    }

    outputResult(['success' => !$hadError, 'from' => $from, 'to' => $to, 'results' => $results]);
    flock($lock, LOCK_UN);
    if ($hadError && PHP_SAPI === 'cli') {
        exit(1);
    }
}

runMain('main');