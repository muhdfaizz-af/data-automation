<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

/**
 * SALES LIMIT MONITOR
 * Single PHP file - No Database
 *
 * Boleh jalan 3 cara:
 *  1. Cron CLI      : /usr/local/bin/php /path/sales_monitor.php
 *  2. Cron URL+key  : curl -s "https://domain/admin/sales_monitor.php?key=XXXX"
 *  3. Browser       : (kena login admin) tunjuk dashboard
 */

// ============================================================
// 0. CRON KEY (tukar ke string random panjang!)
// ============================================================

$cronKey = 's-asia';


// ============================================================
// 0.1 DETECT MODE + AUTH
// ============================================================

$isCli = (php_sapi_name() === 'cli');

$isKeyCron =
    !$isCli
    && isset($_GET['key'])
    && is_string($_GET['key'])
    && hash_equals($cronKey, $_GET['key']);

// Cron = CLI atau URL dengan key yang betul. Skip session.
$isCron = $isCli || $isKeyCron;

if (!$isCron) {

    session_start();

    if (empty($_SESSION['admin_id'])) {
        header('Location: ../index.php');
        exit;
    }

    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 7200) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../index.php?expired=1');
        exit;
    }

    $_SESSION['last_activity'] = time();
}


// ============================================================
// 1. SETTINGS
// ============================================================

$settings = [

    // Auto refresh dashboard (saat) - untuk browser sahaja
    'check_interval' => 60,

    // Email recipient
    'email_to' => 'muhammadfaizzuddin.ahmadfakri6@gmail.com',

    // Email sender
    'email_from' => 'faizzuddin@saudagaarasia.com',

    // Jam (24h, server time) selepas itu daily summary boleh dihantar.
    // Hantar sekali sehari sahaja.
    'daily_report_hour'   => 9,
    'daily_report_minute' => 00,

    // Peratus limit yang trigger warning email
    'warning_threshold' => 0.9,

    // Nama pengirim yang nampak dalam inbox
    'email_from_name' => 'Sales Limit Monitor',

    // SMTP (guna email account yang dibuat dalam cPanel)
    'smtp' => [
        'host'       => 'mail.saudagaarasia.com',
        'port'       => 465,          // 465 = ssl, 587 = tls
        'secure'     => 'ssl',        // 'ssl' atau 'tls'
        'username'   => 'faizzuddin@saudagaarasia.com',
        'password'   => 'Faiz2003',
        'verify_ssl' => true,         // set false kalau error certificate
    ],

    // Rules to monitor
    'rules' => [

        [
            'name'       => 'MY CHOCO ALBAB PREMIUM MUG',
            'country'    => 'MY',
            'url'        => 'https://admin.e-saudagaar.com/index.php/report/RepDPProduction/printDailySalesInventory?param=TVlfXzIwMjYtMDktMTZUMDA6MDA6MDBfXzIwMjYtMDktMzBUMjM6NTk6NTlfXzUsODQ3MTBfX0NIT0NPIEFMQkFCX19zb2xkX18=',
            'price_code' => 'FOC',
            'sku'        => 'MCA-1002',
            'limit'      => 850,
        ],

        [
            'name'       => 'SG CHOCO ALBAB PREMIUM MUG',
            'country'    => 'SG',
            'url'        => 'https://admin.e-saudagaar.com/index.php/report/RepDPProduction/printDailySalesInventory?param=U0dfXzIwMjYtMDktMTZUMDA6MDA6MDBfXzIwMjYtMDktMzBUMjM6NTk6NTlfXzE3M19fQ0hPQ08gQUxCQUJfX3NvbGRfXw==',
            'price_code' => 'FOC',
            'sku'        => 'MCA-1002',
            'limit'      => 100,
        ],

    ],
];


// ============================================================
// 2. STATE FILE + LOCK FILE
// ============================================================

$stateFile = __DIR__ . DIRECTORY_SEPARATOR . '.sales_monitor_state.json';
$lockFile  = __DIR__ . DIRECTORY_SEPARATOR . '.sales_monitor.lock';


