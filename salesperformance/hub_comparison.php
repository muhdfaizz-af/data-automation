<?php
/**
 * Sales by Hub Report
 * Covers spec sections:
 *   3.4 Comparison Sales by Hub   (Daily / MTD / YTD — pie + table, per hub)
 *   3.5 Sales and Target by Hub  (Daily / Monthly — bar chart, Target vs Actual, Different %)
 *   3.6 Sales Increment by Hub   (MTD per month, same day-of-month cut-off, month-on-month increment)
 *
 * ── HUB CLASSIFICATION (from spec) ──────────────────────────────────────────
 *   West Malaysia : orders (MY co.) where order_id starts with 'MYH'
 *   East Malaysia : orders (MY co.) where order_id starts with 'MYB' AND member_code does NOT start with 'BN'
 *   Brunei        : orders (MY co.) where order_id starts with 'MYB' AND member_code starts with 'BN'
 *   Singapore     : orders (SG co.) — all applicable records
 *
 * ── TARGET (3.5) ─────────────────────────────────────────────────────────
 *   Target figures are entered by the admin directly in the browser and are
 *   NOT persisted to the database (per requirement). They live only in the
 *   page's JS state for the current session/reload and are used purely to
 *   compute "Different %" against the Actual Sales already fetched from the
 *   server. Reloading the page clears them — this is intentional.
 *
 * ── STATUS / SOURCE ──────────────────────────────────────────────────────
 *   Only `orders` (System sales / Order History) is used here — the spec's
 *   "Sources" column for every hub is "Order History MY/SG", not manual_sales.
 *   Status filter (Confirmed/Void/All) applies globally, same as
 *   sales-performance.php.
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
$activeNav = 'salesbyhub';
$navBasePath = '../';

function getDBConnection() {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
        return new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (Exception $e) { return null; }
}

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

// ════════════════════════════════════════════════════
// HUB DEFINITIONS
// ════════════════════════════════════════════════════
const HUBS = [
    'west'      => ['label' => 'West Malaysia', 'color' => '#2563EB', 'hover' => '#1D4ED8'],
    'east'      => ['label' => 'East Malaysia',  'color' => '#00B4B4', 'hover' => '#008A8A'],
    'brunei'    => ['label' => 'Brunei',         'color' => '#E0202E', 'hover' => '#8E1620'],
    'singapore' => ['label' => 'Singapore',      'color' => '#F5A623', 'hover' => '#c97e0e'],
];

/**
 * SQL CASE expression that classifies each order row into a hub key.
 * Requires `o` = orders alias, `c` = companies alias (joined).
 */
function hubClassifyExpr() {
    return "CASE
        WHEN c.company_code = 'SG' THEN 'singapore'
        WHEN o.order_id LIKE 'MYH%' THEN 'west'
        WHEN o.order_id LIKE 'MYB%' AND (o.member_code IS NULL OR o.member_code NOT LIKE 'BN%') THEN 'east'
        WHEN o.order_id LIKE 'MYB%' AND o.member_code LIKE 'BN%' THEN 'brunei'
        ELSE NULL
    END";
}

/**
 * Sum `sub_total` (converted to MYR) per hub, for orders with
 * order_datetime in [$fromDt, $toExclusiveDt).
 * Returns assoc array keyed by hub -> float total (always has all 4 keys).
 */
function getHubTotals($pdo, $fromDt, $toExclusiveDt, $statusFilter = 'all') {
    $totals = array_fill_keys(array_keys(HUBS), 0.0);
    if (!$pdo) return $totals;
    try {
        $params = ['from' => $fromDt, 'to' => $toExclusiveDt];
        $statusClause = '';
        if ($statusFilter !== 'all') {
            $statusClause = " AND o.order_status = :status";
            $params['status'] = $statusFilter === 'confirmed' ? 'Confirmed' : 'Void';
        }
        $hubExpr = hubClassifyExpr();
        $sql = "SELECT $hubExpr AS hub, c.company_code, SUM(o.sub_total) AS total
                FROM orders o
                JOIN companies c ON c.id = o.company_id
                WHERE o.order_datetime >= :from
                  AND o.order_datetime <  :to" . $statusClause . "
                GROUP BY hub, c.company_code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $hub = $r['hub'];
            if (!$hub || !array_key_exists($hub, $totals)) continue; // ignore unclassified rows
            $totals[$hub] += toMyr($r['total'], $r['company_code']);
        }
    } catch (Exception $e) { /* keep zeros */ }
    return $totals;
}

/**
 * MTD totals per hub for a given year/month, cut off at min(cutoffDay, days-in-month).
 */
function getHubMonthlyMTD($pdo, $year, $month, $cutoffDay, $statusFilter = 'all') {
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $day = min($cutoffDay, $daysInMonth);
    $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $toDt = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
    $toDt->modify('+1 day');
    return getHubTotals($pdo, $from, $toDt->format('Y-m-d H:i:s'), $statusFilter);
}

/**
 * Turn a hub->total array into the {grand,total_fmt,hubs:{key:{total,pct}}} shape used in JSON responses.
 */
