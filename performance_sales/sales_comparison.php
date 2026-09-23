<?php
/**
 * Sales Performance Report
 * Comparison Daily / Monthly / Yearly Sales.
 *
 * - Status & Company filter are GLOBAL (top of page), applied to all 3 charts.
 * - All filtering happens via AJAX (fetch) -> the page URL never changes.
 *
 * Daily:   date range (day to day).
 * Monthly: Month range (e.g. Jan 2026 - Mar 2026) + Day-of-month range (1-31),
 *          e.g. only count the 1st-15th of every month in the range.
 * Yearly:  Year range (e.g. 2025 - 2026) + chronological date range (e.g. Jan 1 - Aug 25),
 *          applied to EVERY year in the range, so you can fairly compare
 *          partial years like "2025 vs 2026, Jan 1 - Aug 25 only".
 *
 * PERFORMANCE NOTE: the initial page load used to run all 3 report queries
 * (Daily/Monthly/Yearly, each up to 2 sub-queries for System + Manual = up to
 * 6 queries) synchronously in PHP before any HTML was sent. That blocked the
 * very first paint even though every *filter change* was already AJAX-based.
 * The page now sends the shell immediately with empty charts, then fires the
 * same fetchSection() calls used by "Apply Filter" on DOMContentLoaded, so
 * the 3 sections load in parallel in the browser instead of serially in PHP.
 *
 * Data source: `orders` (join `companies` for company_code) = "System" sales,
 *   PLUS `manual_sales` (join `companies` for company_code) = "Manual" sales
 *   entered for channels not available in Solucis (Modern Trade, TikTok, Shopee, etc).
 *   A Data Source filter (All / System / Manual) controls which one(s) feed the charts.
 *   - Order from company SG (currency SGD) is converted to MYR by multiplying
 *     SGD_TO_MYR_RATE (3.27) before being summed together with MY orders.
 *   - Manual sales are converted to MYR the same way, based on their company_id.
 *   - The Status filter (Confirmed/Void) only applies to System (orders) data,
 *     since manual_sales has no status column — manual entries are always included
 *     regardless of the Status filter.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

// ── SGD -> MYR conversion rate (fixed, matches the currency converter) ──
define('SGD_TO_MYR_RATE', 3.27);

// ── Auth guard (same as dashboard) ──
if (empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

// ── Idle timeout check (consistent with dashboard) ──
$idleLimit = 7200;
if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleLimit) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

$adminUsername = $_SESSION['admin_username'] ?? '';
$activeNav = 'salescomparison';
$navBasePath = '../';

function getDBConnection() {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
        return new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (Exception $e) { return null; }
}

/**
 * Convert one row's total to MYR based on its company_code.
 */
function toMyr($amount, $companyCode) {
    $amount = (float)$amount;
    if ($companyCode === 'SG') {
        $amount *= SGD_TO_MYR_RATE;
    }
    return $amount;
}

function normalizeStatus($v) {
    return in_array($v, ['all', 'confirmed', 'void'], true) ? $v : 'all';
}

function normalizeCompany($v) {
    return in_array($v, ['all', 'MY', 'SG'], true) ? $v : 'all';
}

/**
 * Data Source filter: all = System (orders) + Manual, system = orders only, manual = manual_sales only.
 */
function normalizeSource($v) {
    return in_array($v, ['all', 'system', 'manual'], true) ? $v : 'all';
}

function normalizeDay($v, $default) {
    $v = (int)$v;
    return ($v >= 1 && $v <= 31) ? $v : $default;
}

function normalizeMonthNum($v, $default) {
    $v = (int)$v;
    return ($v >= 1 && $v <= 12) ? $v : $default;
}

/**
 * Build the shared WHERE clause (status + company) for the `orders` query and append
 * bound params into $params by reference.
 */
function getFilterWhereClause($statusFilter, $companyFilter, &$params) {
    $clause = '';
    if ($statusFilter !== 'all') {
        $clause .= " AND o.order_status = :status";
        $params['status'] = $statusFilter === 'confirmed' ? 'Confirmed' : 'Void';
    }
    if ($companyFilter !== 'all') {
        $clause .= " AND c.company_code = :company";
        $params['company'] = $companyFilter;
    }
    return $clause;
}

/**
 * Build the WHERE clause (company only — manual_sales has no status column)
 * for the `manual_sales` query and append bound params into $params by reference.
 */
function getManualFilterWhereClause($companyFilter, &$params) {
    $clause = '';
    if ($companyFilter !== 'all') {
        $clause .= " AND c.company_code = :company";
        $params['company'] = $companyFilter;
    }
    return $clause;
}

function buildSectionResponse($data, $error, $from, $to) {
    $total = array_sum($data['values']);
    $count = count($data['values']);
    $avg   = $count > 0 ? $total / $count : 0;
    $resp = [
        'labels'  => $data['labels'],
        'values'  => $data['values'],
        'total'   => round($total, 2),
        'average' => round($avg, 2),
        'error'   => $error,
        'from'    => $from,
        'to'      => $to,
    ];
    if (isset($data['keys'])) {
        $resp['keys'] = $data['keys'];
    }
    return $resp;
}

// ════════════════════════════════════════════════════
// VALIDATION / CLAMPING
// ════════════════════════════════════════════════════
function clampDailyRange($from, $to) {
    $defFrom = date('Y-m-d', strtotime('-6 days'));
    $defTo   = date('Y-m-d');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to)) {
        return [$defFrom, $defTo, 'Invalid date format.'];
    }
    if ($from > $to) {
        return [$defFrom, $defTo, 'The "from" date cannot be later than the "to" date.'];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
        return [$defFrom, $defTo, 'Maximum range for the daily chart is 92 days.'];
    }
    return [$from, $to, ''];
}

/**
 * Monthly: month range (Y-m) + day-of-month range (1-31).
 * Returns [monthFrom, monthTo, dayFrom, dayTo, error]
 */
function clampMonthlyRange($monthFrom, $monthTo, $dayFrom, $dayTo) {
    $defMonthFrom = date('Y-m', strtotime('-5 months'));
    $defMonthTo   = date('Y-m');
    $defDayFrom   = 1;
    $defDayTo     = 31;

    if (!preg_match('/^\d{4}-\d{2}$/', (string)$monthFrom) || !preg_match('/^\d{4}-\d{2}$/', (string)$monthTo)) {
        return [$defMonthFrom, $defMonthTo, $defDayFrom, $defDayTo, 'Invalid month format.'];
    }
    if ($monthFrom > $monthTo) {
        return [$defMonthFrom, $defMonthTo, $defDayFrom, $defDayTo, 'The "from" month cannot be later than the "to" month.'];
    }
    [$fy, $fm] = array_map('intval', explode('-', $monthFrom));
    [$ty, $tm] = array_map('intval', explode('-', $monthTo));
    $spanMonths = ($ty - $fy) * 12 + ($tm - $fm);
    if ($spanMonths > 36) {
        return [$defMonthFrom, $defMonthTo, $defDayFrom, $defDayTo, 'Maximum range for the monthly chart is 36 months.'];
    }

    $dayFrom = normalizeDay($dayFrom, $defDayFrom);
    $dayTo   = normalizeDay($dayTo, $defDayTo);
    if ($dayFrom > $dayTo) {
        [$dayFrom, $dayTo] = [$dayTo, $dayFrom];
    }

    return [$monthFrom, $monthTo, $dayFrom, $dayTo, ''];
}

/**
 * Yearly: year range + month range + day range (applied to every year in the range).
 * Returns [yearFrom, yearTo, monthFrom, monthTo, dayFrom, dayTo, error]
 */
