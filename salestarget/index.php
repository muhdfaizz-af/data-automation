<?php
/**
 * Sales Target Report (Estimation)
 *
 * Team picks a Month, sets a daily Target for every day of that month
 * (one input box per day — targets can differ day to day, same idea as
 * the ESTIMATION_SALES.xlsx workbook). Sales is fetched live from
 * `orders` (System sales, SG converted to MYR). Different and New Target
 * are calculated live on every load — nothing except `target_amount`
 * itself is persisted to the database.
 *
 * ── CALCULATION LOGIC (ported from ESTIMATION_SALES.xlsx) ──────────────
 *   Different[d]  = Sales[d] - Target[d]                  (only for d < today)
 *
 *   New Target[d] = Sales[d]                               if d < today (locked, already happened)
 *                 = Target[d] - (CumulativeVariance / RemainingDays)   if d >= today
 *
 *   CumulativeVariance = SUM(Different[d]) for every d < today in this month
 *   RemainingDays       = days_in_month - DAY(today) + 1
 *
 *   NOTE: the original Excel hardcoded "30" days per month and used
 *   DAY(today-1), which breaks for 28/29/31-day months and for the 1st
 *   of the month. This port uses the real days-in-month and a formula
 *   that doesn't roll over month boundaries — verified to reproduce the
 *   exact same figures as the Excel for September 2026, plus correct
 *   behaviour for Feb (28 days) and month-fully-past/future edge cases.
 *
 *   Total(New Target) always equals Total(Target) for the month — it's
 *   a pure redistribution of the remaining budget, not a change to the
 *   overall monthly target.
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
$adminId       = $_SESSION['admin_id'] ?? null;
$activeNav     = 'salestarget';
$navBasePath   = '../';

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
// VALIDATION / CLAMPING
// ════════════════════════════════════════════════════
function normalizeYear($v) {
    $currentYear = (int)date('Y');
    $v = (int)$v;
    return ($v >= 2000 && $v <= $currentYear + 1) ? $v : $currentYear;
}
function normalizeMonth($v) {
    $v = (int)$v;
    return ($v >= 1 && $v <= 12) ? $v : (int)date('n');
}
function daysInMonth($year, $month) {
    return (int)date('t', mktime(0, 0, 0, $month, 1, $year));
}
/**
 * The "Reporting Date" — lets the team simulate "if today was the 20th":
 * days before it are locked to actual Sales, days from it onward get the
 * New Target redistribution. Defaults to the real system date.
 */
function clampReportDate($v) {
    $today = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) return $today;
    if ($v < '2000-01-01' || $v > '2100-12-31') return $today;
    return $v;
}

// ════════════════════════════════════════════════════
// DATA ACCESS
// ════════════════════════════════════════════════════

/**
 * All saved targets for a given month. Returns assoc array 'Y-m-d' => float.
 */
function getMonthlyTargetMap($pdo, $year, $month) {
    $map = [];
    if (!$pdo) return $map;
    try {
        $dim = daysInMonth($year, $month);
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = sprintf('%04d-%02d-%02d', $year, $month, $dim);
        $stmt = $pdo->prepare("SELECT target_date, target_amount FROM sales_target WHERE target_date BETWEEN :from AND :to");
        $stmt->execute(['from' => $from, 'to' => $to]);
        foreach ($stmt->fetchAll() as $r) {
            $map[$r['target_date']] = (float)$r['target_amount'];
        }
    } catch (Exception $e) { /* table might not exist yet - treat as no targets set */ }
    return $map;
}

/**
 * Daily actual sales (System / orders only) for a given month, converted to MYR.
 * Returns assoc array 'Y-m-d' => float.
 */
function getDailyActualMap($pdo, $year, $month, $statusFilter = 'all') {
    $map = [];
    if (!$pdo) return $map;
    try {
        $dim = daysInMonth($year, $month);
        $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $toDt = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $dim));
        $toDt->modify('+1 day');
        $params = ['from' => $from, 'to' => $toDt->format('Y-m-d H:i:s')];
        $statusClause = '';
        if ($statusFilter !== 'all') {
            $statusClause = " AND o.order_status = :status";
            $params['status'] = $statusFilter === 'confirmed' ? 'Confirmed' : 'Void';
        }
        $sql = "SELECT DATE(o.order_datetime) AS d, c.company_code, SUM(o.sub_total) AS total
                FROM orders o
                JOIN companies c ON c.id = o.company_id
                WHERE o.order_datetime >= :from
                  AND o.order_datetime <  :to" . $statusClause . "
                GROUP BY d, c.company_code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $key = $r['d'];
            $map[$key] = ($map[$key] ?? 0.0) + toMyr($r['total'], $r['company_code']);
        }
    } catch (Exception $e) { /* keep empty */ }
    return $map;
}