// ============================================================
// 2.1 LOCK (elak dua run bertindih hantar email dua kali)
// ============================================================

$lockHandle = @fopen($lockFile, 'c');

if ($lockHandle && !flock($lockHandle, LOCK_EX | LOCK_NB)) {

    // Run lain tengah jalan
    if ($isCron) {
        echo date('Y-m-d H:i:s') . " | Another run in progress, skipped.\n";
        exit;
    }

    // Untuk browser, tunggu sekejap sampai lock free
    flock($lockHandle, LOCK_EX);
}


// ============================================================
// 3. LOAD STATE
// ============================================================

$state = [];

if (file_exists($stateFile)) {

    $json = file_get_contents($stateFile);

    $decoded = json_decode($json, true);

    if (is_array($decoded)) {
        $state = $decoded;
    }
}


// ============================================================
// 4. FUNCTIONS
// ============================================================

function fetchReport($url)
{
    if (function_exists('curl_init') && function_exists('curl_exec')) {
        return fetchReportCurl($url);
    }

    return fetchReportStream($url);
}


function fetchReportCurl($url)
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,

        CURLOPT_USERAGENT =>
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140 Safari/537.36',

        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
    ]);

    $html = curl_exec($ch);

    $error = curl_error($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($html === false || $html === '') {

        return [
            'success' => false,
            'error'   => $error ?: 'Empty response',
            'http'    => $httpCode,
            'html'    => '',
        ];
    }

    if ($httpCode >= 400) {

        return [
            'success' => false,
            'error'   => 'HTTP Error ' . $httpCode,
            'http'    => $httpCode,
            'html'    => $html,
        ];
    }

    return [
        'success' => true,
        'error'   => '',
        'http'    => $httpCode,
        'html'    => $html,
    ];
}


function fetchReportStream($url)
{
    if (!ini_get('allow_url_fopen')) {

        return [
            'success' => false,
            'error'   => 'Neither curl nor allow_url_fopen is available on this server. Ask your host to enable one of them.',
            'http'    => 0,
            'html'    => '',
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 30,
            'follow_location' => 1,
            'header'          =>
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140 Safari/537.36\r\n"
                . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n",
            'ignore_errors'   => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $html = @file_get_contents($url, false, $context);

    $httpCode = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {

        foreach ($http_response_header as $headerLine) {

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
                $httpCode = (int) $m[1];
            }
        }
    }

    if ($html === false || $html === '') {

        $lastError = error_get_last();

        return [
            'success' => false,
            'error'   => $lastError ? $lastError['message'] : 'Empty response',
            'http'    => $httpCode,
            'html'    => '',
        ];
    }

    if ($httpCode >= 400) {

        return [
            'success' => false,
            'error'   => 'HTTP Error ' . $httpCode,
            'http'    => $httpCode,
            'html'    => $html,
        ];
    }

    return [
        'success' => true,
        'error'   => '',
        'http'    => $httpCode,
        'html'    => $html,
    ];
}


function cleanText($text)
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $text = preg_replace('/\s+/u', ' ', $text);

    return trim($text);
}


function getReportData($html, $targetPriceCode, $targetSku)
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument();

    $loaded = $dom->loadHTML($html);

    if (!$loaded) {
        return [
            'success' => false,
            'error'   => 'Unable to parse HTML.',
            'qty'     => null,
            'row'     => null,
        ];
    }

    $xpath = new DOMXPath($dom);

    $tables = $xpath->query('//table');

    foreach ($tables as $table) {

        $rows = $xpath->query('.//tr', $table);

        foreach ($rows as $row) {

            $cells = $xpath->query('./th|./td', $row);

            if ($cells->length < 5) {
                continue;
            }

            $values = [];

            foreach ($cells as $cell) {
                $values[] = cleanText($cell->textContent);
            }

            /**
             * 0 = No.
             * 1 = Price Code
             * 2 = Product SKU
             * 3 = Product Name
             * 4 = Qty
             * 5 = Total
             */

            $priceCode = $values[1] ?? '';
            $sku       = $values[2] ?? '';
            $qty       = $values[4] ?? '';

            if (
                strtoupper(trim($priceCode)) === strtoupper(trim($targetPriceCode))
                &&
                strtoupper(trim($sku)) === strtoupper(trim($targetSku))
            ) {

                $qtyClean = str_replace(',', '', $qty);

                if (is_numeric($qtyClean)) {

                    return [
                        'success' => true,
                        'error'   => '',
                        'qty'     => (float) $qtyClean,
                        'row'     => $values,
                    ];
                }

                return [
                    'success' => false,
                    'error'   => 'Matching row found but Qty is not numeric.',
                    'qty'     => null,
                    'row'     => $values,
                ];
            }
        }
    }

    return [
        'success' => false,
        'error'   => 'Price Code + SKU not found.',
        'qty'     => null,
        'row'     => null,
    ];
}