function clampYearlyRange($yearFrom, $yearTo, $monthFrom, $monthTo, $dayFrom, $dayTo) {
    $currentYear   = (int)date('Y');
    $defYearFrom   = $currentYear - 4;
    $defYearTo     = $currentYear;
    $defMonthFrom  = 1;
    $defMonthTo    = 12;
    $defDayFrom    = 1;
    $defDayTo      = 31;

    $yearFrom = (int)$yearFrom;
    $yearTo   = (int)$yearTo;

    if ($yearFrom < 2000 || $yearTo < 2000 || $yearFrom > $currentYear + 1 || $yearTo > $currentYear + 1) {
        return [$defYearFrom, $defYearTo, $defMonthFrom, $defMonthTo, $defDayFrom, $defDayTo, 'Invalid year.'];
    }
    if ($yearFrom > $yearTo) {
        return [$defYearFrom, $defYearTo, $defMonthFrom, $defMonthTo, $defDayFrom, $defDayTo, 'The "from" year cannot be later than the "to" year.'];
    }
    if (($yearTo - $yearFrom) > 20) {
        return [$defYearFrom, $defYearTo, $defMonthFrom, $defMonthTo, $defDayFrom, $defDayTo, 'Maximum range for the yearly chart is 20 years.'];
    }

    $monthFrom = normalizeMonthNum($monthFrom, $defMonthFrom);
    $monthTo   = normalizeMonthNum($monthTo, $defMonthTo);
    if ($monthFrom > $monthTo) {
        [$monthFrom, $monthTo] = [$monthTo, $monthFrom];
    }

    $dayFrom = normalizeDay($dayFrom, $defDayFrom);
    $dayTo   = normalizeDay($dayTo, $defDayTo);
    if ($monthFrom === $monthTo && $dayFrom > $dayTo) {
        [$dayFrom, $dayTo] = [$dayTo, $dayFrom];
    }

    return [$yearFrom, $yearTo, $monthFrom, $monthTo, $dayFrom, $dayTo, ''];
}

// ════════════════════════════════════════════════════
// DAILY
// ════════════════════════════════════════════════════
function getDailySales($pdo, $from, $to, $statusFilter = 'all', $companyFilter = 'all', $sourceFilter = 'all') {
    $rows = [];
    if ($pdo && $sourceFilter !== 'manual') {
        try {
            $params = [
                'from'        => $from . ' 00:00:00',
                'toExclusive' => (new DateTime($to))->modify('+1 day')->format('Y-m-d 00:00:00'),
            ];
            $clause = getFilterWhereClause($statusFilter, $companyFilter, $params);
            $sql = "SELECT DATE(o.order_datetime) AS d,
                        c.company_code,
                        SUM(o.sub_total) AS total
                 FROM orders o
                 JOIN companies c ON c.id = o.company_id
                 WHERE o.order_datetime >= :from
                   AND o.order_datetime <  :toExclusive" . $clause . "
                 GROUP BY d, c.company_code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Exception $e) { $rows = []; }
    }

    $manualRows = [];
    if ($pdo && $sourceFilter !== 'system') {
        try {
            $mParams = [
                'from' => $from,
                'to'   => $to,
            ];
            $mClause = getManualFilterWhereClause($companyFilter, $mParams);
            $sql = "SELECT ms.sales_date AS d,
                        c.company_code,
                        SUM(ms.amount) AS total
                 FROM manual_sales ms
                 JOIN companies c ON c.id = ms.company_id
                 WHERE ms.sales_date >= :from
                   AND ms.sales_date <= :to" . $mClause . "
                 GROUP BY d, c.company_code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($mParams);
            $manualRows = $stmt->fetchAll();
        } catch (Exception $e) { $manualRows = []; }
    }

    $totalsByDate = [];
    foreach ($rows as $r) {
        $key = $r['d'];
        $totalsByDate[$key] = ($totalsByDate[$key] ?? 0) + toMyr($r['total'], $r['company_code']);
    }
    foreach ($manualRows as $r) {
        $key = substr((string)$r['d'], 0, 10);
        $totalsByDate[$key] = ($totalsByDate[$key] ?? 0) + toMyr($r['total'], $r['company_code']);
    }

    // ── Fill every day in range so the chart is continuous (15,16,17,18...) ──
    $labels = []; $values = [];
    $cursor = new DateTime($from);
    $end    = new DateTime($to);
    $end->modify('+1 day');
    while ($cursor < $end) {
        $key = $cursor->format('Y-m-d');
        $labels[] = $cursor->format('d M');
        $values[] = round($totalsByDate[$key] ?? 0, 2);
        $cursor->modify('+1 day');
    }
    return ['labels' => $labels, 'values' => $values];
}

// ════════════════════════════════════════════════════
// MONTHLY — month range + day-of-month range (1-31)
// ════════════════════════════════════════════════════
function getMonthlySales($pdo, $monthFrom, $monthTo, $dayFrom, $dayTo, $statusFilter = 'all', $companyFilter = 'all', $sourceFilter = 'all') {
    $rows = [];
    if ($pdo && $sourceFilter !== 'manual') {
        try {
            $params = [
                'from'    => $monthFrom . '-01 00:00:00',
                'to'      => (new DateTime($monthTo . '-01'))->modify('+1 month')->format('Y-m-d 00:00:00'),
                'dayFrom' => $dayFrom,
                'dayTo'   => $dayTo,
            ];
            $clause = getFilterWhereClause($statusFilter, $companyFilter, $params);
            $sql = "SELECT DATE_FORMAT(o.order_datetime,'%Y-%m') AS ym,
                        c.company_code,
                        SUM(o.sub_total) AS total
                 FROM orders o
                 JOIN companies c ON c.id = o.company_id
                 WHERE o.order_datetime >= :from
                   AND o.order_datetime <  :to
                   AND DAY(o.order_datetime) BETWEEN :dayFrom AND :dayTo" . $clause . "
                 GROUP BY ym, c.company_code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Exception $e) { $rows = []; }
    }

    $manualRows = [];
    if ($pdo && $sourceFilter !== 'system') {
        try {
            $mParams = [
                'from'    => $monthFrom . '-01',
                'to'      => (new DateTime($monthTo . '-01'))->modify('+1 month')->modify('-1 day')->format('Y-m-d'),
                'dayFrom' => $dayFrom,
                'dayTo'   => $dayTo,
            ];
            $mClause = getManualFilterWhereClause($companyFilter, $mParams);
            $sql = "SELECT DATE_FORMAT(ms.sales_date,'%Y-%m') AS ym,
                        c.company_code,
                        SUM(ms.amount) AS total
                 FROM manual_sales ms
                 JOIN companies c ON c.id = ms.company_id
                 WHERE ms.sales_date >= :from
                   AND ms.sales_date <= :to
                   AND DAY(ms.sales_date) BETWEEN :dayFrom AND :dayTo" . $mClause . "
                 GROUP BY ym, c.company_code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($mParams);
            $manualRows = $stmt->fetchAll();
        } catch (Exception $e) { $manualRows = []; }
    }

    $totalsByYm = [];
    foreach ($rows as $r) {
        $totalsByYm[$r['ym']] = ($totalsByYm[$r['ym']] ?? 0) + toMyr($r['total'], $r['company_code']);
    }
    foreach ($manualRows as $r) {
        $totalsByYm[$r['ym']] = ($totalsByYm[$r['ym']] ?? 0) + toMyr($r['total'], $r['company_code']);
    }

    // ── Fill every month touched by the range (Jul -> Aug, etc) ──
    $labels = []; $values = []; $keys = [];
    $cursor = new DateTime($monthFrom . '-01');
    $end    = new DateTime($monthTo . '-01');
    $end->modify('+1 month');
    while ($cursor < $end) {
        $key = $cursor->format('Y-m');
        $labels[] = $cursor->format('M Y');
        $values[] = round($totalsByYm[$key] ?? 0, 2);
        $keys[]   = $key;
        $cursor->modify('+1 month');
    }
    return ['labels' => $labels, 'values' => $values, 'keys' => $keys];
}