function buildPeriodPayload($totals) {
    $grand = array_sum($totals);
    $hubs = [];
    foreach (HUBS as $key => $meta) {
        $total = $totals[$key] ?? 0.0;
        $pct = $grand > 0 ? ($total / $grand * 100) : 0.0;
        $hubs[$key] = ['total' => round($total, 2), 'pct' => round($pct, 2)];
    }
    return ['grand' => round($grand, 2), 'hubs' => $hubs];
}

// ════════════════════════════════════════════════════
// VALIDATION / CLAMPING
// ════════════════════════════════════════════════════
function clampReportDate($v) {
    $today = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) return [$today, ''];
    if ($v > $today) return [$today, 'Reporting date cannot be in the future.'];
    if ($v < '2000-01-01') return [$today, 'Invalid reporting date.'];
    return [$v, ''];
}

function clampYear($v) {
    $currentYear = (int)date('Y');
    $v = (int)$v;
    if ($v < 2000 || $v > $currentYear) return [$currentYear, ''];
    return [$v, ''];
}

// ════════════════════════════════════════════════════
// 3.4 + 3.5 DATA — Daily / MTD / YTD totals by hub, as of a reporting date
// ════════════════════════════════════════════════════
function getHubSummary($pdo, $reportDate, $statusFilter) {
    $dt = new DateTime($reportDate);

    $dayFrom = (clone $dt)->format('Y-m-d 00:00:00');
    $dayTo   = (clone $dt)->modify('+1 day')->format('Y-m-d 00:00:00');

    $monthFrom = (clone $dt)->modify('first day of this month')->format('Y-m-d 00:00:00');

    $yearFrom = $dt->format('Y') . '-01-01 00:00:00';

    $daily   = getHubTotals($pdo, $dayFrom, $dayTo, $statusFilter);
    $monthly = getHubTotals($pdo, $monthFrom, $dayTo, $statusFilter);
    $yearly  = getHubTotals($pdo, $yearFrom, $dayTo, $statusFilter);

    return [
        'report_date' => $reportDate,
        'daily'       => buildPeriodPayload($daily),
        'monthly'     => buildPeriodPayload($monthly),
        'yearly'      => buildPeriodPayload($yearly),
    ];
}

// ════════════════════════════════════════════════════
// 3.6 DATA — MTD per month (same day-of-month cutoff), with month-on-month increment
// ════════════════════════════════════════════════════
function getHubIncrement($pdo, $year, $cutoffDay, $statusFilter) {
    $currentYear  = (int)date('Y');
    $currentMonth = (int)date('n');
    $endMonth = ($year == $currentYear) ? $currentMonth : 12;

    // Baseline for January's increment = December of the previous year, same cutoff day.
    $prevTotals = getHubMonthlyMTD($pdo, $year - 1, 12, $cutoffDay, $statusFilter);

    $rows = [];
    $sums = array_fill_keys(array_keys(HUBS), 0.0);
    $sumTotal = 0.0;
    $monthCount = 0;

    for ($m = 1; $m <= $endMonth; $m++) {
        $totals = getHubMonthlyMTD($pdo, $year, $m, $cutoffDay, $statusFilter);
        $grand = array_sum($totals);

        $hubRows = [];
        foreach (HUBS as $key => $meta) {
            $sales = $totals[$key];
            $pct = $grand > 0 ? ($sales / $grand * 100) : 0.0;
            $prevSales = $prevTotals[$key];
            $increment = $prevSales > 0 ? (($sales - $prevSales) / $prevSales * 100) : null;
            $hubRows[$key] = [
                'sales'     => round($sales, 2),
                'pct'       => round($pct, 2),
                'increment' => $increment === null ? null : round($increment, 2),
            ];
            $sums[$key] += $sales;
        }

        $prevGrand = array_sum($prevTotals);
        $totalIncrement = $prevGrand > 0 ? (($grand - $prevGrand) / $prevGrand * 100) : null;

        $rows[] = [
            'month'           => $m,
            'month_label'     => date('M', mktime(0, 0, 0, $m, 1)),
            'hubs'            => $hubRows,
            'total'           => round($grand, 2),
            'total_increment' => $totalIncrement === null ? null : round($totalIncrement, 2),
        ];

        $sumTotal += $grand;
        $monthCount++;
        $prevTotals = $totals;
    }

    $avgHubs = [];
    foreach (HUBS as $key => $meta) {
        $avgHubs[$key] = round($monthCount > 0 ? $sums[$key] / $monthCount : 0, 2);
    }

    return [
        'year'        => $year,
        'cutoff_day'  => $cutoffDay,
        'end_month'   => $endMonth,
        'rows'        => $rows,
        'average'     => ['hubs' => $avgHubs, 'total' => round($monthCount > 0 ? $sumTotal / $monthCount : 0, 2)],
    ];
}

$pdo = getDBConnection();