$GLOBALS['mail_last_error'] = '';


function smtpRead($fp)
{
    $data = '';

    while (($line = fgets($fp, 515)) !== false) {

        $data .= $line;

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return $data;
}


function smtpCmd($fp, $command, $expectCode)
{
    fwrite($fp, $command . "\r\n");

    $response = smtpRead($fp);

    $code = (int) substr($response, 0, 3);

    return [$code === $expectCode, trim($response)];
}


/**
 * Hantar email melalui SMTP (tanpa library luar).
 * Return true/false. Kalau gagal, sebab disimpan dalam
 * $GLOBALS['mail_last_error'].
 */
function deliverMail($settings, $subject, $message)
{
    $GLOBALS['mail_last_error'] = '';

    $smtp = $settings['smtp'];

    if (empty($smtp['password']) || strpos($smtp['password'], 'GANTI') === 0) {
        $GLOBALS['mail_last_error'] = 'SMTP password belum diisi dalam settings.';
        return false;
    }

    $secure = strtolower($smtp['secure']);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $smtp['host'] . ':' . $smtp['port'];
    $verify = !isset($smtp['verify_ssl']) || $smtp['verify_ssl'];

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            'allow_self_signed' => !$verify,
        ],
    ]);

    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);

    if (!$fp) {
        $GLOBALS['mail_last_error'] = 'Cannot connect to ' . $smtp['host'] . ':' . $smtp['port'] . ' - ' . $errstr . ' (' . $errno . ')';
        return false;
    }

    stream_set_timeout($fp, 20);

    $fail = function ($step, $response) use ($fp) {
        $GLOBALS['mail_last_error'] = 'SMTP failed at ' . $step . ': ' . $response;
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return false;
    };

    $greeting = smtpRead($fp);

    if (substr($greeting, 0, 3) !== '220') {
        return $fail('greeting', trim($greeting));
    }

    $ehloHost = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : (string) gethostname();
    $ehloHost = preg_replace('/[^A-Za-z0-9.\-]/', '', $ehloHost);

    if ($ehloHost === '') {
        $ehloHost = 'localhost';
    }

    list($ok, $resp) = smtpCmd($fp, 'EHLO ' . $ehloHost, 250);

    if (!$ok) {
        return $fail('EHLO', $resp);
    }

    if ($secure === 'tls') {

        list($ok, $resp) = smtpCmd($fp, 'STARTTLS', 220);

        if (!$ok) {
            return $fail('STARTTLS', $resp);
        }

        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail('TLS handshake', 'could not enable encryption');
        }

        list($ok, $resp) = smtpCmd($fp, 'EHLO ' . $ehloHost, 250);

        if (!$ok) {
            return $fail('EHLO after TLS', $resp);
        }
    }

    list($ok, $resp) = smtpCmd($fp, 'AUTH LOGIN', 334);

    if (!$ok) {
        return $fail('AUTH LOGIN', $resp);
    }

    list($ok, $resp) = smtpCmd($fp, base64_encode($smtp['username']), 334);

    if (!$ok) {
        return $fail('AUTH username', $resp);
    }

    list($ok, $resp) = smtpCmd($fp, base64_encode($smtp['password']), 235);

    if (!$ok) {
        return $fail('AUTH (username/password salah?)', $resp);
    }

    list($ok, $resp) = smtpCmd($fp, 'MAIL FROM:<' . $settings['email_from'] . '>', 250);

    if (!$ok) {
        return $fail('MAIL FROM', $resp);
    }

    list($ok, $resp) = smtpCmd($fp, 'RCPT TO:<' . $settings['email_to'] . '>', 250);

    if (!$ok) {
        return $fail('RCPT TO', $resp);
    }

    list($ok, $resp) = smtpCmd($fp, 'DATA', 354);

    if (!$ok) {
        return $fail('DATA', $resp);
    }

    $domain = substr(strrchr($settings['email_from'], '@'), 1);

    $headers = [
        'Date: ' . date('r'),
        'From: =?UTF-8?B?' . base64_encode($settings['email_from_name']) . '?= <' . $settings['email_from'] . '>',
        'To: <' . $settings['email_to'] . '>',
        'Reply-To: ' . $settings['email_from'],
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'Message-ID: <' . md5(uniqid('', true)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];

    $data =
        implode("\r\n", $headers)
        . "\r\n\r\n"
        . chunk_split(base64_encode($message), 76, "\r\n")
        . ".\r\n";

    fwrite($fp, $data);

    $resp = smtpRead($fp);

    if (substr($resp, 0, 3) !== '250') {
        return $fail('sending message', trim($resp));
    }

    smtpCmd($fp, 'QUIT', 221);

    @fclose($fp);

    return true;
}