// ════════════════════════════════════════════════════
// YEARLY — year range + chronological month/day range (applied to every year in range)
// ════════════════════════════════════════════════════
function getYearlySales($pdo, $yearFrom, $yearTo, $monthFrom, $monthTo, $dayFrom = 1, $dayTo = 31, $statusFilter = 'all', $companyFilter = 'all', $sourceFilter = 'all') {
    $rows = [];
    if ($pdo && $sourceFilter !== 'manual') {
        try {
            $params = [
                'from'      => $yearFrom . '-01-01 00:00:00',
                'to'        => ($yearTo + 1) . '-01-01 00:00:00',
                'startMonthDay' => ($monthFrom * 100) + $dayFrom,
                'endMonthDay'   => ($monthTo * 100) + $dayTo,
            ];
            $clause = getFilterWhereClause($statusFilter, $companyFilter, $params);
            $sql = "SELECT YEAR(o.order_datetime) AS y,
                        c.company_code,
                        SUM(o.sub_total) AS total
                 FROM orders o
                 JOIN companies c ON c.id = o.company_id
                 WHERE o.order_datetime >= :from
                   AND o.order_datetime <  :to
                   AND (MONTH(o.order_datetime) * 100 + DAY(o.order_datetime)) BETWEEN :startMonthDay AND :endMonthDay" . $clause . "
                 GROUP BY y, c.company_code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Exception $e) { $rows = []; }
    }

    $manualRows = [];
    if ($pdo && $sourceFilter !== 'system') {
        try {
            $mParams = [
                'from'      => $yearFrom . '-01-01',
                'to'        => $yearTo . '-12-31',
                'startMonthDay' => ($monthFrom * 100) + $dayFrom,
                'endMonthDay'   => ($monthTo * 100) + $dayTo,
            ];
            $mClause = getManualFilterWhereClause($companyFilter, $mParams);
            $sql = "SELECT YEAR(ms.sales_date) AS y,
                        c.company_code,
                        SUM(ms.amount) AS total
                 FROM manual_sales ms
                 JOIN companies c ON c.id = ms.company_id
                 WHERE ms.sales_date >= :from
                   AND ms.sales_date <= :to
                   AND (MONTH(ms.sales_date) * 100 + DAY(ms.sales_date)) BETWEEN :startMonthDay AND :endMonthDay" . $mClause . "
                 GROUP BY y, c.company_code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($mParams);
            $manualRows = $stmt->fetchAll();
        } catch (Exception $e) { $manualRows = []; }
    }

    $totalsByYear = [];
    foreach ($rows as $r) {
        $y = (int)$r['y'];
        $totalsByYear[$y] = ($totalsByYear[$y] ?? 0) + toMyr($r['total'], $r['company_code']);
    }
    foreach ($manualRows as $r) {
        $y = (int)$r['y'];
        $totalsByYear[$y] = ($totalsByYear[$y] ?? 0) + toMyr($r['total'], $r['company_code']);
    }

    $labels = []; $values = []; $keys = [];
    for ($y = $yearFrom; $y <= $yearTo; $y++) {
        $labels[] = (string)$y;
        $values[] = round($totalsByYear[$y] ?? 0, 2);
        $keys[]   = $y;
    }
    return ['labels' => $labels, 'values' => $values, 'keys' => $keys];
}

$pdo = getDBConnection();

// ════════════════════════════════════════════════════
// AJAX ENDPOINT — all filters (status/company/source/date) go through here,
// no page reload, no URL change. The initial page load ALSO goes through
// here now (fired by JS on DOMContentLoaded) instead of running queries
// directly in the HTML-rendering branch below.
// ════════════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $section       = $_GET['ajax'];
    $statusFilter  = normalizeStatus($_GET['status_filter'] ?? 'all');
    $companyFilter = normalizeCompany($_GET['company_filter'] ?? 'all');
    $sourceFilter  = normalizeSource($_GET['source_filter'] ?? 'all');

    if ($section === 'daily') {
        [$from, $to, $err] = clampDailyRange($_GET['daily_from'] ?? '', $_GET['daily_to'] ?? '');
        $data = getDailySales($pdo, $from, $to, $statusFilter, $companyFilter, $sourceFilter);
        echo json_encode(buildSectionResponse($data, $err, $from, $to));
        exit;
    }

    if ($section === 'monthly') {
        [$monthFrom, $monthTo, $dayFrom, $dayTo, $err] = clampMonthlyRange(
            $_GET['monthly_month_from'] ?? '',
            $_GET['monthly_month_to'] ?? '',
            $_GET['monthly_day_from'] ?? '',
            $_GET['monthly_day_to'] ?? ''
        );
        $data = getMonthlySales($pdo, $monthFrom, $monthTo, $dayFrom, $dayTo, $statusFilter, $companyFilter, $sourceFilter);
        $resp = buildSectionResponse($data, $err, $monthFrom, $monthTo);
        $resp['day_from'] = $dayFrom;
        $resp['day_to']   = $dayTo;
        echo json_encode($resp);
        exit;
    }

    if ($section === 'yearly') {
        [$yearFrom, $yearTo, $monthFrom, $monthTo, $dayFrom, $dayTo, $err] = clampYearlyRange(
            $_GET['yearly_year_from'] ?? '',
            $_GET['yearly_year_to'] ?? '',
            $_GET['yearly_month_from'] ?? '',
            $_GET['yearly_month_to'] ?? '',
            $_GET['yearly_day_from'] ?? '',
            $_GET['yearly_day_to'] ?? ''
        );
        $data = getYearlySales($pdo, $yearFrom, $yearTo, $monthFrom, $monthTo, $dayFrom, $dayTo, $statusFilter, $companyFilter, $sourceFilter);
        $resp = buildSectionResponse($data, $err, $yearFrom, $yearTo);
        $resp['month_from'] = $monthFrom;
        $resp['month_to']   = $monthTo;
        $resp['day_from']   = $dayFrom;
        $resp['day_to']     = $dayTo;
        echo json_encode($resp);
        exit;
    }

    echo json_encode(['error' => 'Invalid section']);
    exit;
}

// ════════════════════════════════════════════════════
// INITIAL PAGE LOAD — defaults ONLY. No DB queries happen here anymore.
// The three sections are populated by JS calling the same AJAX endpoint
// above right after the shell renders, so the first byte the browser gets
// is not blocked on any report calculation.
// ════════════════════════════════════════════════════
$todayYmd    = date('Y-m-d');
$currentYear = (int)date('Y');
$currentYm   = date('Y-m');
$previousYm  = date('Y-m', strtotime('-1 month'));
$yesterdayDay = (int)date('d', strtotime('-1 day'));

$statusFilter  = 'confirmed';
$companyFilter = 'all';
$sourceFilter  = 'all';

$dailyFrom = date('Y-m-d', strtotime('-2 days'));
$dailyTo   = date('Y-m-d', strtotime('-1 day'));

$monthlyMonthFrom = $previousYm;
$monthlyMonthTo   = $currentYm;
$monthlyDayFrom   = 1;
$monthlyDayTo     = $yesterdayDay;

$yearlyYearFrom  = $currentYear - 1;
$yearlyYearTo    = $currentYear;
$yearlyMonthFrom = 1;
$yearlyMonthTo   = (int)date('n');
$yearlyDayFrom   = 1;
$yearlyDayTo     = $yesterdayDay;

$yearOptionsStart = $currentYear - 15;
$yearOptionsEnd   = $currentYear + 1;

$monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sales Performance — S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="icon" href="../images/icon-sasia.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
:root{
  --red:#E0202E;--red-dark:#8E1620;--red-darker:#3B0B0F;
  --teal:#00B4B4;--teal-dark:#008A8A;
  --ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;
  --gray-300:#D8D8DE;--gray-100:#F2F2F4;
  --bg:#F5F5F7;--white:#FFFFFF;
  --gold:#F5A623;--green:#10B981;
  --radius-lg:18px;--radius-md:12px;--radius-sm:8px;
  --sidebar-w: 260px;--sidebar-w-collapsed: 82px;--topbar-h: 64px;
  --shadow:0 1px 2px rgba(20,20,30,.04),0 8px 24px -12px rgba(20,20,30,.10);
  --shadow-card:0 2px 8px rgba(20,20,30,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;}
a{text-decoration:none;color:inherit;}
button{font-family:inherit;cursor:pointer;border:none;background:none;}
svg{display:block;}

/* ── LAYOUT ── */
.layout{display:flex;margin-top:var(--topbar-h);}
.main{margin-left:var(--sidebar-w);width:calc(100% - var(--sidebar-w));flex:1;padding:28px 32px 48px;min-width:0;box-sizing:border-box;overflow-x:hidden;transition:margin-left .25s ease,width .25s ease;}
body.sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed);width:calc(100% - var(--sidebar-w-collapsed));}
@media(max-width:900px){.main{margin-left:0;width:100%;padding:20px;} body.sidebar-collapsed .main{margin-left:0;width:100%;}}

