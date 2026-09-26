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
 *   - login_agents has NO unique key on (member_code, login_time) by design,
 *     since the source can legitimately report two different members logging
 *     in at the exact same timestamp.
 *   - Instead, we guard against the whole date range being imported twice
 *     (e.g. cron firing twice, or a manual run overlapping with cron) by
 *     checking import_batches before doing any work. See alreadyImported().
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
define('ADMIN_SOURCE_DEFAULT_TO', '2026-09-24');
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
 * Everything below is unchanged from the original cURL-based script.
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
 * Guard against importing the same (company, date range) more than once.
 *
 * login_agents has no unique key on (member_code, login_time) by design
 * (source can legitimately report two different members logging in at the
 * exact same timestamp), so duplicate protection happens here instead: we
 * check import_batches for a prior successful run covering this exact
 * from/to range, identified via the original_filename prefix written by
 * this script (see main()).
 */
function alreadyImported(PDO $pdo, int $companyId, string $fromDate, string $toDate): bool
{
    $prefix = AGENT_LOGIN_FILE_PREFIX . $fromDate . '_' . $toDate . '_';

    $stmt = $pdo->prepare(
        "SELECT id FROM import_batches
         WHERE company_id = :company_id
           AND file_type = 'AGENT_LOGIN'
           AND status IN ('completed', 'completed_with_errors')
           AND original_filename LIKE :prefix
         LIMIT 1"
    );
    $stmt->execute([
        ':company_id' => $companyId,
        ':prefix' => $prefix . '%',
    ]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Preload all known member_code values into a lookup set (member_code => true).
 *
 * This replaces doing a SELECT per login-time row. For very large `members`
 * tables this is still one single query + one pass to build the array,
 * which is far cheaper than millions of round-trips.
 */
function loadMemberCodeSet(PDO $pdo): array
{
    $set = [];
    $stmt = $pdo->query('SELECT member_code FROM members');
    while (($code = $stmt->fetchColumn()) !== false) {
        $set[(string) $code] = true;
    }
    $stmt->closeCursor();

    return $set;
}

/**
 * Flush a batch of prepared insert rows using one multi-row INSERT statement,
 * wrapped in a transaction. Returns the number of rows inserted.
 *
 * $rows is an array of [member_code, member_name, login_time, import_batch_id].
 */
function flushInsertBatch(PDO $pdo, array $rows): int
{
    if (empty($rows)) {
        return 0;
    }

    $placeholders = [];
    $params = [];
    foreach ($rows as $row) {
        $placeholders[] = '(?, ?, ?, ?)';
        $params[] = $row[0];
        $params[] = $row[1];
        $params[] = $row[2];
        $params[] = $row[3];
    }

    $sql = 'INSERT INTO login_agents (member_code, member_name, login_time, import_batch_id) VALUES '
        . implode(', ', $placeholders);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pdo->commit();
        return count($rows);
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

    // Idempotency guard: skip entirely if this exact date range was already
    // imported successfully before (e.g. cron fired twice, or manual run
    // overlapped with cron).
    if (alreadyImported($pdo, (int) $companyId, $fromDate, $toDate)) {
        $message = "Date range {$fromDate} to {$toDate} already imported for company " . ADMIN_SOURCE_COMPANY_CODE . '; skipping to avoid duplicate import.';
        writeApiLog($message, 'INFO');
        outputResult([
            'success' => true,
            'skipped' => true,
            'from' => $fromDate,
            'to' => $toDate,
            'message' => $message,
        ]);
        return;
    }

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

    // Preload valid member codes once instead of querying per login-time row.
    $memberCodeSet = loadMemberCodeSet($pdo);
    writeApiLog('Preloaded ' . count($memberCodeSet) . ' member codes for lookup.');

    $successCount = 0;
    $failedCount = 0;
    $skippedMissingMember = 0;

    $pendingRows = [];
    $batchSize = AGENT_LOGIN_INSERT_BATCH_SIZE;

    foreach ($reportRows as $row) {
        $memberCode = trim((string) ($row['member_code'] ?? ''));
        $memberName = trim((string) ($row['member_name'] ?? ''));
        $loginTimes = $row['login_times'] ?? [];

        foreach ($loginTimes as $loginTimeRaw) {
            $loginTime = validLoginTime((string) $loginTimeRaw);
            if ($loginTime === null) {
                $failedCount++;
                writeApiLog('Skipped invalid login timestamp for member ' . $memberCode . ': ' . $loginTimeRaw, 'WARN');
                continue;
            }

            if (!isset($memberCodeSet[$memberCode])) {
                $skippedMissingMember++;
                writeApiLog('Skipped member not found in members table: ' . $memberCode, 'WARN');
                continue;
            }

            $pendingRows[] = [
                $memberCode,
                $memberName !== '' ? $memberName : null,
                $loginTime,
                $importBatchId,
            ];

            if (count($pendingRows) >= $batchSize) {
                try {
                    $successCount += flushInsertBatch($pdo, $pendingRows);
                } catch (Throwable $e) {
                    $failedCount += count($pendingRows);
                    writeApiLog('Batch insert failed (' . count($pendingRows) . ' rows): ' . $e->getMessage(), 'ERROR');
                }
                $pendingRows = [];
            }
        }
    }

    // Flush any remaining rows that didn't fill a full batch.
    if (!empty($pendingRows)) {
        try {
            $successCount += flushInsertBatch($pdo, $pendingRows);
        } catch (Throwable $e) {
            $failedCount += count($pendingRows);
            writeApiLog('Final batch insert failed (' . count($pendingRows) . ' rows): ' . $e->getMessage(), 'ERROR');
        }
        $pendingRows = [];
    }

    $totalRows = $successCount + $failedCount + $skippedMissingMember;

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
        ':failed' => $failedCount + $skippedMissingMember,
        ':status' => ($failedCount + $skippedMissingMember) > 0 ? 'completed_with_errors' : 'completed',
        ':id' => $importBatchId,
    ]);

    writeApiLog('Import finished. inserted=' . $successCount . ' failed=' . ($failedCount + $skippedMissingMember) . ' total=' . $totalRows . ' batch_id=' . $importBatchId);

    outputResult([
        'success' => true,
        'batch_id' => $importBatchId,
        'from' => $fromDate,
        'to' => $toDate,
        'report_rows_found' => count($reportRows),
        'inserted_rows' => $successCount,
        'failed_rows' => $failedCount + $skippedMissingMember,
        'skipped_missing_member' => $skippedMissingMember,
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