// ════════════════════════════════════════════════════
// AJAX ENDPOINT
// ════════════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $section      = $_GET['ajax'];
    $statusFilter = normalizeStatus($_GET['status_filter'] ?? 'all');

    if ($section === 'summary') {
        [$reportDate, $err] = clampReportDate($_GET['report_date'] ?? '');
        $data = getHubSummary($pdo, $reportDate, $statusFilter);
        $data['error'] = $err;
        echo json_encode($data);
        exit;
    }

    if ($section === 'increment') {
        [$reportDate, ] = clampReportDate($_GET['report_date'] ?? '');
        [$year, $err]    = clampYear($_GET['year'] ?? '');
        $cutoffDay = (int)date('d', strtotime($reportDate));
        $data = getHubIncrement($pdo, $year, $cutoffDay, $statusFilter);
        $data['error'] = $err;
        echo json_encode($data);
        exit;
    }

    echo json_encode(['error' => 'Invalid section']);
    exit;
}

// ════════════════════════════════════════════════════
// INITIAL PAGE LOAD — defaults only, rest happens via AJAX (URL never changes)
// ════════════════════════════════════════════════════
$todayYmd     = date('Y-m-d');
$currentYear  = (int)date('Y');
$statusFilter = 'all';
$reportDate   = $todayYmd;
$incrementYear = $currentYear;

$summaryData   = getHubSummary($pdo, $reportDate, $statusFilter);
$cutoffDay     = (int)date('d', strtotime($reportDate));
$incrementData = getHubIncrement($pdo, $incrementYear, $cutoffDay, $statusFilter);