/**
 * Upsert target amounts for a month. $targets = ['Y-m-d' => amount, ...]
 */
function saveMonthlyTargets($pdo, $targets, $adminId) {
    if (!$pdo) return false;
    try {
        $sql = "INSERT INTO sales_target (target_date, target_amount, created_by)
                VALUES (:target_date, :target_amount, :created_by)
                ON DUPLICATE KEY UPDATE target_amount = VALUES(target_amount), created_by = VALUES(created_by)";
        $stmt = $pdo->prepare($sql);
        foreach ($targets as $date => $amount) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $stmt->execute([
                'target_date'   => $date,
                'target_amount' => round((float)$amount, 2),
                'created_by'    => $adminId,
            ]);
        }
        return true;
    } catch (Exception $e) { return false; }
}

// ════════════════════════════════════════════════════
// CALCULATION — pure logic, no DB. Unit-tested separately against the
// original Excel figures (Sept 2026) plus Feb / past-month / future-month
// edge cases before being wired in here.
// ════════════════════════════════════════════════════
function buildEstimation($targetMap, $actualMap, $year, $month, $todayStr) {
    $today = new DateTime($todayStr);
    $dim = daysInMonth($year, $month);
    $firstOfMonth = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $lastOfMonth  = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $dim));

    if ($today < $firstOfMonth) {
        $remainingDays = $dim;
    } elseif ($today > $lastOfMonth) {
        $remainingDays = 0;
    } else {
        $remainingDays = $dim - (int)$today->format('j') + 1;
    }

    $rows = [];
    $cumVariance = 0.0;
    for ($d = 1; $d <= $dim; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $dateObj = new DateTime($date);
        $isPast = $dateObj < $today;
        $target = $targetMap[$date] ?? 0.0;
        $actual = $actualMap[$date] ?? 0.0;
        $different = $isPast ? ($actual - $target) : null;
        if ($isPast) $cumVariance += $different;
        $rows[$date] = [
            'date'      => $date,
            'day_label' => $dateObj->format('D, j M'),
            'is_past'   => $isPast,
            'is_today'  => $date === $today->format('Y-m-d'),
            'target'    => round($target, 2),
            'sales'     => $isPast ? round($actual, 2) : null,
            'different' => $different === null ? null : round($different, 2),
        ];
    }

    $adjPerDay = $remainingDays > 0 ? ($cumVariance / $remainingDays) : 0.0;

    $totalTarget = 0.0; $totalSales = 0.0; $totalDifferent = 0.0; $totalNewTarget = 0.0;
    foreach ($rows as $date => &$row) {
        $row['new_target'] = round($row['is_past'] ? $row['sales'] : ($row['target'] - $adjPerDay), 2);
        $totalTarget += $row['target'];
        if ($row['is_past']) { $totalSales += $row['sales']; $totalDifferent += $row['different']; }
        $totalNewTarget += $row['new_target'];
    }
    unset($row);

    return [
        'year' => $year, 'month' => $month,
        'rows' => array_values($rows),
        'today' => $today->format('Y-m-d'),
        'days_in_month' => $dim,
        'remaining_days' => $remainingDays,
        'cumulative_variance' => round($cumVariance, 2),
        'adjustment_per_day' => round($adjPerDay, 2),
        'totals' => [
            'target'     => round($totalTarget, 2),
            'sales'      => round($totalSales, 2),
            'different'  => round($totalDifferent, 2),
            'new_target' => round($totalNewTarget, 2),
        ],
        'achievement_pct' => $totalTarget > 0 ? round($totalSales / $totalTarget * 100, 2) : null,
    ];
}

$pdo = getDBConnection();

