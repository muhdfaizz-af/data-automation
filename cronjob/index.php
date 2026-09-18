<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

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

/**
 * SALES LIMIT MONITOR
 * Single PHP file - No Database
 *
 * Monitor:
 * Price Code : FOC
 * SKU        : MCA-1002
 *
 * Change the settings below.
 */

// ============================================================
// 1. SETTINGS
// ============================================================

$settings = [

    // Check every how many seconds if running continuously
    'check_interval' => 60,

    // Email recipient
    'email_to' => 'muhammadfaizzuddin.ahmadfakri6@gmail.com',

    // Email sender
    'email_from' => 'noreply@saudagaarasia.com',

    // Hour (24h, server time) after which the daily summary
    // email is allowed to fire. It only sends once per day,
    // on the first script run at/after this hour.
    'daily_report_hour' => 9,

    // Fraction of the limit that triggers the 90% warning email
    'warning_threshold' => 0.9,

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
// 2. STATE FILE
// ============================================================
// No database.
// This file is automatically created next to this PHP file.

$stateFile = __DIR__ . DIRECTORY_SEPARATOR . '.sales_monitor_state.json';


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

/**
 * Fetch a URL using curl if available, otherwise fall back to
 * file_get_contents() with a stream context.
 *
 * Shared hosting sometimes has the curl extension disabled but
 * allow_url_fopen enabled (or vice versa), so this tries curl
 * first (more reliable, better error info) and only falls back
 * if curl_init/curl_exec don't exist.
 */
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


/**
 * curl-free fallback using file_get_contents() + stream context.
 * Requires allow_url_fopen = On (usually enabled by default on
 * shared hosting, even when the curl extension is missing).
 */
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
            'ignore_errors'   => true, // so we still get body + headers on 4xx/5xx
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $html = @file_get_contents($url, false, $context);

    $httpCode = 0;

    // $http_response_header is set automatically by file_get_contents()
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
             * Expected:
             *
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

                // Convert qty like "51", "1,200", "51.00"
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


function sendAlertEmail($settings, $rule, $qty)
{
    $subject =
        '🚨 Sales Limit Reached - '
        . $rule['country']
        . ' - '
        . $rule['sku'];

    $message = '';

    $message .= "SALES LIMIT REACHED\n";
    $message .= "===========================\n\n";

    $message .= "Country      : " . $rule['country'] . "\n";
    $message .= "Price Code   : " . $rule['price_code'] . "\n";
    $message .= "Product SKU  : " . $rule['sku'] . "\n";
    $message .= "Current Qty  : " . $qty . "\n";
    $message .= "Limit        : " . $rule['limit'] . "\n";
    $message .= "Remaining    : 0\n";
    $message .= "Checked At   : " . date('Y-m-d H:i:s') . "\n\n";

    $message .= "The configured sales limit has been reached.\n";

    $headers = [];

    $headers[] = 'From: ' . $settings['email_from'];
    $headers[] = 'Reply-To: ' . $settings['email_from'];
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    return mail(
        $settings['email_to'],
        $subject,
        $message,
        implode("\r\n", $headers)
    );
}


/**
 * Sent once when a rule crosses the warning threshold
 * (default 90% of its limit) but hasn't reached the limit yet.
 */
function sendWarningEmail($settings, $rule, $qty, $thresholdPct)
{
    $remaining = $rule['limit'] - $qty;

    $subject =
        '⚠️ Sales Nearing Limit (' . $thresholdPct . '%) - '
        . $rule['country']
        . ' - '
        . $rule['sku'];

    $message = '';

    $message .= "SALES NEARING LIMIT\n";
    $message .= "===========================\n\n";

    $message .= "Country      : " . $rule['country'] . "\n";
    $message .= "Price Code   : " . $rule['price_code'] . "\n";
    $message .= "Product SKU  : " . $rule['sku'] . "\n";
    $message .= "Current Qty  : " . $qty . "\n";
    $message .= "Limit        : " . $rule['limit'] . "\n";
    $message .= "Remaining    : " . $remaining . "\n";
    $message .= "Checked At   : " . date('Y-m-d H:i:s') . "\n\n";

    $message .= "Sales have reached " . $thresholdPct . "% of the configured limit.\n";

    $headers = [];

    $headers[] = 'From: ' . $settings['email_from'];
    $headers[] = 'Reply-To: ' . $settings['email_from'];
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    return mail(
        $settings['email_to'],
        $subject,
        $message,
        implode("\r\n", $headers)
    );
}


/**
 * Sent once a day (first run at/after daily_report_hour) with
 * a status summary of every rule, regardless of whether any
 * limit or warning threshold was hit.
 */
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

    $headers = [];

    $headers[] = 'From: ' . $settings['email_from'];
    $headers[] = 'Reply-To: ' . $settings['email_from'];
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    return mail(
        $settings['email_to'],
        $subject,
        $message,
        implode("\r\n", $headers)
    );
}


/**
 * Sends a simple test email so you can verify mail() actually
 * works on this server (SMTP/sendmail configured, not blocked,
 * not landing in spam, etc).
 *
 * Returns an array with success flag + any PHP error captured,
 * because mail() itself doesn't tell you *why* it failed.
 */