$yearOptionsStart = $currentYear - 10;
$yearOptionsEnd   = $currentYear;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sales by Hub — S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="icon" href="../images/icon-sasia.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<style>
:root{
  --red:#E0202E;--red-dark:#8E1620;--red-darker:#3B0B0F;
  --teal:#00B4B4;--teal-dark:#008A8A;
  --ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;
  --gray-300:#D8D8DE;--gray-100:#F2F2F4;
  --bg:#F5F5F7;--white:#FFFFFF;
  --gold:#F5A623;--green:#10B981;
  --radius-lg:18px;--radius-md:12px;--radius-sm:8px;
  --sidebar-w:256px;--sidebar-w-collapsed:76px;--topbar-h:64px;
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
.main{margin-left:var(--sidebar-w);flex:1;padding:28px 32px 48px;min-width:0;transition:margin-left .25s ease;}
@media(max-width:900px){.main{margin-left:0;padding:20px;} body.sidebar-collapsed .main{margin-left:0;}}

/* ── PAGE HEADER ── */
.page-header{margin-bottom:24px;}
.page-header h1{font-size:24px;font-weight:800;margin-bottom:3px;}
.page-header p{font-size:13.5px;color:var(--gray-500);}

/* ── REPORT CARDS ── */
.report-card{background:var(--white);border-radius:var(--radius-lg);padding:24px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);margin-bottom:24px;}
.report-card-head{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
.report-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.report-icon svg{width:20px;height:20px;stroke:#fff;}
.ri-red{background:linear-gradient(135deg,var(--red),var(--red-dark));}
.ri-teal{background:linear-gradient(135deg,var(--teal),var(--teal-dark));}
.ri-gold{background:linear-gradient(135deg,var(--gold),#c97e0e);}
.ri-dark{background:linear-gradient(135deg,var(--gray-700),var(--ink));}
.report-card-title{font-size:16px;font-weight:800;}
.report-card-sub{font-size:12px;color:var(--gray-500);font-weight:500;margin-top:1px;}

/* ── GLOBAL FILTER BAR ── */
.global-filter-card{border:1.5px solid var(--ink);}
.global-filter-row{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;}
.global-filter-group{display:flex;flex-direction:column;gap:6px;min-width:170px;}
.global-filter-group label{font-size:11px;font-weight:700;color:var(--gray-700);text-transform:uppercase;letter-spacing:.3px;}
.global-filter-group select,.global-filter-group input{padding:10px 12px;border:1.5px solid var(--gray-300);border-radius:9px;font-size:13.5px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--ink);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;}
.global-filter-group select:focus,.global-filter-group input:focus{border-color:var(--ink);box-shadow:0 0 0 3px rgba(27,27,31,.1);}
.btn-apply-global{padding:11px 22px;background:var(--ink);color:#fff;border-radius:9px;font-size:13px;font-weight:800;display:inline-flex;align-items:center;gap:8px;transition:background .15s,opacity .15s;}
.btn-apply-global:hover{background:#000;}
.btn-apply-global:disabled{opacity:.6;cursor:not-allowed;}
.btn-apply-global svg{width:14px;height:14px;stroke:#fff;fill:none;}
.global-filter-hint{font-size:11.5px;color:var(--gray-500);margin-left:auto;align-self:center;max-width:280px;}
.filter-msg{font-size:12.5px;font-weight:600;padding:8px 12px;border-radius:8px;margin-bottom:14px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.filter-msg.hint-box{background:#eef2ff;color:#3730a3;border-color:#c7d2fe;}

/* ── 3.4 HUB PIE GRID ── */
.hub-pie-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
@media(max-width:1100px){.hub-pie-grid{grid-template-columns:1fr;}}
.hub-pie-panel{background:var(--gray-100);border-radius:var(--radius-md);padding:16px;}
.hub-pie-panel-title{font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-700);margin-bottom:2px;}
.hub-pie-panel-sub{font-size:11px;color:var(--gray-500);margin-bottom:12px;}
.hub-pie-wrap{position:relative;height:190px;margin-bottom:14px;}
.hub-pie-grand{text-align:center;font-size:13px;font-weight:800;margin-bottom:12px;}
.hub-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.hub-table th{text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--gray-500);padding:6px 6px;border-bottom:1.5px solid var(--gray-300);}
.hub-table td{padding:8px 6px;border-bottom:1px solid var(--gray-300);font-weight:600;}
.hub-table tr:last-child td{border-bottom:none;}
.hub-table td.num{text-align:right;font-variant-numeric:tabular-nums;}
.hub-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:7px;flex-shrink:0;}
.hub-name-cell{display:flex;align-items:center;}
.hub-table tfoot td{font-weight:800;border-top:1.5px solid var(--ink);border-bottom:none;padding-top:10px;}

/* ── 3.5 TARGET GRID ── */
.target-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;}
@media(max-width:1000px){.target-grid{grid-template-columns:1fr;}}
.target-panel{background:var(--gray-100);border-radius:var(--radius-md);padding:16px;}
.target-panel-title{font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-700);margin-bottom:12px;}
.target-chart-wrap{position:relative;height:210px;margin-bottom:14px;}
.target-table input.target-input{width:100%;padding:6px 8px;border:1.5px solid var(--gray-300);border-radius:7px;font-size:12.5px;font-family:'Plus Jakarta Sans',sans-serif;text-align:right;font-variant-numeric:tabular-nums;outline:none;}
.target-table input.target-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(245,166,35,.15);}
.diff-pos{color:var(--green);}
.diff-neg{color:var(--red);}
.target-note{font-size:11px;color:var(--gray-500);margin-top:10px;display:flex;align-items:center;gap:6px;}
.target-note svg{width:13px;height:13px;stroke:var(--gray-500);flex-shrink:0;}

/* ── 3.6 INCREMENT TABLE ── */
.increment-filter-row{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:18px;}
.increment-filter-group{display:flex;flex-direction:column;gap:6px;}
.increment-filter-group label{font-size:11px;font-weight:700;color:var(--gray-700);text-transform:uppercase;letter-spacing:.3px;}
.increment-filter-group select{padding:9px 12px;border:1.5px solid var(--gray-300);border-radius:9px;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;outline:none;}
.btn-apply-inc{padding:10px 18px;background:var(--gold);color:#fff;border-radius:9px;font-size:13px;font-weight:800;display:inline-flex;align-items:center;gap:7px;box-shadow:0 4px 14px rgba(245,166,35,.25);}
.btn-apply-inc:hover{background:#c97e0e;}
.btn-apply-inc:disabled{opacity:.6;cursor:not-allowed;}
.btn-apply-inc svg{width:14px;height:14px;stroke:#fff;fill:none;}
.increment-table-scroll{overflow-x:auto;}
.increment-table{width:100%;border-collapse:collapse;font-size:12px;min-width:920px;}
.increment-table th{text-align:right;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--gray-500);padding:8px 8px;border-bottom:1.5px solid var(--gray-300);white-space:nowrap;}
.increment-table th.hub-group{text-align:center;border-bottom:1px solid var(--gray-300);color:#fff;padding:6px 4px;font-size:10.5px;letter-spacing:.4px;}
.increment-table th:first-child,.increment-table td:first-child{text-align:left;}
.increment-table td{padding:8px 8px;border-bottom:1px solid var(--gray-300);text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.increment-table td:first-child{font-weight:800;}
.increment-table tfoot td{font-weight:800;border-top:1.5px solid var(--ink);background:var(--gray-100);}
.chart-wrap.is-loading, .hub-pie-grid.is-loading, .target-grid.is-loading, .increment-table-scroll.is-loading{opacity:.35;pointer-events:none;transition:opacity .15s;}

@media(max-width:600px){.main{padding:16px 14px 40px;} .global-filter-hint{margin-left:0;max-width:none;}}
</style>
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

<?php $pageTitle = 'Sales by Hub'; include __DIR__ . '/../includes/topnav.php'; ?>

<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="layout">
<main class="main">

  <div class="page-header">
    <h1>Sales by Hub</h1>
    <p>Comparison Sales, Target &amp; Increment across West Malaysia, East Malaysia, Brunei &amp; Singapore.</p>
  </div>

  <!-- ═══════════════ GLOBAL FILTER: STATUS + REPORTING DATE ═══════════════ -->
  <div class="report-card global-filter-card">
    <div class="report-card-head">
      <div class="report-icon ri-dark"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg></div>
      <div>
        <div class="report-card-title">Filter Status &amp; Reporting Date</div>
        <div class="report-card-sub">Applied to all sections below. Daily = the selected date, Monthly = MTD, Yearly = YTD (up to the selected date).</div>
      </div>
    </div>
    <div class="global-filter-row">
      <div class="global-filter-group">
        <label for="globalStatus">Status</label>
        <select id="globalStatus">
          <option value="all" selected>All</option>
          <option value="confirmed">Confirmed</option>
          <option value="void">Void</option>
        </select>
      </div>
      <div class="global-filter-group">
        <label for="globalReportDate">Reporting Date</label>
        <input type="date" id="globalReportDate" value="<?= htmlspecialchars($reportDate) ?>" max="<?= htmlspecialchars($todayYmd) ?>">
      </div>
      <button type="button" class="btn-apply-global" id="btnApplyGlobal">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Apply to All Charts
      </button>
      <span class="global-filter-hint">Sales Increment (3.6) uses the same day-of-month cut-off as this date, but has its own Year filter below.</span>
    </div>
  </div>

  <!-- ═══════════════ 3.4 COMPARISON SALES BY HUB ═══════════════ -->
  <div class="report-card" id="summaryCard">
    <div class="report-card-head">
      <div class="report-icon ri-red"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></div>
      <div>
        <div class="report-card-title" id="summaryTitle">Comparison Sales by Hub — <?= date('j F Y', strtotime($reportDate)) ?></div>
        <div class="report-card-sub">Daily Sales · Month-to-Date · Year-to-Date, broken down by hub</div>
      </div>
    </div>
    <div class="filter-msg" id="summaryErrorMsg" style="display:none;"></div>
    <div class="hub-pie-grid" id="hubPieGrid">
      <div class="hub-pie-panel">
        <div class="hub-pie-panel-title">Daily Sales</div>
        <div class="hub-pie-panel-sub" id="dailyPanelSub"></div>
        <div class="hub-pie-wrap"><canvas id="dailyPie"></canvas></div>
        <div class="hub-pie-grand" id="dailyGrand">RM 0.00</div>
        <table class="hub-table" id="dailyHubTable">
          <thead><tr><th>Region</th><th style="text-align:right">Total Sales</th><th style="text-align:right">%</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="hub-pie-panel">
        <div class="hub-pie-panel-title">Monthly Sales (MTD)</div>
        <div class="hub-pie-panel-sub" id="monthlyPanelSub"></div>
        <div class="hub-pie-wrap"><canvas id="monthlyPie"></canvas></div>
        <div class="hub-pie-grand" id="monthlyGrand">RM 0.00</div>
        <table class="hub-table" id="monthlyHubTable">
          <thead><tr><th>Region</th><th style="text-align:right">Total Sales</th><th style="text-align:right">%</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="hub-pie-panel">
        <div class="hub-pie-panel-title">Yearly Sales (YTD)</div>
        <div class="hub-pie-panel-sub" id="yearlyPanelSub"></div>
        <div class="hub-pie-wrap"><canvas id="yearlyPie"></canvas></div>
        <div class="hub-pie-grand" id="yearlyGrand">RM 0.00</div>
        <table class="hub-table" id="yearlyHubTable">
          <thead><tr><th>Region</th><th style="text-align:right">Total Sales</th><th style="text-align:right">%</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════ 3.5 SALES AND TARGET BY HUB ═══════════════ -->
  <div class="report-card card-gold" id="targetCard">
    <div class="report-card-head">
      <div class="report-icon ri-gold"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg></div>
      <div>
        <div class="report-card-title">Sales and Target by Hub</div>
        <div class="report-card-sub">Enter Target per hub to see the Different (%) — targets are <strong>not saved</strong>, they reset on page reload.</div>
      </div>
    </div>
    <div class="target-grid" id="targetGrid">
      <div class="target-panel">
        <div class="target-panel-title" id="targetDailyTitle">Daily Target</div>
        <div class="target-chart-wrap"><canvas id="dailyTargetChart"></canvas></div>
        <table class="hub-table target-table" id="dailyTargetTable">
          <thead><tr><th>Region</th><th style="text-align:right">Total Sales</th><th style="text-align:right">%</th><th style="text-align:right">Target</th><th style="text-align:right">Different</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="target-panel">
        <div class="target-panel-title" id="targetMonthlyTitle">Monthly Target</div>
        <div class="target-chart-wrap"><canvas id="monthlyTargetChart"></canvas></div>
        <table class="hub-table target-table" id="monthlyTargetTable">
          <thead><tr><th>Region</th><th style="text-align:right">Total Sales</th><th style="text-align:right">%</th><th style="text-align:right">Target</th><th style="text-align:right">Different</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    <div class="target-note">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      Different % = (Total Sales − Target) ÷ Target × 100. Type a Target value and it recalculates automatically.
    </div>
  </div>

  <!-- ═══════════════ 3.6 SALES INCREMENT BY HUB ═══════════════ -->
  <div class="report-card card-teal" id="incrementCard">
    <div class="report-card-head">
      <div class="report-icon ri-teal"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/></svg></div>
      <div>
        <div class="report-card-title">Sales Increment by Hub</div>
        <div class="report-card-sub" id="incrementSub">MTD sales per month (1st – cut-off day), month-on-month increment</div>
      </div>
    </div>

    <div class="filter-msg" id="incrementErrorMsg" style="display:none;"></div>

    <div class="increment-filter-row">
      <div class="increment-filter-group">
        <label for="incrementYear">Year</label>
        <select id="incrementYear">
          <?php for ($y = $yearOptionsEnd; $y >= $yearOptionsStart; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $incrementYear ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <button type="button" class="btn-apply-inc" id="btnApplyIncrement">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Apply
      </button>
    </div>

    <div class="increment-table-scroll" id="incrementTableWrap">
      <table class="increment-table" id="incrementTable">
        <thead>
          <tr id="incrementHubHeadRow"></tr>
          <tr id="incrementSubHeadRow"><th>Month</th></tr>
        </thead>
        <tbody id="incrementTableBody"></tbody>
        <tfoot><tr id="incrementAverageRow"></tr></tfoot>
      </table>
    </div>
  </div>

</main>
</div><!-- layout -->

<script>
function openDrawer(){
    const d = document.getElementById('sidebarDrawer');
    const o = document.getElementById('drawerOverlay');
    if (!d || !o) return;
    d.classList.add('open');
    o.style.display = 'block';
    requestAnimationFrame(() => o.classList.add('open'));
    document.body.style.overflow = 'hidden';
}
function closeDrawer(){
    const d = document.getElementById('sidebarDrawer');
    const o = document.getElementById('drawerOverlay');
    if (!d || !o) return;
    d.classList.remove('open');
    o.classList.remove('open');
    setTimeout(() => { o.style.display = 'none'; }, 260);
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeDrawer(); });
function toggleSidebarOnDesktop(){
    if (window.innerWidth >= 900) {
        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        try {
            if (collapsed) localStorage.setItem('adminSidebarCollapsed', '1');
            else localStorage.removeItem('adminSidebarCollapsed');
        } catch (e) {}
    } else {
        openDrawer();
    }
}

// ════════════════════════════════════════════════════
// HUB META (mirrors PHP HUBS constant)
// ════════════════════════════════════════════════════
const HUBS = {
    west:      { label: 'West Malaysia', color: '#2563EB', hover: '#1D4ED8' },
    east:      { label: 'East Malaysia',  color: '#00B4B4', hover: '#008A8A' },
    brunei:    { label: 'Brunei',         color: '#E0202E', hover: '#8E1620' },
    singapore: { label: 'Singapore',      color: '#F5A623', hover: '#c97e0e' },
};
const HUB_KEYS = Object.keys(HUBS);

function formatRM(n){
    return 'RM ' + Number(n || 0).toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function formatPct(n, withSign){
    if (n === null || n === undefined) return '—';
    const v = Number(n);
    const sign = withSign && v > 0 ? '+' : '';
    return sign + v.toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2}) + '%';
}

if (typeof ChartDataLabels !== 'undefined' && typeof Chart !== 'undefined') {
    Chart.register(ChartDataLabels);
}

// ════════════════════════════════════════════════════
// 3.4 — PIE CHARTS + TABLES
// ════════════════════════════════════════════════════
const pieInstances = {};

function renderHubPie(canvasId, hubsPayload){
    if (typeof Chart === 'undefined') return;
    const labels = HUB_KEYS.map(k => HUBS[k].label);
    const values = HUB_KEYS.map(k => hubsPayload[k] ? hubsPayload[k].total : 0);
    const colors = HUB_KEYS.map(k => HUBS[k].color);

    if (pieInstances[canvasId]) pieInstances[canvasId].destroy();

    const ctx = document.getElementById(canvasId).getContext('2d');
    pieInstances[canvasId] = new Chart(ctx, {
        type: 'pie',
        data: { labels, datasets: [{ data: values, backgroundColor: colors, borderColor: '#fff', borderWidth: 2 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 9, boxHeight: 9, font: { family:"'Plus Jakarta Sans'", weight:'600', size: 11 }, padding: 10 } },
                tooltip: {
                    backgroundColor:'#1B1B1F', padding:10, cornerRadius:8,
                    callbacks: { label: (ctx) => `${ctx.label}: ${formatRM(ctx.parsed)}` }
                },
                datalabels: {
                    color: '#fff', font: { family:"'Plus Jakarta Sans'", weight:'700', size: 11 },
                    formatter: (value, ctx) => {
                        const total = ctx.chart.data.datasets[0].data.reduce((a,b)=>a+Number(b),0);
                        if (total <= 0 || value <= 0) return '';
                        const pct = value / total * 100;
                        return pct < 4 ? '' : pct.toFixed(1) + '%';
                    }
                }
            }
        },
        plugins: (typeof ChartDataLabels !== 'undefined') ? [ChartDataLabels] : []
    });
}

function renderHubTable(tableId, hubsPayload){
    const tbody = document.querySelector('#' + tableId + ' tbody');
    tbody.innerHTML = '';
    HUB_KEYS.forEach(k => {
        const meta = HUBS[k];
        const data = hubsPayload[k] || { total: 0, pct: 0 };
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><span class="hub-name-cell"><span class="hub-dot" style="background:${meta.color}"></span>${meta.label}</span></td>
            <td class="num">${formatRM(data.total)}</td>
            <td class="num">${formatPct(data.pct, false)}</td>`;
        tbody.appendChild(tr);
    });
}

function applySummaryResult(json){
    const errDiv = document.getElementById('summaryErrorMsg');
    if (json.error) { errDiv.textContent = json.error; errDiv.style.display = 'block'; }
    else { errDiv.style.display = 'none'; }

    const dt = new Date(json.report_date + 'T00:00:00');
    const dateLabel = dt.toLocaleDateString('en-GB', { day:'numeric', month:'long', year:'numeric' });
    document.getElementById('summaryTitle').textContent = 'Comparison Sales by Hub — ' + dateLabel;
    document.getElementById('dailyPanelSub').textContent = dateLabel + ' only';
    document.getElementById('monthlyPanelSub').textContent = '1st – ' + dateLabel.split(' ')[0] + ' ' + dateLabel.split(' ').slice(1).join(' ');
    document.getElementById('yearlyPanelSub').textContent = 'Jan 1 – ' + dateLabel;

    ['daily','monthly','yearly'].forEach(period => {
        const payload = json[period];
        document.getElementById(period + 'Grand').textContent = formatRM(payload.grand);
        renderHubPie(period + 'Pie', payload.hubs);
        renderHubTable(period + 'HubTable', payload.hubs);
    });

    // feed section 3.5 with the same Daily / Monthly actuals
    renderTargetSection('daily', json.daily, dateLabel);
    renderTargetSection('monthly', json.monthly, dateLabel);
}

// ════════════════════════════════════════════════════
// 3.5 — TARGET (client-side only, NOT persisted to DB)
// ════════════════════════════════════════════════════
const targetState = { daily: {}, monthly: {} }; // hub -> target value entered by admin
const targetInstances = {};
let latestActuals = { daily: {}, monthly: {} };

function computeDifferent(actual, target){
    if (!target || target <= 0) return null;
    return (actual - target) / target * 100;
}

function renderTargetSection(period, payload, dateLabel){
    latestActuals[period] = payload.hubs;
    if (period === 'daily') document.getElementById('targetDailyTitle').textContent = 'Daily Target — ' + dateLabel;
    if (period === 'monthly') document.getElementById('targetMonthlyTitle').textContent = 'Monthly Target — MTD as of ' + dateLabel;
    renderTargetTable(period);
    renderTargetChart(period);
}

function renderTargetTable(period){
    const tbody = document.querySelector('#' + period + 'TargetTable tbody');
    tbody.innerHTML = '';
    HUB_KEYS.forEach(k => {
        const meta = HUBS[k];
        const actual = (latestActuals[period][k] || { total: 0, pct: 0 });
        const target = targetState[period][k] ?? '';
        const diff = computeDifferent(actual.total, Number(target));
        const diffClass = diff === null ? '' : (diff >= 0 ? 'diff-pos' : 'diff-neg');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><span class="hub-name-cell"><span class="hub-dot" style="background:${meta.color}"></span>${meta.label}</span></td>
            <td class="num">${formatRM(actual.total)}</td>
            <td class="num">${formatPct(actual.pct, false)}</td>
            <td class="num"><input type="number" min="0" step="0.01" class="target-input" data-period="${period}" data-hub="${k}" value="${target}" placeholder="0.00"></td>
            <td class="num ${diffClass}">${diff === null ? '—' : formatPct(diff, true)}</td>`;
        tbody.appendChild(tr);
    });
}

function renderTargetChart(period){
    if (typeof Chart === 'undefined') return;
    const canvasId = period + 'TargetChart';
    const labels = HUB_KEYS.map(k => HUBS[k].label);
    const actualValues = HUB_KEYS.map(k => (latestActuals[period][k] || {}).total || 0);
    const targetValues = HUB_KEYS.map(k => Number(targetState[period][k]) || 0);

    if (targetInstances[canvasId]) targetInstances[canvasId].destroy();
    const ctx = document.getElementById(canvasId).getContext('2d');
    targetInstances[canvasId] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Target', data: targetValues, backgroundColor: '#8A8A93', borderRadius: 5, maxBarThickness: 30 },
                { label: 'Total Sales', data: actualValues, backgroundColor: '#F5A623', borderRadius: 5, maxBarThickness: 30 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 10, boxHeight: 10, font: { family:"'Plus Jakarta Sans'", weight:'600', size: 11 } } },
                tooltip: { backgroundColor:'#1B1B1F', padding:10, cornerRadius:8, callbacks: { label:(ctx)=> `${ctx.dataset.label}: ${formatRM(ctx.parsed.y)}` } },
                datalabels: { display: false }
            },
            scales: {
                x: { grid: { display:false }, ticks: { font: { family:"'Plus Jakarta Sans'", weight:'600', size: 10 } } },
                y: { beginAtZero:true, grid: { color:'#F2F2F4' }, ticks: { font:{family:"'Plus Jakarta Sans'"}, callback:(v)=> 'RM ' + v.toLocaleString('en-MY') } }
            }
        }
    });
}

