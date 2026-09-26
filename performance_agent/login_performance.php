<?php
/**
 * Agent Login Activity — login_performance.php
 *
 * Monitor system login activity by hour for a selected date range + region.
 * Follows the same conventions as dashboard.php (session/auth, getDBConnection(),
 * design tokens, topnav/sidebar includes).
 *
 * ── ASSUMPTIONS (sesuaikan ikut schema sebenar kalau lain) ──
 * 1. Region filter guna `members.company_code` (cth: 'MY', 'SG'), sama concept
 *    macam `companies.company_code` yang digunakan dalam dashboard.php.
 *    Kalau region sebenarnya kena join `members` -> `companies`, tukar je
 *    JOIN line dalam loginPerfBaseJoin() kat bawah.
 * 2. "Unique agent login" ikut footnote UI: kalau agent yang sama login
 *    berkali-kali dalam hour + hari yang sama, kira sebagai 1. Kalau dia login
 *    di hour lain (hari sama) atau hari lain (hour sama), setiap satu dikira
 *    berasingan. Jadi semua agregat hour di bawah ni based on
 *    DISTINCT (member_code, DATE(login_time), HOUR(login_time)).
 *
 * ── PERFORMANCE NOTES (2026 update) ──
 * - The "Hourly Login Activity" range query and the "Top 5 Hours" single-day
 *   query used to run as two separate DB round trips. The single-day query
 *   filtered with `DATE(la.login_time) = :d`, which is NOT sargable (a
 *   function wraps the indexed column) so it could not use an index on
 *   login_time and forced a broader scan every time the filter changed.
 *   Both are now produced from ONE query (getLoginPerfHourlyAndTop) that
 *   scans the date range once using the sargable `login_time >= :from AND
 *   login_time < :to` predicate, then both the 24-hour totals and the
 *   top-5-for-a-single-day figures are derived from the same result set in
 *   PHP. This cuts the report down to 2 DB round trips total (was 3) and
 *   removes the non-sargable predicate entirely.
 * - For this to be fast on a large login_agents table, add (if not present):
 *     CREATE INDEX idx_login_agents_time_member ON login_agents (login_time, member_code);
 *   This lets the range scan + GROUP BY use the index instead of a full
 *   table scan, and covers member_code so the grouping doesn't need to hit
 *   the row itself.
 * - The filter form now submits over AJAX (see the <script> near the bottom).
 *   The page never fully reloads and the address bar/URL never changes when
 *   you click "Generate Report" — only the KPI cards, chart, top hours and
 *   breakdown table are refreshed in place.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

// Detect an AJAX report request (either header or ?ajax=1 as a fallback).
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

function loginPerfLogError(Throwable $error, $context) {
    $message = sprintf(
        "[%s] [login_performance] %s: %s\n%s\n\n",
        date('Y-m-d H:i:s'),
        $context,
        $error->getMessage(),
        $error->getTraceAsString()
    );
    error_log($message, 3, __DIR__ . '/../error.log');
}

function getDBConnection() {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
        return new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (Throwable $e) {
        loginPerfLogError($e, 'Database connection failed');
        return null;
    }
}

// ── Auth guard (same idle-timeout convention as dashboard.php) ──
$isLoggedIn = isset($_SESSION['admin_id']);
if (!$isLoggedIn) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'unauthenticated', 'redirect' => '../index.php']);
        exit;
    }
    header('Location: ../index.php');
    exit;
}
$adminUsername = $_SESSION['admin_username'] ?? '';
$idleLimit = 7200;
if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleLimit) {
    session_unset();
    session_destroy();
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'expired', 'redirect' => '../index.php?expired=1']);
        exit;
    }
    header('Location: ../index.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

// ══════════════════════ Data layer ══════════════════════

// Base FROM/JOIN clause reused by every query below.
function loginPerfBaseJoin() {
    return 'FROM login_agents la
            INNER JOIN members m ON m.member_code = la.member_code
            INNER JOIN companies c ON c.id = m.company_id';
}

function loginPerfRegionFilter($region) {
    if ($region === '' || $region === 'ALL') return '';
    return ' AND c.company_code = :region';
}

function loginPerfBindRegion(&$params, $region) {
    if ($region !== '' && $region !== 'ALL') $params['region'] = $region;
}

/**
 * Single pass over the date range that produces BOTH:
 *  - hourly: 24-slot array of totals across the whole range (the main chart)
 *  - top: the top N hours (by unique agents) for one specific reference date
 *         (the "Top 5 Hours" panel), derived from the same rows instead of a
 *         second, non-sargable query.
 * Each unit counted = one (member_code, date, hour) combo — i.e. an agent
 * that logged in one or more times within that hour, on that day.
 */