// ════════════════════════════════════════════════════
// AJAX ENDPOINTS
// ════════════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['ajax'];

    if ($action === 'month_data') {
        $year  = normalizeYear($_GET['year'] ?? '');
        $month = normalizeMonth($_GET['month'] ?? '');
        $statusFilter = normalizeStatus($_GET['status_filter'] ?? 'all');
        $reportDate = clampReportDate($_GET['report_date'] ?? '');

        $targetMap = getMonthlyTargetMap($pdo, $year, $month);
        $actualMap = getDailyActualMap($pdo, $year, $month, $statusFilter);
        $data = buildEstimation($targetMap, $actualMap, $year, $month, $reportDate);
        $data['error'] = '';
        echo json_encode($data);
        exit;
    }

    if ($action === 'save_targets') {
        $raw = json_decode(file_get_contents('php://input'), true);
        $year  = normalizeYear($raw['year'] ?? '');
        $month = normalizeMonth($raw['month'] ?? '');
        $targets = is_array($raw['targets'] ?? null) ? $raw['targets'] : [];

        $ok = saveMonthlyTargets($pdo, $targets, $adminId);

        // return the freshly recalculated month so the UI updates immediately
        $targetMap = getMonthlyTargetMap($pdo, $year, $month);
        $statusFilter = normalizeStatus($raw['status_filter'] ?? 'all');
        $reportDate = clampReportDate($raw['report_date'] ?? '');
        $actualMap = getDailyActualMap($pdo, $year, $month, $statusFilter);
        $data = buildEstimation($targetMap, $actualMap, $year, $month, $reportDate);
        $data['error'] = $ok ? '' : 'Failed to save targets. Please try again.';
        $data['saved'] = $ok;
        echo json_encode($data);
        exit;
    }

    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// ════════════════════════════════════════════════════
// INITIAL PAGE LOAD
// ════════════════════════════════════════════════════
$currentYear  = (int)date('Y');
$currentMonth = (int)date('n');
$statusFilter = 'all';
$todayYmd     = date('Y-m-d');

$targetMap = getMonthlyTargetMap($pdo, $currentYear, $currentMonth);
$actualMap = getDailyActualMap($pdo, $currentYear, $currentMonth, $statusFilter);
$estimation = buildEstimation($targetMap, $actualMap, $currentYear, $currentMonth, $todayYmd);

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
$yearOptionsStart = $currentYear - 3;
$yearOptionsEnd   = $currentYear + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sales Target — S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="icon" href="../images/icon-sasia.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --red:#E0202E;--red-dark:#8E1620;
  --teal:#00B4B4;--teal-dark:#008A8A;
  --ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;
  --gray-300:#D8D8DE;--gray-100:#F2F2F4;
  --bg:#F5F5F7;--white:#FFFFFF;
  --gold:#F5A623;--green:#10B981;
  --radius-lg:18px;--radius-md:12px;--radius-sm:8px;
  --sidebar-w:256px;--sidebar-w-collapsed:76px;--topbar-h:64px;
  --shadow-card:0 2px 8px rgba(20,20,30,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;}
a{text-decoration:none;color:inherit;}
button{font-family:inherit;cursor:pointer;border:none;background:none;}

.layout{display:flex;margin-top:var(--topbar-h);}
.main{margin-left:var(--sidebar-w);flex:1;padding:28px 32px 48px;min-width:0;transition:margin-left .25s ease;}
@media(max-width:900px){.main{margin-left:0;padding:20px;} body.sidebar-collapsed .main{margin-left:0;}}

.page-header{margin-bottom:24px;}
.page-header h1{font-size:24px;font-weight:800;margin-bottom:3px;}
.page-header p{font-size:13.5px;color:var(--gray-500);}