document.addEventListener('input', function(e){
    if (!e.target.classList.contains('target-input')) return;
    const period = e.target.dataset.period;
    const hub = e.target.dataset.hub;
    const val = e.target.value === '' ? null : Number(e.target.value);
    targetState[period][hub] = val;
    renderTargetTable(period);
    renderTargetChart(period);
});

// ════════════════════════════════════════════════════
// 3.6 — INCREMENT TABLE
// ════════════════════════════════════════════════════
function buildIncrementHead(){
    const hubHead = document.getElementById('incrementHubHeadRow');
    const subHead = document.getElementById('incrementSubHeadRow');
    hubHead.innerHTML = '<th></th>';
    HUB_KEYS.forEach(k => {
        const th = document.createElement('th');
        th.className = 'hub-group';
        th.colSpan = 3;
        th.style.background = HUBS[k].color;
        th.textContent = HUBS[k].label;
        hubHead.appendChild(th);
    });
    const totalTh = document.createElement('th');
    totalTh.className = 'hub-group';
    totalTh.colSpan = 2;
    totalTh.style.background = '#1B1B1F';
    totalTh.textContent = 'Total Sales';
    hubHead.appendChild(totalTh);

    subHead.innerHTML = '<th>Month</th>';
    HUB_KEYS.forEach(() => {
        subHead.innerHTML += '<th>Sales</th><th>Sales %</th><th>Increment %</th>';
    });
    subHead.innerHTML += '<th>Total</th><th>Increment %</th>';
}

