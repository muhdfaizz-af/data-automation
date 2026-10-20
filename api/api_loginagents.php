<?php
/**
 * Import Hourly User Login Count report to login_agents table.
 *
 * Cron usage:
 *   php api/api_loginagents.php
 * Or with custom date range:
 *   php api/api_loginagents.php 2025-01-01 2026-09-24
 *
 * This script logs in to admin.e-saudagaar.com, fetches the report from the
 * protected URL, parses login timestamps, and inserts them into login_agents.
 *
 * Duplicate protection:
 *   - login_agents has a UNIQUE KEY on (member_code, login_time).
 *   - Same member + same timestamp is inserted ONCE only; repeats are skipped
 *     (INSERT ... ON DUPLICATE KEY UPDATE id = id).
 *   - Different members at the same timestamp are still allowed.
 *   - Because of this, re-running any date range is safe.
 *
 * NOTE: This version does NOT require the PHP cURL extension. HTTP requests
 * are made using file_get_contents() + stream_context_create() instead.
 * Cookies are tracked manually and persisted as JSON in $cookieFile.
 */

require_once __DIR__ . '/config.php';
/*
define('ADMIN_SOURCE_DEFAULT_FROM', date('Y-m-d', strtotime('yesterday')));
define('ADMIN_SOURCE_DEFAULT_TO', date('Y-m-d', strtotime('yesterday')));
*/
define('ADMIN_SOURCE_DEFAULT_FROM', '2025-01-01');
define('ADMIN_SOURCE_DEFAULT_TO', '2025-12-31');
define('ADMIN_SOURCE_LOGIN_PATH', '/index.php/sysapp/Login/Login');
define('ADMIN_SOURCE_REPORT_PATH', '/index.php/report/RepDailyBonusExport/print');
define('ADMIN_SOURCE_COMPANY_CODE', 'MY');
define('API_LOG_DIR', __DIR__ . '/logs');
define('LOGIN_AGENT_COOKIE_FILE', sys_get_temp_dir() . '/login_agents_cookie.json');
define('AGENT_LOGIN_FILE_PREFIX', 'agentlogin_');
define('AGENT_LOGIN_INSERT_BATCH_SIZE', 500);

function writeApiLog(string $message, string $level = 'INFO'): void
{
    if (!is_dir(API_LOG_DIR)) {
        @mkdir(API_LOG_DIR, 0775, true);
    }

    $logFile = API_LOG_DIR . '/login_agents_' . date('Y-m-d') . '.log';
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/* -----------------------------------------------------------------------
 * HTTP layer (cURL-free). Uses file_get_contents() + stream_context_create()
 * and tracks cookies manually as a simple JSON name=>value store on disk.
 * --------------------------------------------------------------------- */

function loadCookiesFromFile(string $cookieFile): array
{
    if (!file_exists($cookieFile)) {
        return [];
    }
    $raw = file_get_contents($cookieFile);
    $cookies = json_decode((string) $raw, true);
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
    if (isset($options['headers']) && is_array($options['headers'])) {
        foreach ($options['headers'] as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
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
            'timeout' => 60,
            'follow_location' => 1,
            'max_redirects' => 10,
            'ignore_errors' => true, // still return body on 4xx/5xx
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];

    if ($body !== null) {
        $contextOptions['http']['content'] = $body;
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents($url, false, $context);
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
        $cookies = array_merge($cookies, $newCookies);
        saveCookiesToFile($cookieFile, $cookies);
    }

    return [
        'http_code' => $httpCode,
        'body' => is_string($responseBody) ? $responseBody : '',
        'error' => $error,
    ];
}

/* -----------------------------------------------------------------------
 * Login + report parsing
 * --------------------------------------------------------------------- */

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
            return trim($matches[1] ?? $matches[2] ?? '');
        }
    }

    return '';
}

function extractFormFields(string $html, string $defaultAction): array
{
    $fields = [
        'action' => $defaultAction,
        'method' => 'POST',
        'inputs' => [],
    ];

    if (preg_match('/<form\b[^>]*action=["\']([^"\']+)["\'][^>]*>/i', $html, $formMatch)) {
        $fields['action'] = $formMatch[1];
    }

    if (preg_match_all('/<input\s+[^>]*name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $name = trim($match[1]);
            $value = trim($match[2]);
            if ($name !== '') {
                $fields['inputs'][$name] = $value;
            }
        }
    }

    if (preg_match_all('/<input\s+[^>]*name=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $name = trim($match[1]);
            if ($name !== '' && !isset($fields['inputs'][$name])) {
                $fields['inputs'][$name] = '';
            }
        }
    }

    return $fields;
}

function buildAdminUrl(string $path): string
{
    return rtrim(ADMIN_SOURCE_BASE_URL, '/') . '/' . ltrim($path, '/');
}