function sendTestEmail($settings)
{
    $subject = 'Test Email - Sales Limit Monitor';

    $message  = "This is a test email from Sales Limit Monitor.\n\n";
    $message .= "If you received this, the mail() function on this ";
    $message .= "server is working correctly.\n\n";
    $message .= "Sent at: " . date('Y-m-d H:i:s') . "\n";

    $headers = [];

    $headers[] = 'From: ' . $settings['email_from'];
    $headers[] = 'Reply-To: ' . $settings['email_from'];
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    // Clear any previous error so we only capture what mail() causes here
    error_clear_last();

    $sent = mail(
        $settings['email_to'],
        $subject,
        $message,
        implode("\r\n", $headers)
    );

    $lastError = error_get_last();

    return [
        'success' => $sent,
        'error'   => (!$sent && $lastError) ? $lastError['message'] : '',
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
// 5. HANDLE "SEND TEST EMAIL" BUTTON
// ============================================================
// IMPORTANT: this only runs on POST (form submit), never on a
// normal page load or on the auto-refresh (meta refresh always
// uses GET). This stops the test email from firing repeatedly
// on its own.

$testEmailResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {

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


    // --------------------------------------------------------
    // Fetch report
    // --------------------------------------------------------

    $report = fetchReport($rule['url']);

    if (!$report['success']) {

        $result['status']  = 'ERROR';
        $result['message'] = $report['error'];

        $results[] = $result;

        continue;
    }


    // --------------------------------------------------------
    // Find Price Code + SKU
    // --------------------------------------------------------

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


    // --------------------------------------------------------
    // Compare limit
    // --------------------------------------------------------

    if ($qty >= $rule['limit']) {

        $result['status']  = 'LIMIT REACHED';
        $result['message'] = 'Limit has been reached.';


        /**
         * Only send email once.
         *
         * If email was already sent for this limit,
         * don't send again every minute.
         */

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

            // A rule that hit 100% has obviously also cleared 90%.
            $state[$ruleKey]['warned'] = true;


            if ($emailSent) {

                $result['message'] =
                    'LIMIT REACHED - EMAIL SENT';

            } else {

                $result['message'] =
                    'LIMIT REACHED - EMAIL FAILED';
            }


            saveState($stateFile, $state);

        } else {

            $result['message'] =
                'LIMIT REACHED - EMAIL ALREADY SENT';
        }


    } else {

        $remaining = $rule['limit'] - $qty;

        $isWarningZone = $qty >= ($rule['limit'] * $settings['warning_threshold']);

        $result['status'] = $isWarningZone ? 'WARNING' : 'ACTIVE';

        $result['message'] =
            'Remaining: ' . $remaining;


        /**
         * If quantity somehow goes below the limit again,
         * reset the alert.
         *
         * This allows another email if it later reaches
         * the limit again.
         */

        if (isset($state[$ruleKey]['alerted']) && $state[$ruleKey]['alerted'] === true) {

            $state[$ruleKey]['alerted'] = false;

            saveState($stateFile, $state);
        }


        // ----------------------------------------------------
        // 90% warning email (sent once per crossing)
        // ----------------------------------------------------

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

            $state[$ruleKey]['warned']      = $warnSent;
            $state[$ruleKey]['warn_time']   = date('Y-m-d H:i:s');

            $result['message'] .= $warnSent
                ? ' - WARNING EMAIL SENT'
                : ' - WARNING EMAIL FAILED';

            saveState($stateFile, $state);

        } elseif (!$isWarningZone && $alreadyWarned) {

            // Dropped back below the warning threshold, allow
            // another warning email next time it crosses again.
            $state[$ruleKey]['warned'] = false;

            saveState($stateFile, $state);
        }
    }


    $results[] = $result;
}


// ============================================================
// 7. DAILY SUMMARY EMAIL (once a day, first run at/after
//    daily_report_hour, server time)
// ============================================================

$today       = date('Y-m-d');
$currentHour = (int) date('G');

$dailyAlreadySent =
    isset($state['_daily_report']['date'])
    &&
    $state['_daily_report']['date'] === $today;

if (!$dailyAlreadySent && $currentHour >= (int) $settings['daily_report_hour']) {

    $dailySent = sendDailySummaryEmail($settings, $results);

    $state['_daily_report'] = [
        'date' => $today,
        'sent' => $dailySent,
        'time' => date('Y-m-d H:i:s'),
    ];

    saveState($stateFile, $state);
}


// ============================================================
// 8. OUTPUT DASHBOARD
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

        .active {
            background: #dff5e3;
            color: #197333;
        }

        .warning {
            background: #fff3cd;
            color: #856404;
        }

        .limit {
            background: #ffd9d9;
            color: #b00020;
        }

        .error {
            background: #eee;
            color: #555;
        }

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

        .test-result.ok {
            background: #dff5e3;
            color: #197333;
        }

        .test-result.fail {
            background: #ffd9d9;
            color: #b00020;
        }

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
            : 'Not sent yet today (fires on first check at/after ' . (int)$settings['daily_report_hour'] . ':00)';
        ?>
    </div>


    <div class="test-email-card">

        <div>
            <strong>Email Test</strong>
            <div class="info">
                Send a test email to
                <?php echo htmlspecialchars($settings['email_to']); ?>
                to confirm mail() works on this server.
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
                            <br>mail() returned false. Check server mail/SMTP configuration.
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

                    <div class="label">
                        Price Code
                    </div>

                    <div class="value">
                        <?php echo htmlspecialchars($result['price_code']); ?>
                    </div>

                </div>


                <div class="box">

                    <div class="label">
                        Product SKU
                    </div>

                    <div class="value">
                        <?php echo htmlspecialchars($result['sku']); ?>
                    </div>

                </div>


                <div class="box">

                    <div class="label">
                        Current Qty
                    </div>

                    <div class="value">

                        <?php

                        echo $result['qty'] !== null
                            ? number_format($result['qty'])
                            : '-';

                        ?>

                    </div>

                </div>


                <div class="box">

                    <div class="label">
                        Limit
                    </div>

                    <div class="value">

                        <?php
                        echo number_format($result['limit']);
                        ?>

                    </div>

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