.report-card{background:var(--white);border-radius:var(--radius-lg);padding:24px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);margin-bottom:24px;}
.report-card-head{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
.report-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:linear-gradient(135deg,var(--gold),#c97e0e);}
.report-icon svg{width:20px;height:20px;stroke:#fff;}
.report-card-title{font-size:16px;font-weight:800;}
.report-card-sub{font-size:12px;color:var(--gray-500);font-weight:500;margin-top:1px;}

.filter-row{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:6px;min-width:150px;}
.filter-group label{font-size:11px;font-weight:700;color:var(--gray-700);text-transform:uppercase;letter-spacing:.3px;}
.filter-group select{padding:10px 12px;border:1.5px solid var(--gray-300);border-radius:9px;font-size:13.5px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--ink);background:#fff;outline:none;}
.filter-group select:focus{border-color:var(--ink);box-shadow:0 0 0 3px rgba(27,27,31,.1);}
.btn-apply{padding:11px 22px;background:var(--ink);color:#fff;border-radius:9px;font-size:13px;font-weight:800;display:inline-flex;align-items:center;gap:8px;}
.btn-apply:hover{background:#000;}
.btn-apply:disabled{opacity:.6;cursor:not-allowed;}
.btn-apply svg{width:14px;height:14px;stroke:#fff;fill:none;}
.btn-save{padding:11px 22px;background:var(--gold);color:#fff;border-radius:9px;font-size:13px;font-weight:800;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 14px rgba(245,166,35,.25);margin-left:auto;}
.btn-save:hover{background:#c97e0e;}
.btn-save:disabled{opacity:.6;cursor:not-allowed;}
.btn-save svg{width:14px;height:14px;stroke:#fff;fill:none;}
.filter-msg{font-size:12.5px;font-weight:600;padding:8px 12px;border-radius:8px;margin-bottom:14px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.filter-msg.success{background:#d1fae5;color:#065f46;border-color:#a7f3d0;}

.stats-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;}
.stat-card{flex:1;min-width:160px;background:var(--gray-100);border-radius:var(--radius-md);padding:14px 16px;}
.stat-card .stat-label{font-size:10.5px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.stat-card .stat-value{font-size:18px;font-weight:800;}
.stat-target .stat-value{color:var(--ink);}
.stat-sales .stat-value{color:var(--teal-dark);}
.stat-variance .stat-value.diff-pos{color:var(--green);}
.stat-variance .stat-value.diff-neg{color:var(--red);}
.stat-achievement .stat-value{color:#c97e0e;}

.target-note{font-size:11.5px;color:var(--gray-500);margin-bottom:16px;display:flex;align-items:flex-start;gap:7px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;border-radius:9px;padding:10px 12px;}
.target-note svg{width:14px;height:14px;stroke:#3730a3;flex-shrink:0;margin-top:1px;}

.target-table-scroll{overflow-x:auto;}
.target-table{width:100%;border-collapse:collapse;font-size:13px;min-width:680px;}
.target-table th{text-align:right;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--gray-500);padding:8px 8px;border-bottom:1.5px solid var(--gray-300);white-space:nowrap;}
.target-table th:first-child{text-align:left;}
.target-table td{padding:7px 8px;border-bottom:1px solid var(--gray-300);text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.target-table td:first-child{text-align:left;font-weight:700;}
.target-table tr.row-today td:first-child{color:var(--gold);}
.target-table tr.row-today{background:#fff8ec;}
.target-table tr.row-past td{color:var(--gray-700);}
.target-table tfoot td{font-weight:800;border-top:1.5px solid var(--ink);background:var(--gray-100);}
.target-input{width:110px;padding:6px 8px;border:1.5px solid var(--gray-300);border-radius:7px;font-size:12.5px;font-family:'Plus Jakarta Sans',sans-serif;text-align:right;font-variant-numeric:tabular-nums;outline:none;}
.target-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(245,166,35,.15);}
.target-input:disabled{background:var(--gray-100);color:var(--gray-500);}
.diff-pos{color:var(--green);}
.diff-neg{color:var(--red);}
.muted{color:var(--gray-500);}

@media(max-width:600px){.main{padding:16px 14px 40px;} .btn-save{margin-left:0;width:100%;justify-content:center;}}
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

<?php $pageTitle = 'Sales Target'; include __DIR__ . '/../includes/topnav.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="layout">
<main class="main">

  <div class="page-header">
    <h1>Sales Target</h1>
    <p>Set a daily target for the month — Sales is pulled live from Order History, Different &amp; New Target recalculate automatically.</p>
  </div>

  <div class="report-card">
    <div class="report-card-head">
      <div class="report-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg></div>
      <div>
        <div class="report-card-title">Monthly Target Grid</div>
        <div class="report-card-sub" id="cardSub">Pick a month, key in the daily target, then Save.</div>
      </div>
    </div>

    <div class="filter-row" style="margin-bottom:18px;">
      <div class="filter-group">
        <label for="monthSelect">Month</label>
        <select id="monthSelect">
          <?php foreach ($monthNames as $num => $name): ?>
            <option value="<?= $num ?>" <?= $num === $currentMonth ? 'selected' : '' ?>><?= $name ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label for="yearSelect">Year</label>
        <select id="yearSelect">
          <?php for ($y = $yearOptionsEnd; $y >= $yearOptionsStart; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="filter-group">
        <label for="statusSelect">Status</label>
        <select id="statusSelect">
          <option value="all" selected>All</option>
          <option value="confirmed">Confirmed</option>
          <option value="void">Void</option>
        </select>
      </div>
      <div class="filter-group">
        <label for="reportDateInput">Reporting Date <span style="text-transform:none;font-weight:500;color:var(--gray-500);">(treat as "today")</span></label>
        <input type="date" id="reportDateInput" value="<?= htmlspecialchars($todayYmd) ?>">
      </div>
      <button type="button" class="btn-apply" id="btnLoad">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Load
      </button>
      <button type="button" class="btn-save" id="btnSave">
        <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save Targets
      </button>
    </div>

    <div class="filter-msg" id="msgBox" style="display:none;"></div>

    <div class="stats-row">
      <div class="stat-card stat-target">
        <div class="stat-label">Total Target (Month)</div>
        <div class="stat-value" id="statTarget">RM 0.00</div>
      </div>
      <div class="stat-card stat-sales">
        <div class="stat-label">Total Sales (MTD so far)</div>
        <div class="stat-value" id="statSales">RM 0.00</div>
      </div>
      <div class="stat-card stat-variance">
        <div class="stat-label">Cumulative Variance</div>
        <div class="stat-value" id="statVariance">RM 0.00</div>
      </div>
      <div class="stat-card stat-achievement">
        <div class="stat-label">Achievement</div>
        <div class="stat-value" id="statAchievement">—</div>
      </div>
    </div>

    <div class="target-note">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      <span>Sales &amp; Different are locked once a day has passed. <strong>New Target</strong> redistributes the cumulative shortfall/surplus so far across the remaining days of the month — it updates every day automatically and is never saved, only the Target you type in is.</span>
    </div>

    <div class="target-table-scroll">
      <table class="target-table" id="targetTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Target</th>
            <th>Sales</th>
            <th>Different</th>
            <th>New Target</th>
          </tr>
        </thead>
        <tbody id="targetTableBody"></tbody>
        <tfoot><tr id="targetTotalRow"></tr></tfoot>
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
    d.classList.add('open'); o.style.display='block';
    requestAnimationFrame(()=>o.classList.add('open'));
    document.body.style.overflow='hidden';
}
function closeDrawer(){
    const d = document.getElementById('sidebarDrawer');
    const o = document.getElementById('drawerOverlay');
    if (!d || !o) return;
    d.classList.remove('open'); o.classList.remove('open');
    setTimeout(()=>{o.style.display='none';},260);
    document.body.style.overflow='';
}
document.addEventListener('keydown', function(e){ if (e.key==='Escape') closeDrawer(); });
function toggleSidebarOnDesktop(){
    if (window.innerWidth >= 900) {
        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        try {
            if (collapsed) localStorage.setItem('adminSidebarCollapsed','1');
            else localStorage.removeItem('adminSidebarCollapsed');
        } catch(e){}
    } else { openDrawer(); }
}

function formatRM(n){
    if (n === null || n === undefined) return '—';
    return 'RM ' + Number(n).toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function formatPct(n){
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2}) + '%';
}
function diffClass(n){
    if (n === null || n === undefined) return '';
    return n >= 0 ? 'diff-pos' : 'diff-neg';
}

let currentMonthData = null; // last payload from the server, so Save can re-read edited inputs

function renderTable(data){
    currentMonthData = data;

    document.getElementById('cardSub').textContent =
        `${data.days_in_month} days · reporting date = ${data.today} · remaining days = ${data.remaining_days}`;

    const tbody = document.getElementById('targetTableBody');
    tbody.innerHTML = '';
    data.rows.forEach(row => {
        const tr = document.createElement('tr');
        tr.className = row.is_today ? 'row-today' : (row.is_past ? 'row-past' : '');
        tr.innerHTML = `
            <td>${row.day_label}${row.is_today ? ' (reporting date)' : ''}</td>
            <td><input type="number" min="0" step="0.01" class="target-input" data-date="${row.date}" value="${row.target}"></td>
            <td>${row.sales === null ? '<span class="muted">—</span>' : formatRM(row.sales)}</td>
            <td class="${diffClass(row.different)}">${row.different === null ? '<span class="muted">—</span>' : formatRM(row.different)}</td>
            <td class="muted">${formatRM(row.new_target)}</td>
        `;
        tbody.appendChild(tr);
    });

    const t = data.totals;
    document.getElementById('targetTotalRow').innerHTML = `
        <td>Total</td>
        <td>${formatRM(t.target)}</td>
        <td>${formatRM(t.sales)}</td>
        <td class="${diffClass(t.different)}">${formatRM(t.different)}</td>
        <td>${formatRM(t.new_target)}</td>
    `;

    document.getElementById('statTarget').textContent = formatRM(t.target);
    document.getElementById('statSales').textContent = formatRM(t.sales);
    const varEl = document.getElementById('statVariance');
    varEl.textContent = formatRM(data.cumulative_variance);
    varEl.className = 'stat-value ' + diffClass(data.cumulative_variance);
    document.getElementById('statAchievement').textContent = data.achievement_pct === null ? '—' : formatPct(data.achievement_pct);
}

function showMsg(text, isSuccess){
    const box = document.getElementById('msgBox');
    box.textContent = text;
    box.className = 'filter-msg' + (isSuccess ? ' success' : '');
    box.style.display = 'block';
    if (isSuccess) setTimeout(() => { box.style.display = 'none'; }, 3000);
}

const AJAX_URL = window.location.pathname;

async function loadMonth(){
    const year = document.getElementById('yearSelect').value;
    const month = document.getElementById('monthSelect').value;
    const status = document.getElementById('statusSelect').value;
    const reportDate = document.getElementById('reportDateInput').value;
    const params = new URLSearchParams({ ajax:'month_data', year, month, status_filter: status, report_date: reportDate });

    document.getElementById('btnLoad').disabled = true;
    try {
        const res = await fetch(AJAX_URL + '?' + params.toString(), { headers:{'X-Requested-With':'XMLHttpRequest'} });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        const reportYm = reportDate.slice(0,7);
        const gridYm = `${year}-${String(month).padStart(2,'0')}`;
        if (json.error) { showMsg(json.error, false); }
        else if (reportYm !== gridYm) {
            showMsg(`Note: Reporting Date (${reportDate}) is outside the ${gridYm} grid you're viewing — every day here will be treated as ${reportYm < gridYm ? 'upcoming (not yet happened)' : 'already passed (locked to actual)'}.`, false);
        }
        else { document.getElementById('msgBox').style.display = 'none'; }
        renderTable(json);
    } catch (err) {
        showMsg('Failed to load data. Please try again.', false);
    } finally {
        document.getElementById('btnLoad').disabled = false;
    }
}

async function saveTargets(){
    if (!currentMonthData) return;
    const inputs = document.querySelectorAll('.target-input');
    const targets = {};
    inputs.forEach(inp => { targets[inp.dataset.date] = Number(inp.value) || 0; });

    const payload = {
        year: document.getElementById('yearSelect').value,
        month: document.getElementById('monthSelect').value,
        status_filter: document.getElementById('statusSelect').value,
        report_date: document.getElementById('reportDateInput').value,
        targets
    };

    document.getElementById('btnSave').disabled = true;
    try {
        const res = await fetch(AJAX_URL + '?ajax=save_targets', {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' },
            body: JSON.stringify(payload)
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (json.saved) showMsg('Targets saved.', true);
        else showMsg(json.error || 'Failed to save targets.', false);
        renderTable(json);
    } catch (err) {
        showMsg('Failed to save targets. Please try again.', false);
    } finally {
        document.getElementById('btnSave').disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', function(){
    renderTable(<?= json_encode($estimation) ?>);
    document.getElementById('btnLoad').addEventListener('click', loadMonth);
    document.getElementById('btnSave').addEventListener('click', saveTargets);
});
</script>
</body>
</html>