function getLoginPerfHourlyAndTop(PDO $pdo, $from, $to, $region, $topDate, $limit = 5) {
    $join = loginPerfBaseJoin();
    $regionSql = loginPerfRegionFilter($region);
    $sql = "SELECT x.d AS d, x.hr AS hr, COUNT(*) AS total FROM (
                SELECT la.member_code, DATE(la.login_time) AS d, HOUR(la.login_time) AS hr
                {$join}
                WHERE la.login_time >= :from AND la.login_time < :to {$regionSql}
                GROUP BY la.member_code, DATE(la.login_time), HOUR(la.login_time)
            ) x
            GROUP BY x.d, x.hr";
    $stmt = $pdo->prepare($sql);
    $params = ['from' => $from . ' 00:00:00', 'to' => date('Y-m-d 00:00:00', strtotime($to . ' +1 day'))];
    loginPerfBindRegion($params, $region);
    $stmt->execute($params);

    $hourly = array_fill(0, 24, 0);
    $topRows = [];
    while ($row = $stmt->fetch()) {
        $hr = (int)$row['hr'];
        $total = (int)$row['total'];
        $hourly[$hr] += $total;
        if ($row['d'] === $topDate) {
            $topRows[] = ['hr' => $hr, 'total' => $total];
        }
    }

    usort($topRows, function ($a, $b) {
        return ($b['total'] <=> $a['total']) ?: ($a['hr'] <=> $b['hr']);
    });
    $topRows = array_slice($topRows, 0, $limit);

    return [$hourly, $topRows];
}

/** Distinct agents that logged in anywhere in the range (the "Total Agent Login" KPI). */
function getLoginPerfTotalUniqueAgents(PDO $pdo, $from, $to, $region) {
    $join = loginPerfBaseJoin();
    $regionSql = loginPerfRegionFilter($region);
    $sql = "SELECT COUNT(DISTINCT la.member_code) {$join}
            WHERE la.login_time >= :from AND la.login_time < :to {$regionSql}";
    $stmt = $pdo->prepare($sql);
    $params = ['from' => $from . ' 00:00:00', 'to' => date('Y-m-d 00:00:00', strtotime($to . ' +1 day'))];
    loginPerfBindRegion($params, $region);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function getLoginPerfData(PDO $pdo, $from, $to, $region) {
    // Only 2 DB round trips now (was 3): one combined range+top-hours query,
    // and one distinct-agent-count query.
    [$hourly, $topHours] = getLoginPerfHourlyAndTop($pdo, $from, $to, $region, $to, 5);
    $totalUniqueAgents = getLoginPerfTotalUniqueAgents($pdo, $from, $to, $region);

    $hourlySum = array_sum($hourly);
    $daysSelected = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    $avgPerHour = round($hourlySum / 24, 1);
    $activeHours = count(array_filter($hourly, fn($v) => $v > 0));

    $peakHour = 0; $peakValue = -1;
    foreach ($hourly as $hr => $val) { if ($val > $peakValue) { $peakValue = $val; $peakHour = $hr; } }

    $breakdown = [];
    foreach ($hourly as $hr => $total) {
        $breakdown[$hr] = [
            'hour' => $hr,
            'total' => $total,
            'avg_per_day' => $daysSelected > 0 ? round($total / $daysSelected, 1) : 0,
            'contribution' => $totalUniqueAgents > 0 ? round(($total / $totalUniqueAgents) * 100, 1) : 0,
        ];
    }

    return [
        'from' => $from, 'to' => $to, 'region' => $region,
        'days_selected' => $daysSelected,
        'total_unique_agents' => $totalUniqueAgents,
        'avg_per_hour' => $avgPerHour,
        'peak_hour' => $peakHour, 'peak_value' => max(0, $peakValue),
        'active_hours' => $activeHours,
        'hourly' => $hourly,
        'top_hours' => $topHours,
        'breakdown' => $breakdown,
    ];
}

function loginPerfHourLabel($hr) {
    $h1 = str_pad((string)$hr, 2, '0', STR_PAD_LEFT);
    $h2 = str_pad((string)(($hr + 1) % 24), 2, '0', STR_PAD_LEFT);
    return "{$h1}:00 – {$h2}:00";
}

// ── Read filters ──
$today = date('Y-m-d');
$defaultTo = date('Y-m-d', strtotime('-1 day'));
$defaultFrom = date('Y-m-d', strtotime($defaultTo . ' -21 days'));

$fromDate = $_GET['from_date'] ?? $defaultFrom;
$toDate = $_GET['to_date'] ?? $defaultTo;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) $toDate = $defaultTo;
if ($toDate > $today) $toDate = $today;
if ($fromDate > $toDate) $fromDate = $toDate;