function sendAlertEmail($settings, $rule, $qty)
{
    $subject =
        '🚨 Sales Limit Reached - '
        . $rule['country']
        . ' - '
        . $rule['sku'];

    $message  = "SALES LIMIT REACHED\n";
    $message .= "===========================\n\n";
    $message .= "Product      : " . $rule['name'] . "\n";
    $message .= "Country      : " . $rule['country'] . "\n";
    $message .= "Price Code   : " . $rule['price_code'] . "\n";
    $message .= "Product SKU  : " . $rule['sku'] . "\n";
    $message .= "Current Qty  : " . $qty . "\n";
    $message .= "Limit        : " . $rule['limit'] . "\n";
    $message .= "Remaining    : 0\n";
    $message .= "Checked At   : " . date('Y-m-d H:i:s') . "\n\n";
    $message .= "The configured sales limit has been reached.\n";

    return deliverMail($settings, $subject, $message);
}


function sendWarningEmail($settings, $rule, $qty, $thresholdPct)
{
    $remaining = $rule['limit'] - $qty;

    $subject =
        '⚠️ Sales Nearing Limit (' . $thresholdPct . '%) - '
        . $rule['country']
        . ' - '
        . $rule['sku'];

    $message  = "SALES NEARING LIMIT\n";
    $message .= "===========================\n\n";
    $message .= "Product      : " . $rule['name'] . "\n";
    $message .= "Country      : " . $rule['country'] . "\n";
    $message .= "Price Code   : " . $rule['price_code'] . "\n";
    $message .= "Product SKU  : " . $rule['sku'] . "\n";
    $message .= "Current Qty  : " . $qty . "\n";
    $message .= "Limit        : " . $rule['limit'] . "\n";
    $message .= "Remaining    : " . $remaining . "\n";
    $message .= "Checked At   : " . date('Y-m-d H:i:s') . "\n\n";
    $message .= "Sales have reached " . $thresholdPct . "% of the configured limit.\n";

    return deliverMail($settings, $subject, $message);
}


function sendDailySummaryEmail($settings, $results)
{
    $subject = '📊 Daily Sales Summary - ' . date('Y-m-d');

    $message  = "DAILY SALES SUMMARY\n";
    $message .= "===========================\n\n";
    $message .= "Generated At : " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($results as $r) {

        $message .= $r['name'] . " (" . $r['country'] . ")\n";
        $message .= "  Price Code : " . $r['price_code'] . "\n";
        $message .= "  SKU        : " . $r['sku'] . "\n";
        $message .= "  Qty        : " . ($r['qty'] !== null ? $r['qty'] : 'n/a') . "\n";
        $message .= "  Limit      : " . $r['limit'] . "\n";
        $message .= "  Status     : " . $r['status'] . "\n";
        $message .= "  Note       : " . $r['message'] . "\n\n";
    }

    return deliverMail($settings, $subject, $message);
}