function loginAndGetSession(string $cookieFile): void
{
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }

    $loginCandidates = [
        '/',
        '/index.php',
    ];

    $lastError = 'No login candidate was reachable.';

    foreach ($loginCandidates as $path) {
        $url = buildAdminUrl($path);
        $page = httpRequest('GET', $url, $cookieFile);

        if ($page['http_code'] >= 400 || $page['error'] !== '') {
            $lastError = $page['error'] !== '' ? $page['error'] : 'HTTP ' . $page['http_code'];
            continue;
        }

        $form = extractFormFields($page['body'], $url);
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

        $actionUrl = $form['action'];
        $submitUrl = preg_match('/^https?:\/\//i', $actionUrl) ? $actionUrl : buildAdminUrl($actionUrl);
        $loginEndpoint = buildAdminUrl('/index.php/sysapp/Login/Login');
        if (strpos($submitUrl, 'sysapp/Login/Login') !== false || strpos($actionUrl, 'sysapp/Login/Login') !== false) {
            $submitUrl = $loginEndpoint;
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

        $body = strtolower($postResult['body'] ?? '');
        $decoded = json_decode($postResult['body'] ?? '', true);
        $jsonMsg = is_array($decoded) && isset($decoded['msg']) ? strtoupper((string) $decoded['msg']) : '';

        $desktopCheck = httpRequest('GET', buildAdminUrl('/index.php/sysapp/Desktop'), $cookieFile);
        $desktopBody = strtolower($desktopCheck['body'] ?? '');
        $desktopSuccess = (
            $desktopCheck['http_code'] < 400
            && !preg_match('/<input[^>]+name=["\']?lbusername["\']?/i', $desktopCheck['body'] ?? '')
            && !preg_match('/<input[^>]+name=["\']?lbpasswd["\']?/i', $desktopCheck['body'] ?? '')
            && (
                stripos($desktopCheck['body'] ?? '', 'index.php/sysapp/Desktop') !== false
                || stripos($desktopCheck['body'] ?? '', 'sysapp/Desktop') !== false
                || stripos($desktopCheck['body'] ?? '', 'desktop') !== false
                || stripos($desktopCheck['body'] ?? '', 'logout') !== false
            )
        );

        $success = (
            $jsonMsg === 'OK'
        ) || (
            stripos($postResult['body'] ?? '', 'document.location') !== false && stripos($postResult['body'] ?? '', 'index.php/sysapp/desktop') !== false
        ) || $desktopSuccess;

        if ($success) {
            return;
        }

        $loginFailedMarkers = [
            'invalid credentials',
            'username or password',
            'login failed',
            'incorrect password',
            'wrong password',
            'please sign in',
            'login to continue',
            'invalid code',
            'warning',
        ];

        $loginPageStillVisible = (
            preg_match('/<input[^>]+name=["\']?lbusername["\']?/i', $postResult['body'] ?? '') === 1
            || preg_match('/<input[^>]+name=["\']?lbpasswd["\']?/i', $postResult['body'] ?? '') === 1
            || preg_match('/<form[^>]*name=["\']?login/i', $postResult['body'] ?? '') === 1
        );

        if (!$loginPageStillVisible && !$desktopSuccess) {
            return;
        }

        foreach ($loginFailedMarkers as $marker) {
            if (strpos($body, $marker) !== false || strpos($desktopBody, $marker) !== false) {
                $lastError = 'Login failed: ' . $marker;
                writeApiLog('Login response body: ' . trim(substr($postResult['body'] ?? '', 0, 2000)), 'DEBUG');
                break 2;
            }
        }
    }

    throw new RuntimeException('Unable to login to admin source. Last error: ' . $lastError);
}

function fetchReportPage(string $fromDate, string $toDate, string $cookieFile): string
{
    $reportUrl = buildAdminUrl(ADMIN_SOURCE_REPORT_PATH)
        . '?f_report_type=usrlog'
        . '&f_batch_from=' . rawurlencode($fromDate)
        . '&f_batch_to=' . rawurlencode($toDate);

    $result = httpRequest('GET', $reportUrl, $cookieFile);

    if ($result['error'] !== '') {
        throw new RuntimeException('Fetch report failed: ' . $result['error']);
    }

    if ($result['http_code'] >= 400) {
        throw new RuntimeException('Fetch report failed with HTTP status ' . $result['http_code']);
    }

    return $result['body'];
}

function parseDelimitedRow(string $line): array
{
    $line = preg_replace('/\r?\n/', '', $line);
    $line = trim($line);
    if ($line === '') {
        return [];
    }

    $delimiters = ["\t", ';', ','];
    $bestDelimiter = "\t";
    $bestCount = 0;

    foreach ($delimiters as $delimiter) {
        $parts = explode($delimiter, $line);
        $count = count($parts);
        if ($count > $bestCount) {
            $bestCount = $count;
            $bestDelimiter = $delimiter;
        }
    }

    $parts = array_map('trim', explode($bestDelimiter, $line));
    return array_values(array_filter($parts, static fn ($v) => $v !== ''));
}