$region = $_GET['region'] ?? 'MY';
$allowedRegions = ['ALL' => 'All Regions', 'MY' => 'Malaysia', 'SG' => 'Singapore'];
if (!isset($allowedRegions[$region])) $region = 'MY';

$pdo = getDBConnection();
$dbActive = $pdo instanceof PDO;
$dbError = !$dbActive;
$d = [
        'from' => $fromDate, 'to' => $toDate, 'region' => $region, 'days_selected' => 0,
        'total_unique_agents' => 0, 'avg_per_hour' => 0, 'peak_hour' => 0, 'peak_value' => 0,
        'active_hours' => 0, 'hourly' => array_fill(0, 24, 0), 'top_hours' => [], 'breakdown' => [],
    ];
if ($dbActive) {
    try {
        $d = getLoginPerfData($pdo, $fromDate, $toDate, $region);
    } catch (Throwable $e) {
        loginPerfLogError($e, 'Report query failed');
        $dbError = true;
    }
}

$maxHourly = max(1, max($d['hourly']));

// ══════════════════════ AJAX response (no page markup at all) ══════════════════════
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'dbError' => $dbError,
        'from' => $d['from'],
        'to' => $d['to'],
        'region' => $d['region'],
        'days_selected' => $d['days_selected'],
        'total_unique_agents' => $d['total_unique_agents'],
        'avg_per_hour' => $d['avg_per_hour'],
        'peak_hour' => $d['peak_hour'],
        'peak_value' => $d['peak_value'],
        'active_hours' => $d['active_hours'],
        'hourly' => array_values($d['hourly']),
        'top_hours' => array_values($d['top_hours']),
        'breakdown' => array_values($d['breakdown']),
        'max_hourly' => $maxHourly,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Agent Login Activity — S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="icon" href="../images/icon-sasia.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --red:#E0202E;--red-dark:#8E1620;--red-darker:#3B0B0F;
  --ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;
  --gray-300:#D8D8DE;--gray-100:#F2F2F4;
  --bg:#F5F5F7;--white:#FFFFFF;
  --radius-lg:18px;--radius-md:12px;--radius-sm:8px;
    --shadow-card:0 8px 24px rgba(30,30,40,.06);
    --sidebar-w:260px;--sidebar-w-collapsed:82px;--topbar-h:64px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;}
a{text-decoration:none;color:inherit;}
button,input,select{font:inherit;}
svg{display:block;}
.layout{display:flex;margin-top:var(--topbar-h);}
.main{min-width:0;flex:1;margin-left:var(--sidebar-w);padding:28px 32px 48px;transition:margin-left .25s ease;}
body.sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed);}
.page-header{margin-bottom:24px;}
.page-header h1{margin-bottom:4px;font-size:1.5rem;font-weight:800;}
.page-header p{color:var(--gray-500);font-size:.875rem;}