function sendTestEmail($settings)
{
    $subject = 'Test Email - Sales Limit Monitor';

    $message  = "This is a test email from Sales Limit Monitor.\n\n";
    $message .= "If you received this, the SMTP email setup on this ";
    $message .= "server is working correctly.\n\n";
    $message .= "Sent at: " . date('Y-m-d H:i:s') . "\n";

    $sent = deliverMail($settings, $subject, $message);

    return [
        'success' => $sent,
        'error'   => $sent ? '' : $GLOBALS['mail_last_error'],
    ];
}


function saveState($stateFile, $state)
{
    file_put_contents(
        $stateFile,
        json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );
}


// ============================================================
// 5. HANDLE "SEND TEST EMAIL" BUTTON (browser POST sahaja)
// ============================================================

$testEmailResult = null;

if (
    !$isCron
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['send_test_email'])
) {
    $testEmailResult = sendTestEmail($settings);
}


// ============================================================
// 6. CHECK RULES
// ============================================================

$results = [];

$warningThresholdPct = (int) round($settings['warning_threshold'] * 100);

foreach ($settings['rules'] as $index => $rule) {

    $result = [
        'name'       => $rule['name'],
        'country'    => $rule['country'],
        'price_code' => $rule['price_code'],
        'sku'        => $rule['sku'],
        'limit'      => $rule['limit'],
        'qty'        => null,
        'status'     => 'ERROR',
        'message'    => '',
    ];


    // Fetch report
    $report = fetchReport($rule['url']);

    if (!$report['success']) {

        $result['status']  = 'ERROR';
        $result['message'] = $report['error'];

        $results[] = $result;

        continue;
    }


    // Find Price Code + SKU
    $data = getReportData(
        $report['html'],
        $rule['price_code'],
        $rule['sku']
    );

    if (!$data['success']) {

        $result['status']  = 'NOT FOUND';
        $result['message'] = $data['error'];

        $results[] = $result;

        continue;
    }


    $qty = $data['qty'];

    $result['qty'] = $qty;


    $ruleKey =
        $rule['country']
        . '|'
        . $rule['price_code']
        . '|'
        . $rule['sku'];

    if (!isset($state[$ruleKey])) {
        $state[$ruleKey] = [];
    }


    // Compare limit
    if ($qty >= $rule['limit']) {

        $result['status']  = 'LIMIT REACHED';
        $result['message'] = 'Limit has been reached.';

        $alreadyAlerted =
            isset($state[$ruleKey]['alerted'])
            &&
            $state[$ruleKey]['alerted'] === true;

        if (!$alreadyAlerted) {

            $emailSent = sendAlertEmail(
                $settings,
                $rule,
                $qty
            );

            $state[$ruleKey]['alerted']    = $emailSent;
            $state[$ruleKey]['qty']        = $qty;
            $state[$ruleKey]['alert_time'] = date('Y-m-d H:i:s');

            // Hit 100% bermaksud dah lepas 90% juga.
            $state[$ruleKey]['warned'] = true;

            $result['message'] = $emailSent
                ? 'LIMIT REACHED - EMAIL SENT'
                : 'LIMIT REACHED - EMAIL FAILED';

            saveState($stateFile, $state);

        } else {

            $result['message'] = 'LIMIT REACHED - EMAIL ALREADY SENT';
        }

    } else {

        $remaining = $rule['limit'] - $qty;

        $isWarningZone = $qty >= ($rule['limit'] * $settings['warning_threshold']);

        $result['status']  = $isWarningZone ? 'WARNING' : 'ACTIVE';
        $result['message'] = 'Remaining: ' . $remaining;

        // Reset alert kalau qty turun bawah limit
        if (isset($state[$ruleKey]['alerted']) && $state[$ruleKey]['alerted'] === true) {

            $state[$ruleKey]['alerted'] = false;

            saveState($stateFile, $state);
        }

        // 90% warning email (sekali setiap kali cross)
        $alreadyWarned =
            isset($state[$ruleKey]['warned'])
            &&
            $state[$ruleKey]['warned'] === true;

        if ($isWarningZone && !$alreadyWarned) {

            $warnSent = sendWarningEmail(
                $settings,
                $rule,
                $qty,
                $warningThresholdPct
            );

            $state[$ruleKey]['warned']    = $warnSent;
            $state[$ruleKey]['warn_time'] = date('Y-m-d H:i:s');

            $result['message'] .= $warnSent
                ? ' - WARNING EMAIL SENT'
                : ' - WARNING EMAIL FAILED';

            saveState($stateFile, $state);

        } elseif (!$isWarningZone && $alreadyWarned) {

            $state[$ruleKey]['warned'] = false;

            saveState($stateFile, $state);
        }
    }

    $results[] = $result;
}