function parseReportRows(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $rows = [];
    $isHeaderRow = static function (array $cells): bool {
        $normalized = array_map(static function ($cell) {
            return strtolower(trim((string) $cell));
        }, $cells);

        $joined = implode(' ', $normalized);
        return strpos($joined, 'member code') !== false
            || strpos($joined, 'member name') !== false
            || strpos($joined, 'login times') !== false
            || strpos($joined, 'login count') !== false;
    };

    if (preg_match('/<\s*(table|tr|td|th)\b/i', $raw) === 1) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $raw);
        libxml_clear_errors();

        $tableRows = $dom->getElementsByTagName('tr');
        foreach ($tableRows as $tr) {
            $cells = [];
            foreach ($tr->getElementsByTagName('td') as $td) {
                $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent ?? ''));
            }

            if (count($cells) >= 4) {
                if ($isHeaderRow($cells)) {
                    continue;
                }

                $memberCode = trim((string) $cells[0]);
                $memberName = trim((string) $cells[1]);
                $loginTimesRaw = trim((string) $cells[3]);

                if ($memberCode === '' || $loginTimesRaw === '') {
                    continue;
                }

                $loginTimes = array_filter(array_map('trim', preg_split('/[,;\\n]+/', $loginTimesRaw) ?: []));
                $rows[] = [
                    'member_code' => $memberCode,
                    'member_name' => $memberName,
                    'login_times' => array_values($loginTimes),
                ];
            }
        }

        return $rows;
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw);
    foreach ($lines as $line) {
        $cols = parseDelimitedRow($line);
        if (count($cols) < 4) {
            continue;
        }

        if ($isHeaderRow($cols)) {
            continue;
        }

        $memberCode = trim($cols[0]);
        $memberName = trim($cols[1]);
        $loginTimesRaw = trim(implode(' ', array_slice($cols, 3)));

        if ($memberCode === '' || $loginTimesRaw === '') {
            continue;
        }

        $loginTimes = array_filter(array_map('trim', preg_split('/[,;\\n]+/', $loginTimesRaw) ?: []));
        $rows[] = [
            'member_code' => $memberCode,
            'member_name' => $memberName,
            'login_times' => array_values($loginTimes),
        ];
    }

    return $rows;
}

/* -----------------------------------------------------------------------
 * Database
 * --------------------------------------------------------------------- */

function buildPdo(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function validLoginTime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            $errors = DateTime::getLastErrors();
            if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            return $dt->format('Y-m-d H:i:s');
        }
    }

    return null;
}

/**
 * Insert a batch of rows using one multi-row INSERT, inside a transaction.
 *
 * Rows whose (member_code, login_time) already exist are skipped silently
 * thanks to the UNIQUE KEY uq_login_agents_member_time and
 * ON DUPLICATE KEY UPDATE id = id (a no-op update, so rowCount() = 0 for them).
 *
 * $rows is an array of [member_code, member_name, login_time, import_batch_id].
 *
 * @return array{0:int,1:int} [inserted, skipped_duplicates]
 */