.card{margin-bottom:24px;padding:24px;border:1px solid var(--gray-100);border-radius:var(--radius-lg);background:var(--white);box-shadow:var(--shadow-card);}
.card-title{margin-bottom:4px;font-size:1rem;font-weight:800;}
.card-subtitle{margin-bottom:10px;color:var(--gray-500);font-size:.75rem;line-height:1.5;}
.report-filter{margin-top:20px;}
.period-box{padding:18px;border:1px solid var(--gray-100);border-radius:var(--radius-md);background:var(--gray-100);}
.period-title{margin-bottom:14px;font-size:.875rem;font-weight:800;}
.date-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
.field{display:flex;min-width:0;flex-direction:column;gap:6px;}
.field label{color:var(--gray-700);font-size:.6875rem;font-weight:800;letter-spacing:.35px;text-transform:uppercase;}
.field input,.field select{width:100%;min-width:0;padding:10px 11px;border:1.5px solid var(--gray-300);border-radius:9px;outline:none;background:var(--white);color:var(--ink);font-size:.8125rem;}
.field input:focus,.field select:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.10);}
.filter-footer{display:flex;align-items:center;justify-content:flex-end;gap:16px;margin-top:18px;}
.apply-button{min-width:180px;min-height:42px;padding:10px 20px;border:0;border-radius:9px;background:var(--red);box-shadow:0 4px 14px rgba(224,32,46,.22);color:var(--white);cursor:pointer;font-size:.8125rem;font-weight:800;transition:opacity .15s ease;}
.apply-button:hover{background:var(--red-dark);}
.apply-button:disabled{opacity:.7;cursor:progress;}
.error-box{margin-bottom:20px;padding:13px 15px;border:1px solid #FECACA;border-radius:10px;background:#fff0f0;color:#991B1B;font-size:.8125rem;font-weight:600;}

.agent-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px;}
.agent-kpi-card{min-width:0;padding:18px 20px;border:1px solid var(--gray-100);border-radius:16px;background:var(--white);box-shadow:var(--shadow-card);}
.agent-kpi-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;}
.agent-kpi-label{color:var(--gray-700);font-size:.75rem;font-weight:700;}
.agent-kpi-icon{display:inline-flex;flex:0 0 38px;width:38px;height:38px;align-items:center;justify-content:center;border-radius:10px;font-size:18px;}
.agent-kpi-icon-login{background:#FCE8EB;}
.agent-kpi-icon-hour{background:#E8F3FA;}
.agent-kpi-icon-peak{background:#FFF3E3;}
.agent-kpi-icon-active{background:#E8F5EF;}
.agent-kpi-value{color:var(--ink);font-size:1.5rem;font-weight:800;line-height:1.3;font-variant-numeric:tabular-nums;overflow-wrap:anywhere;}
.agent-kpi-note{margin-top:6px;color:var(--gray-500);font-size:.6875rem;line-height:1.5;}

/* ── Login activity charts ── */
.lp-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;}
.lp-grid .card{min-width:0;}
.lp-grid .card-title,.breakdown-card .card-title{margin-bottom:14px;}

/* ── Hourly bar chart (CSS bars) ── */
.lp-chart-wrap{display:flex;gap:10px;}
.lp-yaxis{display:flex;flex-direction:column;justify-content:space-between;font-size:0.625rem;color:var(--gray-500);font-weight:700;padding:4px 0 26px;text-align:right;height:230px;}
.lp-bars{flex:1;display:grid;grid-template-columns:repeat(24,1fr);align-items:end;height:230px;border-left:1px solid var(--gray-100);border-bottom:1px solid var(--gray-100);position:relative;background-image:repeating-linear-gradient(to top,var(--gray-100) 0,var(--gray-100) 1px,transparent 1px,transparent 20%);}
.lp-bar-col{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;position:relative;}
.lp-bar-val{font-size:0.5625rem;font-weight:800;color:var(--ink);margin-bottom:3px;white-space:nowrap;}
.lp-bar{width:60%;border-radius:3px 3px 0 0;background:linear-gradient(180deg,#ef3b47,var(--red));}
.lp-bar.peak{background:linear-gradient(180deg,#ff5a63,var(--red-dark));}
.lp-xaxis{display:grid;grid-template-columns:repeat(24,1fr);margin-left:calc(56px + 10px);margin-top:4px;}
.lp-xaxis span{font-size:0.5625rem;color:var(--gray-500);font-weight:700;text-align:center;}

/* ── Top 5 hours ── */
.lp-top-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.lp-top-rank{width:22px;height:22px;border-radius:50%;background:var(--gray-100);color:var(--gray-700);font-size:0.6875rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex:none;}
.lp-top-rank.gold{background:#f5a623;color:#fff;}
.lp-top-label{font-size:0.6875rem;font-weight:700;color:var(--gray-700);width:96px;flex:none;}
.lp-top-bar-track{flex:1;height:22px;background:var(--gray-100);border-radius:6px;overflow:hidden;}
.lp-top-bar-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,#f28b93,var(--red));}
.lp-top-val{font-size:0.75rem;font-weight:800;color:var(--ink);width:44px;text-align:right;flex:none;}

/* ── Breakdown table ── */
.lp-breakdown{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.lp-breakdown-table{width:100%;border-collapse:collapse;font-size:0.75rem;}
.lp-breakdown-table th{background:#F7F7F9;color:var(--gray-700);text-align:left;font-size:0.6875rem;padding:9px 10px;font-weight:800;}
.lp-breakdown-table th:not(:first-child),.lp-breakdown-table td:not(:first-child){text-align:right;}
.lp-breakdown-table td{padding:8px 10px;border-bottom:1px solid #ECECF0;font-weight:600;color:var(--ink);}
.lp-note{margin-top:16px;background:var(--gray-100);border-radius:var(--radius-md);padding:12px 16px;font-size:0.75rem;color:var(--gray-700);line-height:1.6;display:flex;gap:10px;}
.lp-note svg{width:16px;height:16px;stroke:var(--red);fill:none;stroke-width:2;flex:none;margin-top:2px;}

@media(max-width:1100px){.agent-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:1000px){.lp-grid{grid-template-columns:1fr;}.lp-breakdown{grid-template-columns:1fr;}}
@media(max-width:900px){.main,body.sidebar-collapsed .main{margin-left:0;padding:20px;}}
@media(max-width:800px){.filter-footer{flex-direction:column;align-items:stretch;}.apply-button{width:100%;}}
@media(max-width:600px){.agent-kpi-grid{grid-template-columns:1fr;}.date-grid{grid-template-columns:1fr;}.card{padding:18px;}}
</style>
</head>
<body>

<?php
$pageTitle = 'Agent Login Activity';
$activeNav = 'agentperformance';
$navBasePath = '../';
$adminUsername = $_SESSION['admin_username'] ?? '';
include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="layout">
<main class="main">

    <div class="error-box" id="lp-error-box" role="alert" style="<?= $dbError ? '' : 'display:none;' ?>">
        Login activity data could not be loaded. The error details were saved to error.log.
    </div>

    <header class="page-header">
        <h1>Agent Login Activity</h1>
        <p>Monitor system login activity by hour for the selected date range and region.</p>
    </header>

    <section class="card">
        <h2 class="card-title">Report Filters</h2>
        <p class="card-subtitle">Select a reporting period and region for agent login activity.</p>
        <form method="get" class="report-filter" id="lp-filter-form">
            <div class="period-box">
                <div class="period-title">Reporting Period</div>
                <div class="date-grid">
                    <div class="field">
                        <label for="from_date">From Date</label>
                        <input type="date" id="from_date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" max="<?= htmlspecialchars($today) ?>">
                    </div>
                    <div class="field">
                        <label for="to_date">To Date</label>
                        <input type="date" id="to_date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" max="<?= htmlspecialchars($today) ?>">
                    </div>
                    <div class="field">
                        <label for="region">Region</label>
                        <select id="region" name="region">
                    <?php foreach ($allowedRegions as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $region === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="filter-footer">
                <button type="submit" class="apply-button" id="lp-apply-btn">Generate Report</button>
            </div>
        </form>
    </section>

    <section class="agent-kpi-grid">
        <article class="agent-kpi-card">
            <div class="agent-kpi-header"><h2 class="agent-kpi-label">Total Agent Login</h2><span class="agent-kpi-icon agent-kpi-icon-login" aria-hidden="true">&#128101;</span></div>
            <div class="agent-kpi-value" id="kpi-total-agents"><?= number_format($d['total_unique_agents']) ?></div>
            <p class="agent-kpi-note" id="kpi-total-note">Unique agents logged in from <?= htmlspecialchars(date('d M', strtotime($fromDate))) ?> to <?= htmlspecialchars(date('d M Y', strtotime($toDate))) ?>.</p>
        </article>
        <article class="agent-kpi-card">
            <div class="agent-kpi-header"><h2 class="agent-kpi-label">Average per Hour</h2><span class="agent-kpi-icon agent-kpi-icon-hour" aria-hidden="true">&#128202;</span></div>
            <div class="agent-kpi-value" id="kpi-avg-hour"><?= number_format($d['avg_per_hour'], 0) ?></div>
            <p class="agent-kpi-note">Average unique login events per hour for the selected date range.</p>
        </article>
        <article class="agent-kpi-card">
            <div class="agent-kpi-header"><h2 class="agent-kpi-label">Peak Hour</h2><span class="agent-kpi-icon agent-kpi-icon-peak" aria-hidden="true">&#128336;</span></div>
            <div class="agent-kpi-value" id="kpi-peak-hour"><?= htmlspecialchars(loginPerfHourLabel($d['peak_hour'])) ?></div>
            <p class="agent-kpi-note" id="kpi-peak-note"><?= number_format($d['peak_value']) ?> unique agent logins.</p>
        </article>
        <article class="agent-kpi-card">
            <div class="agent-kpi-header"><h2 class="agent-kpi-label">Active Hours</h2><span class="agent-kpi-icon agent-kpi-icon-active" aria-hidden="true">&#128101;</span></div>
            <div class="agent-kpi-value" id="kpi-active-hours"><?= number_format($d['active_hours']) ?></div>
            <p class="agent-kpi-note">Hourly intervals with at least one agent login in the selected period.</p>
        </article>
    </section>

    <section class="lp-grid">
        <article class="card">
            <h2 class="card-title">Hourly Login Activity</h2>
            <div class="lp-chart-wrap">
                <div class="lp-yaxis" id="lp-yaxis">
                    <?php
                    $steps = 5;
                    $topVal = (int)(ceil($maxHourly / 50) * 50);
                    if ($topVal <= 0) $topVal = 50;
                    for ($i = $steps; $i >= 0; $i--) echo '<span>' . round(($topVal / $steps) * $i) . '</span>';
                    ?>
                </div>
                <div class="lp-bars" id="lp-bars">
                    <?php foreach ($d['hourly'] as $hr => $val):
                        $heightPct = $topVal > 0 ? ($val / $topVal) * 100 : 0;
                    ?>
                    <div class="lp-bar-col" title="<?= htmlspecialchars(loginPerfHourLabel($hr)) ?>">
                        <?php if ($val > 0): ?><span class="lp-bar-val"><?= number_format($val) ?></span><?php endif; ?>
                        <div class="lp-bar <?= $hr === $d['peak_hour'] ? 'peak' : '' ?>" style="height:<?= max(2, $heightPct) ?>%"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="lp-xaxis">
                <?php foreach ($d['hourly'] as $hr => $val): ?>
                <span><?= str_pad((string)$hr, 2, '0', STR_PAD_LEFT) ?></span>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card">
            <h2 class="card-title">Top 5 Hours</h2>
            <p class="card-subtitle" id="lp-top-date"><?= htmlspecialchars(date('d M Y', strtotime($toDate))) ?></p>
            <div id="lp-top-hours">
            <?php if ($d['top_hours']):
                $topMax = max(array_column($d['top_hours'], 'total'));
                foreach ($d['top_hours'] as $i => $row):
                    $pct = $topMax > 0 ? ((int)$row['total'] / $topMax) * 100 : 0;
            ?>
            <div class="lp-top-row">
                <span class="lp-top-rank <?= $i === 0 ? 'gold' : '' ?>"><?= $i + 1 ?></span>
                <span class="lp-top-label"><?= htmlspecialchars(loginPerfHourLabel((int)$row['hr'])) ?></span>
                <div class="lp-top-bar-track"><div class="lp-top-bar-fill" style="width:<?= $pct ?>%"></div></div>
                <span class="lp-top-val"><?= number_format((int)$row['total']) ?></span>
            </div>
            <?php endforeach; else: ?>
            <div class="data-note" style="font-size:0.75rem;color:var(--gray-500);">No login activity found for this date.</div>
            <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="card breakdown-card">
        <h2 class="card-title">Hourly Breakdown</h2>
        <div class="lp-breakdown" id="lp-breakdown">
            <?php
            $half = array_chunk($d['breakdown'], 12, true);
            foreach ($half as $chunk):
            ?>
            <div style="overflow-x:auto">
                <table class="lp-breakdown-table">
                    <thead><tr><th>Hour</th><th>Total Agent Login</th><th>Average (per day)</th><th>Contribution (%)</th></tr></thead>
                    <tbody>
                    <?php foreach ($chunk as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars(loginPerfHourLabel($row['hour'])) ?></td>
                            <td><?= number_format($row['total']) ?></td>
                            <td><?= number_format($row['avg_per_day'], 1) ?></td>
                            <td><?= number_format($row['contribution'], 1) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="lp-note">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span>Hourly counts are based on unique agents by date and hour. If the same agent logs in multiple times within the same hour on the same day, it counts as 1. If the same agent logs in different hours, it counts once for each hour. If the same agent logs in on different days within the selected range, it counts once for each day-hour occurrence. The average is calculated by dividing the total agent login for each hour by the number of days selected (<span id="lp-note-days"><?= (int)$d['days_selected'] ?></span> days).</span>
        </div>
    </section>

</main>
</div>

<script>
(function () {
    'use strict';

    var form = document.getElementById('lp-filter-form');
    var applyBtn = document.getElementById('lp-apply-btn');
    var errorBox = document.getElementById('lp-error-box');
    var endpoint = window.location.pathname; // same script, ?ajax=1 added per request
    var currentController = null;

    function pad2(n) { return String(n).padStart(2, '0'); }

    function hourLabel(hr) {
        return pad2(hr) + ':00 – ' + pad2((hr + 1) % 24) + ':00';
    }

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function formatDateLabel(dateStr, withYear) {
        var parts = dateStr.split('-');
        var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10), dd = parseInt(parts[2], 10);
        return pad2(dd) + ' ' + MONTHS[m - 1] + (withYear ? ' ' + y : '');
    }

    function fmt(n, decimals) {
        decimals = decimals || 0;
        var num = Number(n) || 0;
        return num.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function renderReport(data) {
        errorBox.style.display = data.dbError ? 'block' : 'none';

        document.getElementById('kpi-total-agents').textContent = fmt(data.total_unique_agents);
        document.getElementById('kpi-total-note').textContent =
            'Unique agents logged in from ' + formatDateLabel(data.from, false) + ' to ' + formatDateLabel(data.to, true) + '.';
        document.getElementById('kpi-avg-hour').textContent = fmt(data.avg_per_hour);
        document.getElementById('kpi-peak-hour').textContent = hourLabel(data.peak_hour);
        document.getElementById('kpi-peak-note').textContent = fmt(data.peak_value) + ' unique agent logins.';
        document.getElementById('kpi-active-hours').textContent = fmt(data.active_hours);

        // Chart
        var maxHourly = Math.max(1, data.max_hourly || 1);
        var steps = 5;
        var topVal = Math.ceil(maxHourly / 50) * 50;
        if (topVal <= 0) topVal = 50;

        var yaxis = document.getElementById('lp-yaxis');
        yaxis.innerHTML = '';
        for (var i = steps; i >= 0; i--) {
            var s = document.createElement('span');
            s.textContent = Math.round((topVal / steps) * i);
            yaxis.appendChild(s);
        }

        var bars = document.getElementById('lp-bars');
        bars.innerHTML = '';
        data.hourly.forEach(function (val, hr) {
            var col = document.createElement('div');
            col.className = 'lp-bar-col';
            col.title = hourLabel(hr);
            if (val > 0) {
                var valSpan = document.createElement('span');
                valSpan.className = 'lp-bar-val';
                valSpan.textContent = fmt(val);
                col.appendChild(valSpan);
            }
            var bar = document.createElement('div');
            bar.className = 'lp-bar' + (hr === data.peak_hour ? ' peak' : '');
            var heightPct = topVal > 0 ? (val / topVal) * 100 : 0;
            bar.style.height = Math.max(2, heightPct) + '%';
            col.appendChild(bar);
            bars.appendChild(col);
        });

        // Top hours
        document.getElementById('lp-top-date').textContent = formatDateLabel(data.to, true);
        var topWrap = document.getElementById('lp-top-hours');
        topWrap.innerHTML = '';
        if (data.top_hours && data.top_hours.length) {
            var topMax = Math.max.apply(null, data.top_hours.map(function (r) { return r.total; }));
            data.top_hours.forEach(function (row, i) {
                var pct = topMax > 0 ? (row.total / topMax) * 100 : 0;
                var rowDiv = document.createElement('div');
                rowDiv.className = 'lp-top-row';

                var rank = document.createElement('span');
                rank.className = 'lp-top-rank' + (i === 0 ? ' gold' : '');
                rank.textContent = String(i + 1);

                var label = document.createElement('span');
                label.className = 'lp-top-label';
                label.textContent = hourLabel(row.hr);

                var track = document.createElement('div');
                track.className = 'lp-top-bar-track';
                var fill = document.createElement('div');
                fill.className = 'lp-top-bar-fill';
                fill.style.width = pct + '%';
                track.appendChild(fill);

                var val = document.createElement('span');
                val.className = 'lp-top-val';
                val.textContent = fmt(row.total);

                rowDiv.appendChild(rank);
                rowDiv.appendChild(label);
                rowDiv.appendChild(track);
                rowDiv.appendChild(val);
                topWrap.appendChild(rowDiv);
            });
        } else {
            var note = document.createElement('div');
            note.className = 'data-note';
            note.style.fontSize = '0.75rem';
            note.style.color = 'var(--gray-500)';
            note.textContent = 'No login activity found for this date.';
            topWrap.appendChild(note);
        }

        // Breakdown table
        var breakdownWrap = document.getElementById('lp-breakdown');
        breakdownWrap.innerHTML = '';
        var rows = data.breakdown || [];
        var chunks = [rows.slice(0, 12), rows.slice(12, 24)];
        chunks.forEach(function (chunk) {
            var wrapDiv = document.createElement('div');
            wrapDiv.style.overflowX = 'auto';
            var table = document.createElement('table');
            table.className = 'lp-breakdown-table';
            var thead = document.createElement('thead');
            thead.innerHTML = '<tr><th>Hour</th><th>Total Agent Login</th><th>Average (per day)</th><th>Contribution (%)</th></tr>';
            var tbody = document.createElement('tbody');
            chunk.forEach(function (r) {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + hourLabel(r.hour) + '</td>' +
                    '<td>' + fmt(r.total) + '</td>' +
                    '<td>' + fmt(r.avg_per_day, 1) + '</td>' +
                    '<td>' + fmt(r.contribution, 1) + '%</td>';
                tbody.appendChild(tr);
            });
            table.appendChild(thead);
            table.appendChild(tbody);
            wrapDiv.appendChild(table);
            breakdownWrap.appendChild(wrapDiv);
        });

        document.getElementById('lp-note-days').textContent = data.days_selected;
    }

    function loadReport() {
        var params = new URLSearchParams(new FormData(form));
        params.set('ajax', '1');

        if (currentController) currentController.abort();
        currentController = new AbortController();

        var originalLabel = applyBtn.textContent;
        applyBtn.disabled = true;
        applyBtn.textContent = 'Loading…';

        fetch(endpoint + '?' + params.toString(), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: currentController.signal
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    window.location = data.redirect;
                    return;
                }
                renderReport(data);
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') {
                    errorBox.textContent = 'Failed to load report. Please try again.';
                    errorBox.style.display = 'block';
                }
            })
            .finally(function () {
                applyBtn.disabled = false;
                applyBtn.textContent = originalLabel;
            });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        loadReport();
    });
})();
</script>

</body>
</html>