function incrementCellClass(v){
    if (v === null || v === undefined) return '';
    return v >= 0 ? 'diff-pos' : 'diff-neg';
}

function applyIncrementResult(json){
    const errDiv = document.getElementById('incrementErrorMsg');
    if (json.error) { errDiv.textContent = json.error; errDiv.style.display = 'block'; }
    else { errDiv.style.display = 'none'; }

    document.getElementById('incrementSub').textContent =
        'MTD sales per month (1st – day ' + json.cutoff_day + '), month-on-month increment · ' + json.year;

    const tbody = document.getElementById('incrementTableBody');
    tbody.innerHTML = '';
    json.rows.forEach(row => {
        const tr = document.createElement('tr');
        let html = `<td>${row.month_label} ${json.year}</td>`;
        HUB_KEYS.forEach(k => {
            const h = row.hubs[k];
            html += `<td>${formatRM(h.sales)}</td><td>${formatPct(h.pct,false)}</td><td class="${incrementCellClass(h.increment)}">${formatPct(h.increment,true)}</td>`;
        });
        html += `<td>${formatRM(row.total)}</td><td class="${incrementCellClass(row.total_increment)}">${formatPct(row.total_increment,true)}</td>`;
        tr.innerHTML = html;
        tbody.appendChild(tr);
    });

    const avgRow = document.getElementById('incrementAverageRow');
    let avgHtml = `<td>Average</td>`;
    HUB_KEYS.forEach(k => {
        avgHtml += `<td>${formatRM(json.average.hubs[k])}</td><td>—</td><td>—</td>`;
    });
    avgHtml += `<td>${formatRM(json.average.total)}</td><td>—</td>`;
    avgRow.innerHTML = avgHtml;
}