// ============================================================
// 7. DAILY SUMMARY EMAIL (sekali sehari)
// ============================================================

$today = date('Y-m-d');

$nowMinutes    = ((int) date('G') * 60) + (int) date('i');
$targetMinutes = ((int) $settings['daily_report_hour'] * 60) + (int) $settings['daily_report_minute'];

// Reset status "sent today" untuk testing:
// https://domain/cronjob/index.php?key=KEY&reset_daily=1
if ($isKeyCron && isset($_GET['reset_daily'])) {

    unset($state['_daily_report']);

    saveState($stateFile, $state);
}

$dailyAlreadySent =
    isset($state['_daily_report']['date'])
    &&
    $state['_daily_report']['date'] === $today;

// Daily summary HANYA dihantar oleh cron (bukan bila buka browser)
if ($isCron && !$dailyAlreadySent && $nowMinutes >= $targetMinutes) {

    $dailySent = sendDailySummaryEmail($settings, $results);

    $dailyLog = $dailySent
        ? 'DAILY SUMMARY: EMAIL SENT'
        : 'DAILY SUMMARY: EMAIL FAILED - ' . $GLOBALS['mail_last_error'] . ' (will retry next run)';

    // Hanya tanda "sent" kalau email berjaya, supaya cron cuba lagi kalau gagal
    if ($dailySent) {

        $state['_daily_report'] = [
            'date' => $today,
            'sent' => true,
            'time' => date('Y-m-d H:i:s'),
        ];

        saveState($stateFile, $state);
    }
}


// ============================================================
// 7.1 CRON MODE: output teks ringkas, tak perlu HTML
// ============================================================

if ($isCron) {

    foreach ($results as $r) {

        echo date('Y-m-d H:i:s')
            . ' | ' . $r['country']
            . ' | ' . $r['sku']
            . ' | qty=' . ($r['qty'] !== null ? $r['qty'] : 'n/a')
            . ' | ' . $r['status']
            . ' | ' . $r['message']
            . "\n";
    }

    if (!empty($dailyLog)) {
        echo date('Y-m-d H:i:s') . ' | ' . $dailyLog . "\n";
    }

    if (!empty($GLOBALS['mail_last_error'])) {
        echo date('Y-m-d H:i:s') . ' | MAIL ERROR: ' . $GLOBALS['mail_last_error'] . "\n";
    }

    exit;
}