/* ── PAGE HEADER ── */
.page-header{margin-bottom:24px;}
.page-header h1{font-size:1.5rem;font-weight:800;margin-bottom:3px;}
.page-header p{font-size:0.875rem;color:var(--gray-500);}

/* ── REPORT CARDS ── */
.report-card{background:var(--white);border-radius:var(--radius-lg);padding:24px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);margin-bottom:24px;}
.report-card-head{display:flex;align-items:center;gap:12px;margin-bottom:20px;}
.report-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.report-icon svg{width:20px;height:20px;stroke:#fff;}
.ri-red{background:linear-gradient(135deg,var(--red),var(--red-dark));}
.ri-teal{background:linear-gradient(135deg,var(--teal),var(--teal-dark));}
.ri-gold{background:linear-gradient(135deg,var(--gold),#c97e0e);}
.ri-dark{background:linear-gradient(135deg,var(--gray-700),var(--ink));}
.report-card-title{font-size:1rem;font-weight:800;}
.report-card-sub{font-size:0.75rem;color:var(--gray-500);font-weight:500;margin-top:1px;}

/* ── GLOBAL FILTER BAR (Status + Company + Data Source, applies to all charts) ── */
.global-filter-card{border:1.5px solid var(--ink);}
.global-filter-row{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;}
.global-filter-group{display:flex;flex-direction:column;gap:6px;min-width:170px;}
.global-filter-group label{font-size:0.6875rem;font-weight:700;color:var(--gray-700);text-transform:uppercase;letter-spacing:.3px;}
.global-filter-group select{padding:10px 12px;border:1.5px solid var(--gray-300);border-radius:9px;font-size:0.875rem;font-family:'Plus Jakarta Sans',sans-serif;color:var(--ink);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;}
.global-filter-group select:focus{border-color:var(--ink);box-shadow:0 0 0 3px rgba(27,27,31,.1);}
.btn-apply-global{padding:11px 22px;background:var(--ink);color:#fff;border-radius:9px;font-size:0.8125rem;font-weight:800;display:inline-flex;align-items:center;gap:8px;transition:background .15s,opacity .15s;}
.btn-apply-global:hover{background:#000;}
.btn-apply-global:disabled{opacity:.6;cursor:not-allowed;}
.btn-apply-global svg{width:14px;height:14px;stroke:#fff;fill:none;}
.global-filter-hint{font-size:0.75rem;color:var(--gray-500);margin-left:auto;align-self:center;max-width:260px;}

/* ── two-column body: filter panel (left) + stats & chart (right) ── */
.report-card-body{display:grid;grid-template-columns:300px minmax(0,1fr);gap:28px;align-items:start;}
@media(max-width:860px){.report-card-body{grid-template-columns:1fr;}}
.report-card-main{min-width:0;}

.filter-panel{display:flex;flex-direction:column;gap:12px;background:var(--gray-100);border-radius:var(--radius-md);padding:16px;}
.filter-panel-label{font-size:0.6875rem;font-weight:700;color:var(--gray-700);text-transform:uppercase;letter-spacing:.3px;}
.filter-panel-label.spaced{margin-top:4px;}

.filter-inputs-row{display:flex;align-items:center;gap:8px;}
.filter-inputs-row select{flex:1;min-width:0;padding:9px 10px;border:1.5px solid var(--gray-300);border-radius:9px;font-size:0.8125rem;font-family:'Plus Jakarta Sans',sans-serif;color:var(--ink);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;}
.filter-inputs-row select:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.1);}
.filter-arrow{font-size:0.75rem;color:var(--gray-500);font-weight:700;flex-shrink:0;}

/* ── date range: stacked vertically + full width so the picked dates are clearly visible ── */
.date-range-stack{display:flex;flex-direction:column;gap:6px;}
.date-range-stack input{width:100%;padding:11px 12px;border:1.5px solid var(--gray-300);border-radius:9px;font-size:0.875rem;font-family:'Plus Jakarta Sans',sans-serif;color:var(--ink);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;}
.date-range-stack input:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.1);}
.date-range-arrow{font-size:0.75rem;color:var(--gray-500);font-weight:700;text-align:center;}

.btn-apply{padding:10px 16px;background:var(--red);color:#fff;border-radius:9px;font-size:0.8125rem;font-weight:800;display:flex;align-items:center;justify-content:center;gap:7px;box-shadow:0 4px 14px rgba(224,32,46,.25);transition:background .15s,opacity .15s;width:100%;}
.btn-apply:hover{background:var(--red-dark);}
.btn-apply:disabled{opacity:.6;cursor:not-allowed;}
.btn-apply svg{width:14px;height:14px;stroke:#fff;fill:none;}
.card-teal .btn-apply{background:var(--teal);box-shadow:0 4px 14px rgba(0,180,180,.25);}
.card-teal .btn-apply:hover{background:var(--teal-dark);}
.card-gold .btn-apply{background:var(--gold);box-shadow:0 4px 14px rgba(245,166,35,.25);}
.card-gold .btn-apply:hover{background:#c97e0e;}
.filter-msg{font-size:0.75rem;font-weight:600;padding:8px 12px;border-radius:8px;margin-bottom:14px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

/* ── stats row + chart type selector ── */
.stats-row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.stats-group{display:flex;gap:28px;flex-wrap:wrap;}
.stat-block .stat-label{font-size:0.6875rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.stat-block .stat-value{font-size:1.1875rem;font-weight:800;}
.stat-total .stat-value{color:var(--red);}
.card-teal .stat-total .stat-value{color:var(--teal-dark);}
.card-gold .stat-total .stat-value{color:#c97e0e;}
.stat-average .stat-value{color:var(--ink);}

.chart-type-select{padding:8px 30px 8px 14px;border:1.5px solid var(--gray-300);border-radius:9px;font-size:0.75rem;font-weight:700;font-family:'Plus Jakarta Sans',sans-serif;color:var(--ink);background:#fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="%238A8A93" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>') no-repeat right 12px center;appearance:none;-webkit-appearance:none;cursor:pointer;outline:none;flex-shrink:0;}
.chart-type-select:focus{border-color:var(--red);}

/* ── CHART AREA ── */
.chart-wrap{position:relative;height:320px;padding:18px 8px 0;transition:opacity .15s;}
.chart-wrap.is-loading{opacity:.35;pointer-events:none;}
.chart-empty{position:absolute;inset:0;display:none;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:var(--gray-500);font-size:0.8125rem;font-weight:600;text-align:center;}
.chart-empty.show{display:flex;}

/* ════════════════════════════════════════════════════
   BREAKDOWN TABLE — polished, aligned, no senget
   ════════════════════════════════════════════════════ */
.comparison-table-wrap{margin-top:24px;width:100%;}
.comparison-table-wrap.is-loading{opacity:.35;pointer-events:none;}
.comparison-table-title{
  font-size:0.6875rem;font-weight:800;color:var(--gray-700);
  text-transform:uppercase;letter-spacing:.5px;
  margin-bottom:10px;display:flex;align-items:center;gap:8px;
}
.comparison-table-title::before{
  content:"";width:3px;height:14px;border-radius:2px;background:var(--gray-300);
}
#dailyCard .comparison-table-title::before{background:var(--red);}
.card-teal .comparison-table-title::before{background:var(--teal);}
.card-gold .comparison-table-title::before{background:var(--gold);}

.comparison-table-scroll{
  overflow-x:auto;
  border:1px solid var(--gray-100);
  border-radius:var(--radius-md);
  background:#fff;
  box-shadow:0 1px 2px rgba(20,20,30,.03);
}

/* ── table: collapse + fixed layout supaya semua column sejajar ── */
.comparison-table{
  width:100%;
  border-collapse:collapse;
  border-spacing:0;
  font-size:0.75rem;
  table-layout:fixed;
}

/* ── column widths (4 columns) ── */
.comparison-table thead th:nth-child(1),
.comparison-table tbody td:nth-child(1),
.comparison-table tfoot td:nth-child(1){ width:34%; }

.comparison-table thead th:nth-child(2),
.comparison-table tbody td:nth-child(2),
.comparison-table tfoot td:nth-child(2){ width:22%; }

.comparison-table thead th:nth-child(3),
.comparison-table tbody td:nth-child(3),
.comparison-table tfoot td:nth-child(3){ width:22%; }

.comparison-table thead th:nth-child(4),
.comparison-table tbody td:nth-child(4),
.comparison-table tfoot td:nth-child(4){ width:22%; }

/* ── header ── */
.comparison-table thead th{
  padding:12px 16px;
  background:#F9F9FB;
  color:var(--gray-700);
  font-size:0.6875rem;font-weight:800;
  text-align:left;
  text-transform:uppercase;letter-spacing:.5px;
  white-space:nowrap;
  border-bottom:1.5px solid var(--gray-300);
  position:sticky;top:0;z-index:2;
}
.comparison-table thead th:not(:first-child){text-align:right;}

/* header colours per section */
#dailyCard .comparison-table thead th{
  background:linear-gradient(180deg,#FFF5F6,#FDEDEE);
  color:var(--red-dark);
  border-bottom-color:#F5C2C7;
}
.card-teal .comparison-table thead th{
  background:linear-gradient(180deg,#F0FDFD,#E0F7F7);
  color:var(--teal-dark);
  border-bottom-color:#A8E6E6;
}
.card-gold .comparison-table thead th{
  background:linear-gradient(180deg,#FFF9EE,#FEF3DC);
  color:#8A5A0E;
  border-bottom-color:#F3D9A3;
}

/* ── body ── */
.comparison-table tbody td{
  padding:11px 16px;
  border-top:1px solid var(--gray-100);
  white-space:nowrap;
  font-weight:600;
  color:var(--ink);
  font-variant-numeric:tabular-nums;
  vertical-align:middle;
}
.comparison-table tbody td:not(:first-child){text-align:right;}

/* zebra + hover */
.comparison-table tbody tr:nth-child(even){background:#FAFAFC;}
.comparison-table tbody tr{transition:background .15s ease;}
.comparison-table tbody tr:hover{background:#FFF0F1;}
.card-teal .comparison-table tbody tr:hover{background:#EAFBFA;}
.card-gold .comparison-table tbody tr:hover{background:#FFF8E9;}

/* first column — period label */
.comparison-table tbody td:first-child{
  font-weight:700;color:var(--ink);
  border-right:1px solid var(--gray-100);
}
/* Total sales column — section colour */
.comparison-table tbody td.col-total{ font-weight:800; color:var(--red-dark); }
.card-teal .comparison-table tbody td.col-total{color:var(--teal-dark);}
.card-gold .comparison-table tbody td.col-total{color:#8A5A0E;}

/* ── difference badges — inline-block, sejajar dgn header ── */
.change-positive,
.change-negative,
.change-neutral{
  display:inline-block;
  padding:3px 9px;
  border-radius:999px;
  font-size:inherit;font-weight:800;
  line-height:1.2;
  min-width:72px;
  text-align:right;
}
.change-positive{color:#065F46;background:#D1FAE5;}
.change-negative{color:#991B1B;background:#FEE2E2;}
.change-neutral {color:#6B7280;background:#F3F4F6;font-weight:700;}

/* empty (first row) — sama alignment dgn row lain, cuma warna pudar */
.comparison-table tbody td.empty-value,
.comparison-table tfoot td.empty-value{text-align:right;}
.empty-value .change-neutral{color:#B0B0B8;background:transparent;font-weight:700;min-width:0;padding:0;}

/* footer total */
.comparison-table tfoot td{
  padding:13px 16px;
  font-weight:800;
  border-top:2px solid var(--ink);
  background:#F9F9FB;
  color:var(--ink);
  font-variant-numeric:tabular-nums;
  white-space:nowrap;
}
.comparison-table tfoot td:not(:first-child){text-align:right;}
.comparison-table tfoot td.col-total{ color:var(--red-dark); }
.card-teal .comparison-table tfoot td.col-total{color:var(--teal-dark);}
.card-gold .comparison-table tfoot td.col-total{color:#8A5A0E;}

@media(max-width:600px){
  .main{padding:16px 14px 40px;}
  .stats-row{flex-direction:column;align-items:flex-start;}
  .global-filter-hint{margin-left:0;max-width:none;}
  .comparison-table tbody td,
  .comparison-table thead th,
  .comparison-table tfoot td{padding:9px 10px;font-size:0.75rem;}
  .change-positive,.change-negative,.change-neutral{padding:2px 7px;font-size:inherit;min-width:60px;}
}
</style>
<link rel="stylesheet" href="../includes/report_tables.css">
</head>
<body>
<script>
(function(){
    try {
        if (window.innerWidth >= 900 && localStorage.getItem('adminSidebarCollapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (e) {}
})();
</script>

<?php $pageTitle = 'Sales Comparison'; include __DIR__ . '/../includes/topnav.php'; ?>

<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="layout">
<main class="main">

  <div class="page-header">
    <h1>Sales Comparison</h1>
    <p>Comparison Daily, Monthly &amp; Yearly Sales — pick a range and view the chart.</p>
  </div>

  <!-- ═══════════════ GLOBAL FILTER: STATUS + COMPANY + DATA SOURCE ═══════════════ -->
  <div class="report-card global-filter-card">
    <div class="report-card-head">
      <div class="report-icon ri-dark"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg></div>
      <div>
        <div class="report-card-title">Filter Status, Company &amp; Data Source</div>
        <div class="report-card-sub">Applied to Daily, Monthly &amp; Yearly charts below at the same time</div>
      </div>
    </div>
    <div class="global-filter-row">
      <div class="global-filter-group">
        <label for="globalStatus">Status</label>
        <select id="globalStatus">
          <option value="all">All</option>
          <option value="confirmed" selected>Confirmed</option>
          <option value="void">Void</option>
        </select>
      </div>
      <div class="global-filter-group">
        <label for="globalCompany">Company</label>
        <select id="globalCompany">
          <option value="all" selected>All</option>
          <option value="MY">MY</option>
          <option value="SG">SG</option>
        </select>
      </div>
      <div class="global-filter-group">
        <label for="globalSource">Data Source</label>
        <select id="globalSource">
          <option value="all" selected>All (System + Manual)</option>
          <option value="system">System Sales</option>
          <option value="manual">Manual Sales</option>
        </select>
      </div>
      <button type="button" class="btn-apply-global" id="btnApplyGlobal">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Apply to All Charts
      </button>
    </div>
  </div>

  <!-- ═══════════════ DAILY ═══════════════ -->
  <div class="report-card" id="dailyCard">
    <div class="report-card-head">
      <div class="report-icon ri-red"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
      <div>
        <div class="report-card-title">Comparison Daily Sales</div>
        <div class="report-card-sub">Total sales per day, within the selected date range</div>
      </div>
    </div>

    <div class="filter-msg" id="dailyErrorMsg" style="display:none;"></div>

    <div class="report-card-body">
      <form class="filter-panel" id="dailyForm">
        <div class="filter-panel-label">Filter Date Range</div>
        <div class="date-range-stack">
          <input type="date" id="dailyFrom" value="<?= htmlspecialchars($dailyFrom) ?>" max="<?= htmlspecialchars($todayYmd) ?>">
          <div class="date-range-arrow">↓</div>
          <input type="date" id="dailyTo" value="<?= htmlspecialchars($dailyTo) ?>" max="<?= htmlspecialchars($todayYmd) ?>">
        </div>
        <button type="submit" class="btn-apply">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Apply Filter
        </button>
      </form>

      <div class="report-card-main">
        <div class="stats-row">
          <div class="stats-group">
            <div class="stat-block stat-total">
              <div class="stat-label">Total Sales</div>
              <div class="stat-value" id="dailyTotalValue">—</div>
            </div>
            <div class="stat-block stat-average">
              <div class="stat-label">Average Daily Sales</div>
              <div class="stat-value" id="dailyAverageValue">—</div>
            </div>
          </div>
          <select class="chart-type-select" data-chart="daily">
            <option value="line" >Line Chart</option>
            <option value="bar" selected>Bar Chart</option>
          </select>
        </div>
        <div class="chart-wrap" id="dailyChartWrap">
          <canvas id="dailyChart"></canvas>
          <div class="chart-empty" id="dailyEmpty">No sales data in this range.</div>
        </div>
      </div>
    </div>

    <!-- Breakdown table: full card width, mirrors dailyForm's current filtered range -->
    <div class="comparison-table-wrap" id="dailyTableWrap">
      <div class="comparison-table-title">Daily Sales Breakdown</div>
      <div class="comparison-table-scroll">
        <table class="comparison-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Total Sales</th>
              <th>Difference (RM)</th>
              <th>Difference (%)</th>
            </tr>
          </thead>
          <tbody id="dailyTableBody"></tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td class="col-total" id="dailyTableTotal">—</td>
              <td class="empty-value">—</td>
              <td class="empty-value">—</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════ MONTHLY ═══════════════ -->
  <div class="report-card card-teal" id="monthlyCard">
    <div class="report-card-head">
      <div class="report-icon ri-teal"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg></div>
      <div>
        <div class="report-card-title">Comparison Monthly Sales</div>
        <div class="report-card-sub">Total sales per month, plus an optional day-of-month range within each month</div>
      </div>
    </div>

    <div class="filter-msg" id="monthlyErrorMsg" style="display:none;"></div>

    <div class="report-card-body">
      <form class="filter-panel" id="monthlyForm">
        <div class="filter-panel-label">Filter Month Range</div>
        <div class="date-range-stack">
          <input type="month" id="monthlyMonthFrom" value="<?= htmlspecialchars($monthlyMonthFrom) ?>" max="<?= htmlspecialchars($currentYm) ?>">
          <div class="date-range-arrow">↓</div>
          <input type="month" id="monthlyMonthTo" value="<?= htmlspecialchars($monthlyMonthTo) ?>" max="<?= htmlspecialchars($currentYm) ?>">
        </div>

        <div class="filter-panel-label spaced">Day Range (within each month)</div>
        <div class="filter-inputs-row">
          <select id="monthlyDayFrom">
            <?php for ($d = 1; $d <= 31; $d++): ?>
              <option value="<?= $d ?>" <?= $d === $monthlyDayFrom ? 'selected' : '' ?>><?= $d ?></option>
            <?php endfor; ?>
          </select>
          <span class="filter-arrow">–</span>
          <select id="monthlyDayTo">
            <?php for ($d = 1; $d <= 31; $d++): ?>
              <option value="<?= $d ?>" <?= $d === $monthlyDayTo ? 'selected' : '' ?>><?= $d ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <button type="submit" class="btn-apply">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Apply Filter
        </button>
      </form>

      <div class="report-card-main">
        <div class="stats-row">
          <div class="stats-group">
            <div class="stat-block stat-total">
              <div class="stat-label">Total Sales</div>
              <div class="stat-value" id="monthlyTotalValue">—</div>
            </div>
            <div class="stat-block stat-average">
              <div class="stat-label">Average Monthly Sales</div>
              <div class="stat-value" id="monthlyAverageValue">—</div>
            </div>
          </div>
          <select class="chart-type-select" data-chart="monthly">
            <option value="line" >Line Chart</option>
            <option value="bar" selected>Bar Chart</option>
          </select>
        </div>
        <div class="chart-wrap" id="monthlyChartWrap">
          <canvas id="monthlyChart"></canvas>
          <div class="chart-empty" id="monthlyEmpty">No sales data in this range.</div>
        </div>
      </div>
    </div>

    <!-- Breakdown table: full card width, mirrors monthlyForm's current filtered range -->
    <div class="comparison-table-wrap" id="monthlyTableWrap">
      <div class="comparison-table-title">Monthly Sales Breakdown</div>
      <div class="comparison-table-scroll">
        <table class="comparison-table">
          <thead>
            <tr>
              <th>Month</th>
              <th>Total Sales</th>
              <th>Difference (RM)</th>
              <th>Difference (%)</th>
            </tr>
          </thead>
          <tbody id="monthlyTableBody"></tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td class="col-total" id="monthlyTableTotal">—</td>
              <td class="empty-value">—</td>
              <td class="empty-value">—</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════ YEARLY ═══════════════ -->
  <div class="report-card card-gold" id="yearlyCard">
    <div class="report-card-head">
      <div class="report-icon ri-gold"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
      <div>
        <div class="report-card-title">Comparison Yearly Sales</div>
        <div class="report-card-sub">Total sales per year, using the same month + day window for every year — e.g. compare 2025 vs 2026, Jan 1 - Aug 25 only</div>
      </div>
    </div>

    <div class="filter-msg" id="yearlyErrorMsg" style="display:none;"></div>

    <div class="report-card-body">
      <form class="filter-panel" id="yearlyForm">
        <div class="filter-panel-label">Filter Year Range</div>
        <div class="filter-inputs-row">
          <select id="yearlyYearFrom">
            <?php for ($y = $yearOptionsEnd; $y >= $yearOptionsStart; $y--): ?>
              <option value="<?= $y ?>" <?= $y === $yearlyYearFrom ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
          <span class="filter-arrow">–</span>
          <select id="yearlyYearTo">
            <?php for ($y = $yearOptionsEnd; $y >= $yearOptionsStart; $y--): ?>
              <option value="<?= $y ?>" <?= $y === $yearlyYearTo ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="filter-panel-label spaced">Start Month – End Month (each year)</div>
        <div class="filter-inputs-row">
          <select id="yearlyMonthFrom">
            <?php foreach ($monthNames as $num => $name): ?>
              <option value="<?= $num ?>" <?= $num === $yearlyMonthFrom ? 'selected' : '' ?>><?= $name ?></option>
            <?php endforeach; ?>
          </select>
          <span class="filter-arrow">–</span>
          <select id="yearlyMonthTo">
            <?php foreach ($monthNames as $num => $name): ?>
              <option value="<?= $num ?>" <?= $num === $yearlyMonthTo ? 'selected' : '' ?>><?= $name ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filter-panel-label spaced">Start Day – End Day (within the date range)</div>
        <div class="filter-inputs-row">
          <select id="yearlyDayFrom">
            <?php for ($d = 1; $d <= 31; $d++): ?>
              <option value="<?= $d ?>" <?= $d === $yearlyDayFrom ? 'selected' : '' ?>><?= $d ?></option>
            <?php endfor; ?>
          </select>
          <span class="filter-arrow">–</span>
          <select id="yearlyDayTo">
            <?php for ($d = 1; $d <= 31; $d++): ?>
              <option value="<?= $d ?>" <?= $d === $yearlyDayTo ? 'selected' : '' ?>><?= $d ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <button type="submit" class="btn-apply">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Apply Filter
        </button>
      </form>

      <div class="report-card-main">
        <div class="stats-row">
          <div class="stats-group">
            <div class="stat-block stat-total">
              <div class="stat-label">Total Sales</div>
              <div class="stat-value" id="yearlyTotalValue">—</div>
            </div>
            <div class="stat-block stat-average">
              <div class="stat-label">Average Yearly Sales</div>
              <div class="stat-value" id="yearlyAverageValue">—</div>
            </div>
          </div>
          <select class="chart-type-select" data-chart="yearly">
            <option value="line" >Line Chart</option>
            <option value="bar" selected>Bar Chart</option>
          </select>
        </div>
        <div class="chart-wrap" id="yearlyChartWrap">
          <canvas id="yearlyChart"></canvas>
          <div class="chart-empty" id="yearlyEmpty">No sales data in this range.</div>
        </div>
      </div>
    </div>

    <!-- Breakdown table: full card width, mirrors yearlyForm's current filtered range -->
    <div class="comparison-table-wrap" id="yearlyTableWrap">
      <div class="comparison-table-title">Yearly Sales Breakdown</div>
      <div class="comparison-table-scroll">
        <table class="comparison-table">
          <thead>
            <tr>
              <th>Year</th>
              <th>Total Sales</th>
              <th>Difference (RM)</th>
              <th>Difference (%)</th>
            </tr>
          </thead>
          <tbody id="yearlyTableBody"></tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td class="col-total" id="yearlyTableTotal">—</td>
              <td class="empty-value">—</td>
              <td class="empty-value">—</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

</main>
</div><!-- layout -->

<script>
function formatRM(n){
    return 'RM ' + Number(n || 0).toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function chartBaseOptions(){
    return {
        responsive:true, maintainAspectRatio:false,
    layout:{padding:{top:24,right:10,left:4,bottom:4}},
        plugins:{
            legend:{display:false},
            tooltip:{
                backgroundColor:'#1B1B1F', padding:10, cornerRadius:8,
                titleFont:{family:"'Plus Jakarta Sans'", weight:'700'},
                bodyFont:{family:"'Plus Jakarta Sans'"},
                callbacks:{ label:(ctx)=> formatRM(ctx.parsed.y) }
            }
        },
        scales:{
            x:{ grid:{display:false}, ticks:{font:{family:"'Plus Jakarta Sans'", weight:'600', size:11}} },
            y:{ beginAtZero:true, grace:'12%', grid:{color:'#F2F2F4'}, ticks:{font:{family:"'Plus Jakarta Sans'"}, callback:(v)=> 'RM ' + v.toLocaleString('en-MY')} }
        }
    };
}

  const barValueLabels = {
    id: 'barValueLabels',
    afterDatasetsDraw(chart) {
      if (chart.config.type !== 'bar') return;

      const context = chart.ctx;
      context.save();
      context.font = "700 12px 'Plus Jakarta Sans', sans-serif";
      context.fillStyle = '#4A4A52';
      context.textAlign = 'center';
      context.textBaseline = 'bottom';

      const occupiedLabels = [];
      chart.data.datasets.forEach((dataset, datasetIndex) => {
        const meta = chart.getDatasetMeta(datasetIndex);
        meta.data.forEach((bar, index) => {
          const value = Number(dataset.data[index] || 0);
          if (value <= 0) return;

          const text = formatRM(value);
          const textWidth = context.measureText(text).width;
          const left = bar.x - textWidth / 2;
          const right = bar.x + textWidth / 2;
          const hasCollision = occupiedLabels.some(label => left < label.right + 6 && right > label.left - 6);

          // Skip tightly packed labels rather than letting values overlap.
          if (hasCollision && bar.width < textWidth) return;

          context.fillText(text, bar.x, bar.y - 8);
          occupiedLabels.push({ left, right });
        });
      });

      context.restore();
    }
  };

function makeDataset(type, label, data, color, hoverColor, ctx){
    if (type === 'line') {
        const gradient = ctx.createLinearGradient(0, 0, 0, 280);
        gradient.addColorStop(0, color + '33');
        gradient.addColorStop(1, color + '00');
        return {
            label, data,
            borderColor: color, backgroundColor: gradient,
            pointBackgroundColor: color, pointBorderColor: '#fff', pointBorderWidth:2,
            pointRadius:4, pointHoverRadius:6,
            borderWidth:2.5, fill:true, tension:0.35
        };
    }
    return { label, data, backgroundColor:color, hoverBackgroundColor:hoverColor, borderRadius:6, maxBarThickness:46 };
}

// ── Chart data now starts EMPTY. PHP no longer runs any report query on
//    page load — fetchSection() below fills each of these right after the
//    shell is visible, in parallel, instead of PHP blocking on them first. ──
const chartData = {
    daily:   { labels: [], values: [], keys: [], label:'Daily Sales',   color:'#E0202E', hover:'#8E1620' },
    monthly: { labels: [], values: [], keys: [], label:'Monthly Sales', color:'#00B4B4', hover:'#008A8A' },
    yearly:  { labels: [], values: [], keys: [], label:'Yearly Sales',  color:'#F5A623', hover:'#c97e0e' }
};

// ── Current day/month sub-range for each section (so the breakdown table can
//    show the actual date detail, e.g. "1 - 20 Aug 2026" instead of just "Aug 2026") ──
const MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const sectionMeta = {
    monthly: { dayFrom: <?= (int)$monthlyDayFrom ?>, dayTo: <?= (int)$monthlyDayTo ?> },
    yearly:  { monthFrom: <?= (int)$yearlyMonthFrom ?>, monthTo: <?= (int)$yearlyMonthTo ?>, dayFrom: <?= (int)$yearlyDayFrom ?>, dayTo: <?= (int)$yearlyDayTo ?> }
};

const chartInstances = {};

function renderChart(key, type){
    if (typeof Chart === 'undefined') {
        console.error('Chart.js failed to load. Check the CDN script tag or network access.');
        return;
    }

    const canvasId = key + 'Chart';
    const emptyId  = key + 'Empty';
    const { labels, values, label, color, hover } = chartData[key];

    const total = values.reduce((a,b)=>a+Number(b),0);
    document.getElementById(emptyId).classList.toggle('show', total === 0);

    if (chartInstances[key]) {
        chartInstances[key].destroy();
    }

    const ctx = document.getElementById(canvasId).getContext('2d');
    chartInstances[key] = new Chart(ctx, {
        type: type === 'line' ? 'line' : 'bar',
        data:{ labels, datasets:[ makeDataset(type, label, values, color, hover, ctx) ] },
      options: chartBaseOptions(),
      plugins: type === 'bar' ? [barValueLabels] : []
    });
}

// ════════════════════════════════════════════════════
// Build the human-readable period label for a table row.
// Daily: chart label is already a full date ("17 Aug") — use as-is.
// Monthly: show the day-of-month range picked, e.g. "1 - 20 Aug 2026".
// Yearly: show the month+day range picked, e.g. "1 Jan - 20 Aug 2025".
// ════════════════════════════════════════════════════
function buildPeriodLabel(key, label, rowKey){
    if (key === 'monthly') {
        const meta = sectionMeta.monthly;
        if (!rowKey) return label;
        const parts = rowKey.split('-');
        const y = Number(parts[0]);
        const m = Number(parts[1]);
        const monthName = MONTH_NAMES[m - 1] || '';
        return meta.dayFrom + ' - ' + meta.dayTo + ' ' + monthName + ' ' + y;
    }
    if (key === 'yearly') {
        const meta = sectionMeta.yearly;
        const y = Number(rowKey || label);
        const fromName = MONTH_NAMES[meta.monthFrom - 1] || '';
        const toName   = MONTH_NAMES[meta.monthTo - 1] || '';
        if (meta.monthFrom === meta.monthTo) {
            return meta.dayFrom + ' - ' + meta.dayTo + ' ' + fromName + ' ' + y;
        }
        return meta.dayFrom + ' ' + fromName + ' - ' + meta.dayTo + ' ' + toName + ' ' + y;
    }
    return label;
}

// ════════════════════════════════════════════════════
// BREAKDOWN TABLE — mirrors chartData[key], one row per label,
// "Difference (RM)" compares each row to the row before it in the SAME range.
// ════════════════════════════════════════════════════
function renderTable(key){
    const tbody = document.getElementById(key + 'TableBody');
    if (!tbody) return;

    const { labels, values, keys } = chartData[key];

    if (!labels.length) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--gray-500);padding:16px;">No data in this range.</td></tr>';
        const totalCellEmpty = document.getElementById(key + 'TableTotal');
        if (totalCellEmpty) totalCellEmpty.textContent = formatRM(0);
        return;
    }

    let rowsHtml = '';
    let prevValue = null;
    let grandTotal = 0;

    labels.forEach(function(label, i){
        const value = Number(values[i]) || 0;
        grandTotal += value;
        const rowKey = keys && keys[i] !== undefined ? keys[i] : null;
        const periodLabel = buildPeriodLabel(key, label, rowKey);
        let differenceRmCell = '<span class="change-neutral">–</span>';
        let differencePctCell = '<span class="change-neutral">–</span>';

        if (prevValue !== null) {
          const difference = value - prevValue;
          const cls = difference > 0 ? 'change-positive' : (difference < 0 ? 'change-negative' : 'change-neutral');
          const sign = difference > 0 ? '+' : (difference < 0 ? '-' : '');
          differenceRmCell = '<span class="' + cls + '">' + sign + formatRM(Math.abs(difference)) + '</span>';

          if (prevValue === 0) {
            differencePctCell = value > 0
              ? '<span class="change-positive">New</span>'
              : '<span class="change-neutral">0.00%</span>';
          } else {
            const percentage = (difference / Math.abs(prevValue)) * 100;
            const percentageSign = percentage > 0 ? '+' : '';
            differencePctCell = '<span class="' + cls + '">' + percentageSign + percentage.toFixed(2) + '%</span>';
          }
        }

        rowsHtml += '<tr>'
            + '<td>' + periodLabel + '</td>'
            + '<td class="col-total">' + formatRM(value) + '</td>'
            + '<td' + (prevValue === null ? ' class="empty-value"' : '') + '>' + differenceRmCell + '</td>'
            + '<td' + (prevValue === null ? ' class="empty-value"' : '') + '>' + differencePctCell + '</td>'
            + '</tr>';

        prevValue = value;
    });

    tbody.innerHTML = rowsHtml;

    // Update footer total
    const totalCell = document.getElementById(key + 'TableTotal');
    if (totalCell) totalCell.textContent = formatRM(grandTotal);
}

// ════════════════════════════════════════════════════
// AJAX FILTERING — the page URL never changes, everything goes through fetch()
// ════════════════════════════════════════════════════
const AJAX_URL = window.location.pathname;

function setSectionLoading(key, isLoading){
    document.getElementById(key + 'ChartWrap').classList.toggle('is-loading', isLoading);
    const tableWrap = document.getElementById(key + 'TableWrap');
    if (tableWrap) tableWrap.classList.toggle('is-loading', isLoading);
    const form = document.getElementById(key + 'Form');
    if (form) {
        const btn = form.querySelector('.btn-apply');
        if (btn) btn.disabled = isLoading;
    }
}

function getSectionParams(key){
    if (key === 'daily') {
        return {
            daily_from: document.getElementById('dailyFrom').value,
            daily_to:   document.getElementById('dailyTo').value
        };
    }
    if (key === 'monthly') {
        return {
            monthly_month_from: document.getElementById('monthlyMonthFrom').value,
            monthly_month_to:   document.getElementById('monthlyMonthTo').value,
            monthly_day_from:   document.getElementById('monthlyDayFrom').value,
            monthly_day_to:     document.getElementById('monthlyDayTo').value
        };
    }
    // yearly
    return {
        yearly_year_from:  document.getElementById('yearlyYearFrom').value,
        yearly_year_to:    document.getElementById('yearlyYearTo').value,
        yearly_month_from: document.getElementById('yearlyMonthFrom').value,
        yearly_month_to:   document.getElementById('yearlyMonthTo').value,
        yearly_day_from:   document.getElementById('yearlyDayFrom').value,
        yearly_day_to:     document.getElementById('yearlyDayTo').value
    };
}

async function fetchSection(key){
    const status  = document.getElementById('globalStatus').value;
    const company = document.getElementById('globalCompany').value;
    const source  = document.getElementById('globalSource').value;

    const params = new URLSearchParams();
    params.set('ajax', key);
    params.set('status_filter', status);
    params.set('company_filter', company);
    params.set('source_filter', source);

    const sectionParams = getSectionParams(key);
    Object.keys(sectionParams).forEach(k => params.set(k, sectionParams[k]));

    setSectionLoading(key, true);
    try {
        const res = await fetch(AJAX_URL + '?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        applySectionResult(key, json);
    } catch (err) {
        const errDiv = document.getElementById(key + 'ErrorMsg');
        errDiv.textContent = 'Failed to load data. Please try again.';
        errDiv.style.display = 'block';
    } finally {
        setSectionLoading(key, false);
    }
}

function applySectionResult(key, json){
    chartData[key].labels = json.labels || [];
    chartData[key].values = json.values || [];
    chartData[key].keys   = json.keys || [];

    document.getElementById(key + 'TotalValue').textContent   = formatRM(json.total);
    document.getElementById(key + 'AverageValue').textContent = formatRM(json.average);

    const errDiv = document.getElementById(key + 'ErrorMsg');
    if (json.error) {
        errDiv.textContent = json.error;
        errDiv.style.display = 'block';
    } else {
        errDiv.style.display = 'none';
    }

    // sync corrected values back into the inputs (server clamps invalid ranges)
    if (key === 'daily') {
        if (json.from) document.getElementById('dailyFrom').value = json.from;
        if (json.to)   document.getElementById('dailyTo').value   = json.to;
    } else if (key === 'monthly') {
        if (json.from) document.getElementById('monthlyMonthFrom').value = json.from;
        if (json.to)   document.getElementById('monthlyMonthTo').value   = json.to;
        if (json.day_from) document.getElementById('monthlyDayFrom').value = json.day_from;
        if (json.day_to)   document.getElementById('monthlyDayTo').value   = json.day_to;
        if (json.day_from) sectionMeta.monthly.dayFrom = Number(json.day_from);
        if (json.day_to)   sectionMeta.monthly.dayTo   = Number(json.day_to);
    } else if (key === 'yearly') {
        if (json.from) document.getElementById('yearlyYearFrom').value = String(json.from);
        if (json.to)   document.getElementById('yearlyYearTo').value   = String(json.to);
        if (json.month_from) document.getElementById('yearlyMonthFrom').value = json.month_from;
        if (json.month_to)   document.getElementById('yearlyMonthTo').value   = json.month_to;
        if (json.day_from)   document.getElementById('yearlyDayFrom').value   = json.day_from;
        if (json.day_to)     document.getElementById('yearlyDayTo').value     = json.day_to;
        if (json.month_from) sectionMeta.yearly.monthFrom = Number(json.month_from);
        if (json.month_to)   sectionMeta.yearly.monthTo   = Number(json.month_to);
        if (json.day_from)   sectionMeta.yearly.dayFrom   = Number(json.day_from);
        if (json.day_to)     sectionMeta.yearly.dayTo     = Number(json.day_to);
    }

    const typeSelect = document.querySelector('.chart-type-select[data-chart="' + key + '"]');
    renderChart(key, typeSelect ? typeSelect.value : 'line');
    renderTable(key);
}

document.addEventListener('DOMContentLoaded', function(){
    if (typeof Chart === 'undefined') {
        console.error('Chart.js failed to load. Sales charts will not render.');
        return;
    }

    document.querySelectorAll('.chart-type-select').forEach(function(select){
        select.addEventListener('change', function(){
            renderChart(this.dataset.chart, this.value);
        });
    });

    // per-section filter (Apply Filter) — only refreshes that section
    ['daily','monthly','yearly'].forEach(function(key){
        document.getElementById(key + 'Form').addEventListener('submit', function(e){
            e.preventDefault();
            fetchSection(key);
        });
    });

    // global filter (Status + Company + Data Source) — refreshes ALL 3 charts at once
    document.getElementById('btnApplyGlobal').addEventListener('click', function(){
        fetchSection('daily');
        fetchSection('monthly');
        fetchSection('yearly');
    });

    // ── Initial load: fire all 3 sections via the SAME AJAX endpoint used by
    //    the filters above, in parallel, instead of PHP computing them first.
    //    This is what actually removes the up-front DB blocking on page load. ──
    ['daily','monthly','yearly'].forEach(function(key){
        fetchSection(key);
    });
});
</script>
</body>
</html>