function flushInsertBatch(PDO $pdo, array $rows): array
{
    if (empty($rows)) {
        return [0, 0];
    }

    $placeholders = [];
    $params = [];
    foreach ($rows as $row) {
        $placeholders[] = '(?, ?, ?, ?)';
        array_push($params, $row[0], $row[1], $row[2], $row[3]);
    }

    $sql = 'INSERT INTO login_agents (member_code, member_name, login_time, import_batch_id) VALUES '
        . implode(', ', $placeholders)
        . ' ON DUPLICATE KEY UPDATE id = id';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inserted = $stmt->rowCount(); // skipped duplicates count as 0
        $pdo->commit();
        return [$inserted, count($rows) - $inserted];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function outputResult(array $result): void
{
    if (PHP_SAPI === 'cli') {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/* -----------------------------------------------------------------------
 * Main
 * --------------------------------------------------------------------- */

function main(): void
{
    $fromDate = $_GET['from'] ?? ($_SERVER['argv'][1] ?? ADMIN_SOURCE_DEFAULT_FROM);
    $toDate = $_GET['to'] ?? ($_SERVER['argv'][2] ?? ADMIN_SOURCE_DEFAULT_TO);

    writeApiLog('Starting login-agent import run. from=' . $fromDate . ' to=' . $toDate);

    $pdo = buildPdo();

    $companyStmt = $pdo->prepare('SELECT id FROM companies WHERE company_code = :code LIMIT 1');
    $companyStmt->execute([':code' => ADMIN_SOURCE_COMPANY_CODE]);
    $companyId = $companyStmt->fetchColumn();

    if (!$companyId) {
        writeApiLog('Company code ' . ADMIN_SOURCE_COMPANY_CODE . ' not found in companies table.', 'ERROR');
        throw new RuntimeException('Company code ' . ADMIN_SOURCE_COMPANY_CODE . ' not found in companies table.');
    }

    writeApiLog('Company ID resolved: ' . $companyId . ' for code ' . ADMIN_SOURCE_COMPANY_CODE);

    $batchStmt = $pdo->prepare(
        "INSERT INTO import_batches
            (company_id, file_type, original_filename, status, created_at)
         VALUES
            (:company_id, 'AGENT_LOGIN', :filename, 'processing', NOW())"
    );
    $batchStmt->execute([
        ':company_id' => (int) $companyId,
        ':filename' => AGENT_LOGIN_FILE_PREFIX . $fromDate . '_' . $toDate . '_' . date('His') . '.txt',
    ]);
    $importBatchId = (int) $pdo->lastInsertId();

    writeApiLog('Created import batch ID ' . $importBatchId);

    loginAndGetSession(LOGIN_AGENT_COOKIE_FILE);
    writeApiLog('Session login succeeded. Fetching report from admin source.');

    $reportBody = fetchReportPage($fromDate, $toDate, LOGIN_AGENT_COOKIE_FILE);
    $reportRows = parseReportRows($reportBody);
    writeApiLog('Fetched report content; rows detected: ' . count($reportRows));

    $successCount = 0;
    $failedCount = 0;
    $duplicateCount = 0;
    $seen = [];          // in-memory dedupe within this report
    $pendingRows = [];
    $batchSize = AGENT_LOGIN_INSERT_BATCH_SIZE;

    $flush = function () use ($pdo, &$pendingRows, &$successCount, &$failedCount, &$duplicateCount) {
        if (empty($pendingRows)) {
            return;
        }
        try {
            [$ins, $dup] = flushInsertBatch($pdo, $pendingRows);
            $successCount += $ins;
            $duplicateCount += $dup;
        } catch (Throwable $e) {
            $failedCount += count($pendingRows);
            writeApiLog('Batch insert failed (' . count($pendingRows) . ' rows): ' . $e->getMessage(), 'ERROR');
        }
        $pendingRows = [];
    };

    foreach ($reportRows as $row) {
        $memberCode = trim((string) ($row['member_code'] ?? ''));
        $memberName = trim((string) ($row['member_name'] ?? ''));

        foreach (($row['login_times'] ?? []) as $loginTimeRaw) {
            $loginTime = validLoginTime((string) $loginTimeRaw);
            if ($loginTime === null) {
                $failedCount++;
                writeApiLog('Skipped invalid login timestamp for member ' . $memberCode . ': ' . $loginTimeRaw, 'WARN');
                continue;
            }

            // Same member + same timestamp already seen in this report -> skip
            $key = $memberCode . '|' . $loginTime;
            if (isset($seen[$key])) {
                $duplicateCount++;
                continue;
            }
            $seen[$key] = true;

            $pendingRows[] = [
                $memberCode,
                $memberName !== '' ? $memberName : null,
                $loginTime,
                $importBatchId,
            ];

            if (count($pendingRows) >= $batchSize) {
                $flush();
            }
        }
    }

    // Flush any remaining rows that didn't fill a full batch.
    $flush();

    $totalRows = $successCount + $failedCount + $duplicateCount;

    $updateBatchStmt = $pdo->prepare(
        "UPDATE import_batches
         SET total_rows = :total,
             successful_rows = :success,
             failed_rows = :failed,
             status = :status,
             imported_at = NOW()
         WHERE id = :id"
    );
    $updateBatchStmt->execute([
        ':total' => $totalRows,
        ':success' => $successCount,
        ':failed' => $failedCount,
        ':status' => $failedCount > 0 ? 'completed_with_errors' : 'completed',
        ':id' => $importBatchId,
    ]);

    writeApiLog(
        'Import finished. inserted=' . $successCount
        . ' duplicates_skipped=' . $duplicateCount
        . ' failed=' . $failedCount
        . ' total=' . $totalRows
        . ' batch_id=' . $importBatchId
    );

    outputResult([
        'success' => true,
        'batch_id' => $importBatchId,
        'from' => $fromDate,
        'to' => $toDate,
        'report_rows_found' => count($reportRows),
        'inserted_rows' => $successCount,
        'duplicate_skipped' => $duplicateCount,
        'failed_rows' => $failedCount,
        'message' => 'Login report imported successfully.',
    ]);
}

try {
    main();
} catch (Throwable $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
    ];

    writeApiLog('Fatal error: ' . $e->getMessage(), 'ERROR');

    if (PHP_SAPI === 'cli') {
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}