// ════════════════════════════════════════════════════
// AJAX FETCHING — URL never changes
// ════════════════════════════════════════════════════
const AJAX_URL = window.location.pathname;

function setLoading(el, isLoading){
    if (el) el.classList.toggle('is-loading', isLoading);
}

async function fetchSummary(){
    const status = document.getElementById('globalStatus').value;
    const reportDate = document.getElementById('globalReportDate').value;
    const params = new URLSearchParams({ ajax:'summary', status_filter: status, report_date: reportDate });

    setLoading(document.getElementById('hubPieGrid'), true);
    setLoading(document.getElementById('targetGrid'), true);
    document.getElementById('btnApplyGlobal').disabled = true;
    try {
        const res = await fetch(AJAX_URL + '?' + params.toString(), { headers: { 'X-Requested-With':'XMLHttpRequest' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        applySummaryResult(await res.json());
    } catch (err) {
        const e = document.getElementById('summaryErrorMsg');
        e.textContent = 'Failed to load data. Please try again.';
        e.style.display = 'block';
    } finally {
        setLoading(document.getElementById('hubPieGrid'), false);
        setLoading(document.getElementById('targetGrid'), false);
        document.getElementById('btnApplyGlobal').disabled = false;
    }
}

async function fetchIncrement(){
    const status = document.getElementById('globalStatus').value;
    const reportDate = document.getElementById('globalReportDate').value;
    const year = document.getElementById('incrementYear').value;
    const params = new URLSearchParams({ ajax:'increment', status_filter: status, report_date: reportDate, year });

    setLoading(document.getElementById('incrementTableWrap'), true);
    document.getElementById('btnApplyIncrement').disabled = true;
    try {
        const res = await fetch(AJAX_URL + '?' + params.toString(), { headers: { 'X-Requested-With':'XMLHttpRequest' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        applyIncrementResult(await res.json());
    } catch (err) {
        const e = document.getElementById('incrementErrorMsg');
        e.textContent = 'Failed to load data. Please try again.';
        e.style.display = 'block';
    } finally {
        setLoading(document.getElementById('incrementTableWrap'), false);
        document.getElementById('btnApplyIncrement').disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', function(){
    buildIncrementHead();

    // Initial render from PHP-provided data
    applySummaryResult(<?= json_encode($summaryData) ?>);
    applyIncrementResult(<?= json_encode($incrementData) ?>);

    document.getElementById('btnApplyGlobal').addEventListener('click', function(){
        fetchSummary();
        fetchIncrement(); // status + report_date (cutoff day) are shared with 3.6 too
    });

    document.getElementById('btnApplyIncrement').addEventListener('click', fetchIncrement);
});
</script>
</body>
</html>