// ============================================================
// 8. OUTPUT DASHBOARD (browser sahaja)
// ============================================================

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta http-equiv="refresh" content="<?php echo (int)$settings['check_interval']; ?>">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sales Limit Monitor</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        h1 {
            margin-bottom: 5px;
        }

        .updated {
            color: #777;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }

        .box {
            background: #f7f7f7;
            padding: 15px;
            border-radius: 8px;
        }

        .label {
            font-size: 12px;
            color: #777;
        }

        .value {
            font-size: 20px;
            font-weight: bold;
            margin-top: 5px;
        }

        .status {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 13px;
        }

        .active  { background: #dff5e3; color: #197333; }
        .warning { background: #fff3cd; color: #856404; }
        .limit   { background: #ffd9d9; color: #b00020; }
        .error   { background: #eee;    color: #555; }

        .test-email-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .test-email-card .info {
            color: #555;
            font-size: 14px;
        }

        .btn-test {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-test:hover {
            background: #1d4ed8;
        }

        .test-result {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 12px;
        }

        .test-result.ok   { background: #dff5e3; color: #197333; }
        .test-result.fail { background: #ffd9d9; color: #b00020; }

        @media(max-width: 700px) {

            .row {
                grid-template-columns: 1fr 1fr;
            }

            .test-email-card {
                flex-direction: column;
                align-items: flex-start;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <h1>Sales Limit Monitor</h1>

    <div class="updated">
        Last checked:
        <?php echo date('Y-m-d H:i:s'); ?>

        <br>

        Auto refresh:
        <?php echo (int)$settings['check_interval']; ?> seconds

        <br>

        Fetch method:
        <?php echo (function_exists('curl_init') && function_exists('curl_exec')) ? 'curl' : 'file_get_contents (curl not available)'; ?>

        <br>

        Daily summary:
        <?php
        echo isset($state['_daily_report']['date']) && $state['_daily_report']['date'] === $today
            ? 'Sent today at ' . htmlspecialchars($state['_daily_report']['time'])
            : 'Not sent yet today (fires on first check at/after ' . sprintf('%02d:%02d', (int)$settings['daily_report_hour'], (int)$settings['daily_report_minute']) . ')';
        ?>
    </div>


    <div class="test-email-card">

        <div>
            <strong>Email Test</strong>
            <div class="info">
                Send a test email to
                <?php echo htmlspecialchars($settings['email_to']); ?>
                to confirm SMTP email works.
            </div>

            <?php if ($testEmailResult !== null): ?>

                <?php if ($testEmailResult['success']): ?>

                    <div class="test-result ok">
                        ✅ Test email sent successfully. Check the inbox (and spam folder).
                    </div>

                <?php else: ?>

                    <div class="test-result fail">
                        ❌ Test email failed.
                        <?php if (!empty($testEmailResult['error'])): ?>
                            <br>Error: <?php echo htmlspecialchars($testEmailResult['error']); ?>
                        <?php else: ?>
                            <br>Unknown error. Check SMTP settings.
                        <?php endif; ?>
                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

        <form method="POST">
            <input type="hidden" name="send_test_email" value="1">
            <button type="submit" class="btn-test">Send Test Email</button>
        </form>

    </div>


    <?php foreach ($results as $result): ?>

        <?php

        $statusClass = 'error';

        if ($result['status'] === 'ACTIVE') {
            $statusClass = 'active';
        }

        if ($result['status'] === 'WARNING') {
            $statusClass = 'warning';
        }

        if ($result['status'] === 'LIMIT REACHED') {
            $statusClass = 'limit';
        }

        ?>

        <div class="card">

            <h2>
                <?php echo htmlspecialchars($result['name']); ?>
            </h2>

            <div class="row">

                <div class="box">
                    <div class="label">Price Code</div>
                    <div class="value"><?php echo htmlspecialchars($result['price_code']); ?></div>
                </div>

                <div class="box">
                    <div class="label">Product SKU</div>
                    <div class="value"><?php echo htmlspecialchars($result['sku']); ?></div>
                </div>

                <div class="box">
                    <div class="label">Current Qty</div>
                    <div class="value">
                        <?php echo $result['qty'] !== null ? number_format($result['qty']) : '-'; ?>
                    </div>
                </div>

                <div class="box">
                    <div class="label">Limit</div>
                    <div class="value"><?php echo number_format($result['limit']); ?></div>
                </div>

            </div>

            <br>

            <span class="status <?php echo $statusClass; ?>">
                <?php echo htmlspecialchars($result['status']); ?>
            </span>

            &nbsp;

            <?php echo htmlspecialchars($result['message']); ?>

        </div>

    <?php endforeach; ?>

</div>

</body>

</html>