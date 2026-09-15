<?php
/**
 * Manual Sales by Channel
 *
 * Displays manual_sales only, grouped by sales channel.
 * Daily / Month-to-Date / Year-to-Date comparison, as of a Reporting Date —
 * same shape as the Sales by Hub report (3.4 Comparison Sales by Hub).
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

define('SGD_TO_MYR_RATE', 3.27);

if (empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

$idleLimit = 7200;
if (
    !empty($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $idleLimit
) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

$activeNav = 'sales_channel';
$navBasePath = '../';

// Consistent channel color palette (cycled if there are more channels than colors)
const CHANNEL_PALETTE = ['#E0202E', '#F5A623', '#00B4B4', '#2563EB', '#8B5CF6', '#10B981'];

function getDBConnection(): ?PDO
{
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}

function isValidDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function normalizeCompany(mixed $company): string
{
    return is_string($company) && in_array($company, ['all', 'MY', 'SG'], true)
        ? $company
        : 'all';
}

/**
 * Clamp/validate the single Reporting Date filter: must be a real date,
 * not in the future, and not before 2000-01-01. Falls back to yesterday.
 */
function clampReportDate(mixed $value): array
{
    $today = date('Y-m-d');
    $fallback = date('Y-m-d', strtotime('-1 day'));

    if (!is_string($value) || !isValidDate($value)) {
        return [$fallback, ''];
    }
    if ($value > $today) {
        return [$today, 'Reporting date cannot be in the future.'];
    }
    if ($value < '2000-01-01') {
        return [$fallback, 'Invalid reporting date.'];
    }

    return [$value, ''];
}

function getManualChannelRangeTotals(PDO $pdo, string $from, string $to, string $companyFilter): array
{
    $params = ['from_date' => $from, 'to_date' => $to];
    $companyCondition = '';

    if ($companyFilter !== 'all') {
        $companyCondition = ' AND c.company_code = :company_code';
        $params['company_code'] = $companyFilter;
    }

    $statement = $pdo->prepare(
        "SELECT sc.channel_code, COALESCE(SUM(
            CASE WHEN c.company_code = 'SG'
                 THEN ms.amount * " . SGD_TO_MYR_RATE . "
                 ELSE ms.amount END
        ), 0) AS total_sales
         FROM manual_sales ms
         INNER JOIN sales_channels sc ON sc.id = ms.sales_channel_id
         INNER JOIN companies c ON c.id = ms.company_id
         WHERE sc.is_active = 1
           AND ms.sales_date >= :from_date
           AND ms.sales_date <= :to_date
           {$companyCondition}
         GROUP BY sc.channel_code"
    );
    $statement->execute($params);

    $totals = [];
    foreach ($statement->fetchAll() as $row) {
        $totals[(string)$row['channel_code']] = round((float)$row['total_sales'], 2);
    }

    return $totals;
}

$today = date('Y-m-d');
[$reportDate, $dateError] = clampReportDate($_GET['date'] ?? null);
$companyFilter = normalizeCompany($_GET['company'] ?? 'all');
$errors = $dateError ? [$dateError] : [];

// Per-period payload skeleton: for every active channel, code/name/color + zeroed totals
$dailyChannels = [];
$monthlyChannels = [];
$yearlyChannels = [];
$dailyGrand = 0.0;
$monthlyGrand = 0.0;
$yearlyGrand = 0.0;
$monthlyComparison = [];
$yearlyComparison = [];
$monthlyComparisonTotals = ['current' => 0.0, 'previous' => 0.0, 'change' => null];
$yearlyComparisonTotals = ['current' => 0.0, 'previous' => 0.0, 'change' => null];

if (empty($errors)) {
    $pdo = getDBConnection();

    if (!$pdo) {
        $errors[] = 'Unable to connect to the database.';
    } else {
        try {
            $reportDt = new DateTimeImmutable($reportDate);
            $yearFrom = $reportDt->format('Y') . '-01-01';
            $monthPrefix = $reportDt->format('Y-m');

            $companyCondition = '';
            $params = [
                'from_date' => $yearFrom,
                'to_date' => $reportDate,
            ];

            if ($companyFilter !== 'all') {
                $companyCondition = ' AND ms.company_id IN (
                    SELECT filtered_company.id
                    FROM companies filtered_company
                    WHERE filtered_company.company_code = :company_code
                )';
                $params['company_code'] = $companyFilter;
            }

            // One query for Jan 1 -> Reporting Date, per channel per day. Daily / MTD / YTD
            // are then bucketed in PHP from this single result set (same approach as the
            // Sales by Hub report), instead of running 3 separate overlapping range scans.
            $sql = "
                SELECT
                    sc.channel_code,
                    sc.channel_name,
                    ms.sales_date,
                    ms.amount,
                    c.company_code
                FROM sales_channels sc
                LEFT JOIN manual_sales ms
                    ON ms.sales_channel_id = sc.id
                   AND ms.sales_date >= :from_date
                   AND ms.sales_date <= :to_date
                LEFT JOIN companies c
                    ON c.id = ms.company_id
                   {$companyCondition}
                WHERE sc.is_active = 1
                ORDER BY sc.channel_code ASC, ms.sales_date ASC
            ";

            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            $rows = $statement->fetchAll();

            // Master channel list, in stable channel_code order -> assign a stable color to each
            $channelMeta = [];
            $paletteCount = count(CHANNEL_PALETTE);
            foreach ($rows as $row) {
                $code = (string)$row['channel_code'];
                if (!isset($channelMeta[$code])) {
                    $index = count($channelMeta);
                    $channelMeta[$code] = [
                        'channel_code' => $code,
                        'channel_name' => (string)$row['channel_name'],
                        'color' => CHANNEL_PALETTE[$index % $paletteCount],
                    ];
                }
            }

            $dailyTotals = array_fill_keys(array_keys($channelMeta), ['total' => 0.0, 'entry_count' => 0]);
            $monthlyTotals = array_fill_keys(array_keys($channelMeta), ['total' => 0.0, 'entry_count' => 0]);
            $yearlyTotals = array_fill_keys(array_keys($channelMeta), ['total' => 0.0, 'entry_count' => 0]);

            foreach ($rows as $row) {
                if ($row['sales_date'] === null) {
                    continue;
                }

                $code = (string)$row['channel_code'];
                $sales = (float)$row['amount'];
                if ($row['company_code'] === 'SG') {
                    $sales *= SGD_TO_MYR_RATE;
                }

                $isToday = ($row['sales_date'] === $reportDate);
                $isThisMonth = (strncmp((string)$row['sales_date'], $monthPrefix, 7) === 0);

                $yearlyTotals[$code]['total'] += $sales;
                $yearlyTotals[$code]['entry_count']++;

                if ($isThisMonth) {
                    $monthlyTotals[$code]['total'] += $sales;
                    $monthlyTotals[$code]['entry_count']++;
                }
                if ($isToday) {
                    $dailyTotals[$code]['total'] += $sales;
                    $dailyTotals[$code]['entry_count']++;
                }
            }

            $dailyGrand = array_sum(array_column($dailyTotals, 'total'));
            $monthlyGrand = array_sum(array_column($monthlyTotals, 'total'));
            $yearlyGrand = array_sum(array_column($yearlyTotals, 'total'));

            // Stable display order across all three panels: rank by YTD total desc,
            // so a channel's color/position reads the same in every panel.
            $order = array_keys($channelMeta);
            usort($order, static function (string $a, string $b) use ($yearlyTotals): int {
                return $yearlyTotals[$b]['total'] <=> $yearlyTotals[$a]['total']
                    ?: strcmp($a, $b);
            });

            $buildPeriod = static function (array $totals, float $grand) use ($order, $channelMeta): array {
                $out = [];
                foreach ($order as $code) {
                    $meta = $channelMeta[$code];
                    $total = round($totals[$code]['total'], 2);
                    $out[] = [
                        'channel_code' => $meta['channel_code'],
                        'channel_name' => $meta['channel_name'],
                        'color' => $meta['color'],
                        'total_sales' => $total,
                        'entry_count' => $totals[$code]['entry_count'],
                        'pct' => $grand > 0 ? round($total / $grand * 100, 2) : 0.0,
                    ];
                }
                return $out;
            };

            $dailyChannels = $buildPeriod($dailyTotals, $dailyGrand);
            $monthlyChannels = $buildPeriod($monthlyTotals, $monthlyGrand);
            $yearlyChannels = $buildPeriod($yearlyTotals, $yearlyGrand);

            $dailyGrand = round($dailyGrand, 2);
            $monthlyGrand = round($monthlyGrand, 2);
            $yearlyGrand = round($yearlyGrand, 2);

            $previousMonth = $reportDt->modify('first day of previous month');
            $previousMonthDay = min(
                (int)$reportDt->format('j'),
                (int)$previousMonth->format('t')
            );
            $previousMonthTo = $previousMonth->modify('+' . ($previousMonthDay - 1) . ' days');
            $previousMonthTotals = getManualChannelRangeTotals(
                $pdo,
                $previousMonth->format('Y-m-d'),
                $previousMonthTo->format('Y-m-d'),
                $companyFilter
            );

            $previousYear = $reportDt->modify('-1 year');
            $previousYearTo = $previousYear->setDate(
                (int)$previousYear->format('Y'),
                (int)$reportDt->format('n'),
                min((int)$reportDt->format('j'), (int)$previousYear->format('t'))
            );
            $previousYearTotals = getManualChannelRangeTotals(
                $pdo,
                $previousYear->format('Y-01-01'),
                $previousYearTo->format('Y-m-d'),
                $companyFilter
            );

            $monthlyCurrentTotal = 0.0;
            $monthlyPreviousTotal = 0.0;
            $yearlyCurrentTotal = 0.0;
            $yearlyPreviousTotal = 0.0;

            foreach ($channelMeta as $code => $meta) {
                $monthlyCurrent = $monthlyTotals[$code]['total'] ?? 0.0;
                $monthlyPrevious = $previousMonthTotals[$code] ?? 0.0;
                $yearlyCurrent = $yearlyTotals[$code]['total'] ?? 0.0;
                $yearlyPrevious = $previousYearTotals[$code] ?? 0.0;

                $monthlyCurrentTotal += $monthlyCurrent;
                $monthlyPreviousTotal += $monthlyPrevious;
                $yearlyCurrentTotal += $yearlyCurrent;
                $yearlyPreviousTotal += $yearlyPrevious;

                $monthlyComparison[] = [
                    'channel_code' => $code,
                    'color' => $meta['color'],
                    'current' => round($monthlyCurrent, 2),
                    'previous' => round($monthlyPrevious, 2),
                    'difference' => round($monthlyCurrent - $monthlyPrevious, 2),
                    'change' => $monthlyPrevious > 0 ? round(($monthlyCurrent - $monthlyPrevious) / $monthlyPrevious * 100, 2) : null,
                ];
                $yearlyComparison[] = [
                    'channel_code' => $code,
                    'color' => $meta['color'],
                    'current' => round($yearlyCurrent, 2),
                    'previous' => round($yearlyPrevious, 2),
                    'difference' => round($yearlyCurrent - $yearlyPrevious, 2),
                    'change' => $yearlyPrevious > 0 ? round(($yearlyCurrent - $yearlyPrevious) / $yearlyPrevious * 100, 2) : null,
                ];
            }

            $monthlyComparisonTotals = [
                'current' => round($monthlyCurrentTotal, 2),
                'previous' => round($monthlyPreviousTotal, 2),
                'difference' => round($monthlyCurrentTotal - $monthlyPreviousTotal, 2),
                'change' => $monthlyPreviousTotal > 0 ? round(($monthlyCurrentTotal - $monthlyPreviousTotal) / $monthlyPreviousTotal * 100, 2) : null,
            ];
            $yearlyComparisonTotals = [
                'current' => round($yearlyCurrentTotal, 2),
                'previous' => round($yearlyPreviousTotal, 2),
                'difference' => round($yearlyCurrentTotal - $yearlyPreviousTotal, 2),
                'change' => $yearlyPreviousTotal > 0 ? round(($yearlyCurrentTotal - $yearlyPreviousTotal) / $yearlyPreviousTotal * 100, 2) : null,
            ];
        } catch (Throwable $e) {
            error_log('Manual sales channel report failed: ' . $e->getMessage());
            $errors[] = 'Unable to load the manual sales channel report.';
        }
    }
}

$reportDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $reportDate) ?: new DateTimeImmutable($today);
$reportDateLabel = $reportDateObj->format('j F Y');

// Build date range labels for the comparison tables
$monthStartLabel = $reportDateObj->format('j F Y');
$monthFromLabel = $reportDateObj->modify('first day of this month')->format('j F Y');
$monthRangeLabel = $monthFromLabel . ' – ' . $monthStartLabel;

$yearFromLabel = $reportDateObj->format('Y') . '-01-01';
$yearFromLabelFormatted = $reportDateObj->modify('first day of January')->format('j F Y');
$yearRangeLabel = $yearFromLabelFormatted . ' – ' . $monthStartLabel;

// Previous month range label
$prevMonthDt = $reportDateObj->modify('first day of previous month');
$prevMonthFromLabel = $prevMonthDt->format('j F Y');
$prevMonthDay = min((int)$reportDateObj->format('j'), (int)$prevMonthDt->format('t'));
$prevMonthToLabel = $prevMonthDt->modify('+' . ($prevMonthDay - 1) . ' days')->format('j F Y');
$prevMonthRangeLabel = $prevMonthFromLabel . ' – ' . $prevMonthToLabel;

// Previous year range label
$prevYearDt = $reportDateObj->modify('-1 year');
$prevYearFromLabel = $prevYearDt->modify('first day of January')->format('j F Y');
$prevYearToLabel = $prevYearDt->setDate(
    (int)$prevYearDt->format('Y'),
    (int)$reportDateObj->format('n'),
    min((int)$reportDateObj->format('j'), (int)$prevYearDt->format('t'))
)->format('j F Y');
$prevYearRangeLabel = $prevYearFromLabel . ' – ' . $prevYearToLabel;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales Channel - S ASIA SALES REPORT</title>
<link rel="icon" href="../images/icon-sasia.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<style>
:root{
  --red:#E0202E;--red-dark:#8E1620;--red-soft:#FDEDEE;
  --ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;--gray-300:#D8D8DE;--gray-100:#F2F2F4;
  --bg:#F5F5F7;--white:#fff;--green:#10B981;--green-soft:#ECFDF5;
  --gold:#F5A623;--blue:#2563EB;--teal:#00B4B4;--violet:#8B5CF6;
  --sidebar-w:260px;--sidebar-w-collapsed:82px;--topbar-h:64px;
  --radius-lg:18px;--radius-md:12px;--radius-sm:8px;
  --shadow:0 1px 2px rgba(20,20,30,.04),0 8px 24px -12px rgba(20,20,30,.10);
  --shadow-card:0 2px 8px rgba(20,20,30,.06);
  --shadow-card-hover:0 12px 32px rgba(20,20,30,.10);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:var(--bg);color:var(--ink);font-family:'Plus Jakarta Sans',sans-serif;-webkit-font-smoothing:antialiased}
button,input,select{font:inherit}
svg{display:block}
.layout{display:flex;margin-top:var(--topbar-h)}
.main{min-width:0;flex:1;margin-left:var(--sidebar-w);padding:28px 32px 48px;transition:margin-left .25s ease}
body.sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed)}

.page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.page-header h1{margin-bottom:4px;font-size:26px;font-weight:800;letter-spacing:-.3px}
.page-header p{color:var(--gray-500);font-size:13px}
.page-header-badge{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid var(--gray-100);border-radius:999px;background:#fff;box-shadow:var(--shadow-card);color:var(--gray-700);font-size:12px;font-weight:700;white-space:nowrap}
.page-header-badge::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--green)}

.report-card{margin-bottom:24px;padding:24px;border:1px solid var(--gray-100);border-radius:var(--radius-lg);background:var(--white);box-shadow:var(--shadow-card);transition:box-shadow .2s ease}
.report-card:hover{box-shadow:var(--shadow-card-hover)}
.report-card-head{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.report-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.report-icon svg{width:20px;height:20px;stroke:#fff}
.ri-red{background:linear-gradient(135deg,var(--red),var(--red-dark))}
.ri-dark{background:linear-gradient(135deg,var(--gray-700),var(--ink))}
.report-card-title{font-size:16px;font-weight:800}
.report-card-sub{font-size:12px;color:var(--gray-500);font-weight:500;margin-top:1px}

/* Filters */
.global-filter-card{border:1.5px solid var(--ink)}
.global-filter-row{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap}
.global-filter-group{display:flex;flex-direction:column;gap:6px;min-width:170px}
.global-filter-group label{font-size:11px;font-weight:700;color:var(--gray-700);text-transform:uppercase;letter-spacing:.3px}
.global-filter-group select,.global-filter-group input{width:100%;min-height:44px;padding:10px 12px;border:1.5px solid var(--gray-300);border-radius:9px;background:#fff;color:var(--ink);font-size:13.5px;font-weight:600;transition:border-color .15s ease, box-shadow .15s ease}
.global-filter-group select:hover,.global-filter-group input:hover{border-color:#c3c3cb}
.global-filter-group select:focus,.global-filter-group input:focus{outline:none;border-color:var(--ink);box-shadow:0 0 0 3px rgba(27,27,31,.1)}
.apply-button{min-height:44px;padding:11px 22px;border:0;border-radius:9px;background:var(--ink);box-shadow:0 4px 14px rgba(27,27,31,.2);color:#fff;cursor:pointer;font-size:13px;font-weight:800;letter-spacing:.2px;display:inline-flex;align-items:center;gap:8px;transition:background .15s ease, transform .15s ease}
.apply-button:hover{background:#000;transform:translateY(-1px)}
.apply-button:active{transform:translateY(0)}
.apply-button svg{width:14px;height:14px;stroke:#fff;fill:none}
.global-filter-hint{font-size:11.5px;color:var(--gray-500);margin-left:auto;align-self:center;max-width:280px}
.error-box{margin-bottom:20px;padding:13px 15px;border:1px solid #fecaca;border-radius:10px;background:#fef2f2;color:#991b1b;font-size:13px;font-weight:600}

/* Ring/donut chart grid — Daily / Monthly / Yearly, mirrors Sales by Hub 3.4 */
.hub-pie-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
@media(max-width:1100px){.hub-pie-grid{grid-template-columns:1fr}}
.hub-pie-panel{background:var(--gray-100);border-radius:var(--radius-md);padding:16px;transition:box-shadow .2s ease}
.hub-pie-panel:hover{box-shadow:0 4px 16px rgba(20,20,30,.06)}
.hub-pie-panel-title{font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-700);margin-bottom:2px}
.hub-pie-panel-sub{font-size:11px;color:var(--gray-500);margin-bottom:12px}
.hub-pie-wrap{position:relative;height:190px;margin-bottom:14px}
.hub-pie-wrap canvas{width:100%!important;height:100%!important}
.hub-pie-grand{text-align:center;font-size:13px;font-weight:800;margin-bottom:12px}

/* Table */
.hub-table{width:100%;border-collapse:collapse;font-size:12.5px}
.hub-table th{text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--gray-500);padding:6px 6px;border-bottom:1.5px solid var(--gray-300)}
.hub-table td{padding:8px 6px;border-bottom:1px solid var(--gray-300);font-weight:600}
.hub-table th:not(:first-child),.hub-table td:not(:first-child){text-align:right}
.hub-table tbody tr{transition:background .15s ease}
.hub-table tbody tr:hover{background:rgba(255,255,255,.65)}
.hub-table tr:last-child td{border-bottom:none}
.hub-table td.num{font-variant-numeric:tabular-nums}
.hub-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:7px;flex-shrink:0;box-shadow:0 0 0 3px rgba(0,0,0,.03)}
.hub-name-cell{display:flex;align-items:center;text-align:left}
.hub-table tfoot td{font-weight:800;border-top:1.5px solid var(--ink);border-bottom:none;padding-top:10px}
.empty-row{text-align:center!important;color:var(--gray-500);padding:24px!important}

.comparison-table-wrap{margin-top:20px;width:100%}
.comparison-table-title{margin-bottom:8px;color:var(--gray-700);font-size:11px;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
.comparison-table-title span{color:var(--gray-500);font-weight:600;text-transform:none;letter-spacing:0}
.comparison-table-scroll{overflow-x:auto;border:1px solid var(--gray-100);border-radius:var(--radius-md)}
.comparison-table{width:100%;border-collapse:collapse;font-size:12px}
.comparison-table th{padding:10px 14px;background:var(--green);color:#fff;font-size:10px;text-align:left;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.comparison-table td{padding:10px 14px;border-top:1px solid var(--gray-100);white-space:nowrap;font-weight:600}
.comparison-table td.num,.comparison-table th:not(:first-child){text-align:right}
.comparison-table tfoot td{font-weight:800;border-top:1.5px solid var(--ink);background:var(--gray-100)}
.change-positive{color:var(--green);font-weight:800}
.change-negative{color:var(--red);font-weight:800}
.change-neutral{color:var(--gray-500);font-weight:700}
.diff-positive{color:var(--green);font-weight:700}
.diff-negative{color:var(--red);font-weight:700}
.diff-neutral{color:var(--gray-500);font-weight:600}
@media(max-width:650px){.main{padding:18px 14px 40px}.page-header{flex-direction:column;align-items:flex-start}.global-filter-hint{margin-left:0;max-width:none}}
</style>
</head>
<body>
<script>(function(){try{if(window.innerWidth>=900&&localStorage.getItem('adminSidebarCollapsed')==='1')document.body.classList.add('sidebar-collapsed')}catch(e){}})();</script>
<?php $pageTitle = 'Sales Channel'; include __DIR__ . '/../includes/topnav.php'; include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="layout">
<main class="main">
    <div class="page-header">
        <div>
            <h1>Sales Channel</h1>
            <p>Manual sales only, grouped by external sales channel.</p>
        </div>
        <span class="page-header-badge">Live report</span>
    </div>

    <?php if ($errors): ?>
        <div class="error-box" role="alert">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ═══════════════ FILTER: REPORTING DATE + COMPANY ═══════════════ -->
    <section class="report-card global-filter-card">
        <div class="report-card-head">
            <div class="report-icon ri-dark"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg></div>
            <div>
                <div class="report-card-title">Report Filters</div>
                <div class="report-card-sub">Daily = the selected date, Monthly = MTD, Yearly = YTD (up to the selected date). System order sales are not included.</div>
            </div>
        </div>
        <form method="get" class="global-filter-row">
            <div class="global-filter-group">
                <label for="date">Reporting Date</label>
                <input type="date" id="date" name="date" value="<?= htmlspecialchars($reportDate) ?>" max="<?= htmlspecialchars($today) ?>" required>
            </div>
            <div class="global-filter-group">
                <label for="company">Company</label>
                <select id="company" name="company">
                    <option value="all" <?= $companyFilter === 'all' ? 'selected' : '' ?>>All Companies</option>
                    <option value="MY" <?= $companyFilter === 'MY' ? 'selected' : '' ?>>Malaysia</option>
                    <option value="SG" <?= $companyFilter === 'SG' ? 'selected' : '' ?>>Singapore</option>
                </select>
            </div>
            <button type="submit" class="apply-button">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Apply Filter
            </button>
            <span class="global-filter-hint">Amounts converted to MYR. Singapore entries use SGD × <?= number_format(SGD_TO_MYR_RATE, 2) ?>.</span>
        </form>
    </section>

    <!-- ═══════════════ COMPARISON SALES BY CHANNEL — Daily / MTD / YTD ═══════════════ -->
    <section class="report-card">
        <div class="report-card-head">
            <div class="report-icon ri-red"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></div>
            <div>
                <div class="report-card-title">Comparison Sales by Channel — <?= htmlspecialchars($reportDateLabel) ?></div>
                <div class="report-card-sub">Daily Sales · Month-to-Date · Year-to-Date, broken down by channel</div>
            </div>
        </div>
        <div class="hub-pie-grid">
            <?php
            $periods = [
                ['key' => 'daily',   'title' => 'Daily Sales',        'sub' => $reportDateLabel . ' only',        'channels' => $dailyChannels,   'grand' => $dailyGrand,   'canvas' => 'dailyPie'],
                ['key' => 'monthly', 'title' => 'Monthly Sales (MTD)', 'sub' => '1st – ' . $reportDateLabel,        'channels' => $monthlyChannels, 'grand' => $monthlyGrand, 'canvas' => 'monthlyPie'],
                ['key' => 'yearly',  'title' => 'Yearly Sales (YTD)',  'sub' => 'Jan 1 – ' . $reportDateLabel,      'channels' => $yearlyChannels,  'grand' => $yearlyGrand,  'canvas' => 'yearlyPie'],
            ];
            foreach ($periods as $period):
            ?>
            <div class="hub-pie-panel">
                <div class="hub-pie-panel-title"><?= htmlspecialchars($period['title']) ?></div>
                <div class="hub-pie-panel-sub"><?= htmlspecialchars($period['sub']) ?></div>
                <div class="hub-pie-wrap"><canvas id="<?= $period['canvas'] ?>"></canvas></div>
                <div class="hub-pie-grand">RM<?= number_format($period['grand'], 2) ?></div>
                <table class="hub-table">
                    <thead><tr><th>Channel</th><th>Sales</th><th>%</th></tr></thead>
                    <tbody>
                    <?php if (!$period['channels']): ?>
                        <tr><td colspan="3" class="empty-row">No active sales channels found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($period['channels'] as $channel): ?>
                            <tr>
                                <td>
                                    <span class="hub-name-cell">
                                        <span class="hub-dot" style="background:<?= htmlspecialchars($channel['color']) ?>"></span>
                                        <?= htmlspecialchars($channel['channel_code']) ?>
                                    </span>
                                </td>
                                <td class="num">RM<?= number_format($channel['total_sales'], 2) ?></td>
                                <td class="num"><?= number_format($channel['pct'], 2) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="num">RM<?= number_format($period['grand'], 2) ?></td>
                            <td class="num"><?= $period['grand'] > 0 ? '100.00' : '0.00' ?>%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="comparison-table-wrap">
            <div class="comparison-table-title">Monthly MTD Comparison <span>(<?= htmlspecialchars($monthRangeLabel) ?> vs <?= htmlspecialchars($prevMonthRangeLabel) ?>)</span></div>
            <div class="comparison-table-scroll">
                <table class="comparison-table">
                    <thead><tr><th>Channel</th><th>Current MTD</th><th>Last Month MTD</th><th>Difference (RM)</th><th>Difference (%)</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthlyComparison as $comparison): ?>
                        <tr>
                            <td><span class="hub-name-cell"><span class="hub-dot" style="background:<?= htmlspecialchars($comparison['color']) ?>"></span><?= htmlspecialchars($comparison['channel_code']) ?></span></td>
                            <td class="num">RM<?= number_format($comparison['current'], 2) ?></td>
                            <td class="num">RM<?= number_format($comparison['previous'], 2) ?></td>
                            <td class="num <?= $comparison['difference'] > 0 ? 'diff-positive' : ($comparison['difference'] < 0 ? 'diff-negative' : 'diff-neutral') ?>"><?= ($comparison['difference'] > 0 ? '+' : '') . number_format($comparison['difference'], 2) ?></td>
                            <td class="num <?= $comparison['change'] === null ? 'change-neutral' : ($comparison['change'] >= 0 ? 'change-positive' : 'change-negative') ?>"><?= $comparison['change'] === null ? 'N/A' : (($comparison['change'] > 0 ? '+' : '') . number_format($comparison['change'], 2) . '%') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="num">RM<?= number_format($monthlyComparisonTotals['current'], 2) ?></td>
                            <td class="num">RM<?= number_format($monthlyComparisonTotals['previous'], 2) ?></td>
                            <td class="num <?= $monthlyComparisonTotals['difference'] > 0 ? 'diff-positive' : ($monthlyComparisonTotals['difference'] < 0 ? 'diff-negative' : 'diff-neutral') ?>"><?= ($monthlyComparisonTotals['difference'] > 0 ? '+' : '') . number_format($monthlyComparisonTotals['difference'], 2) ?></td>
                            <td class="num <?= $monthlyComparisonTotals['change'] === null ? 'change-neutral' : ($monthlyComparisonTotals['change'] >= 0 ? 'change-positive' : 'change-negative') ?>"><?= $monthlyComparisonTotals['change'] === null ? 'N/A' : (($monthlyComparisonTotals['change'] > 0 ? '+' : '') . number_format($monthlyComparisonTotals['change'], 2) . '%') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="comparison-table-wrap">
            <div class="comparison-table-title">Yearly YTD Comparison <span>(<?= htmlspecialchars($yearRangeLabel) ?> vs <?= htmlspecialchars($prevYearRangeLabel) ?>)</span></div>
            <div class="comparison-table-scroll">
                <table class="comparison-table">
                    <thead><tr><th>Channel</th><th>Current YTD</th><th>Last Year YTD</th><th>Difference (RM)</th><th>Difference (%)</th></tr></thead>
                    <tbody>
                    <?php foreach ($yearlyComparison as $comparison): ?>
                        <tr>
                            <td><span class="hub-name-cell"><span class="hub-dot" style="background:<?= htmlspecialchars($comparison['color']) ?>"></span><?= htmlspecialchars($comparison['channel_code']) ?></span></td>
                            <td class="num">RM<?= number_format($comparison['current'], 2) ?></td>
                            <td class="num">RM<?= number_format($comparison['previous'], 2) ?></td>
                            <td class="num <?= $comparison['difference'] > 0 ? 'diff-positive' : ($comparison['difference'] < 0 ? 'diff-negative' : 'diff-neutral') ?>"><?= ($comparison['difference'] > 0 ? '+' : '') . number_format($comparison['difference'], 2) ?></td>
                            <td class="num <?= $comparison['change'] === null ? 'change-neutral' : ($comparison['change'] >= 0 ? 'change-positive' : 'change-negative') ?>"><?= $comparison['change'] === null ? 'N/A' : (($comparison['change'] > 0 ? '+' : '') . number_format($comparison['change'], 2) . '%') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="num">RM<?= number_format($yearlyComparisonTotals['current'], 2) ?></td>
                            <td class="num">RM<?= number_format($yearlyComparisonTotals['previous'], 2) ?></td>
                            <td class="num <?= $yearlyComparisonTotals['difference'] > 0 ? 'diff-positive' : ($yearlyComparisonTotals['difference'] < 0 ? 'diff-negative' : 'diff-neutral') ?>"><?= ($yearlyComparisonTotals['difference'] > 0 ? '+' : '') . number_format($yearlyComparisonTotals['difference'], 2) ?></td>
                            <td class="num <?= $yearlyComparisonTotals['change'] === null ? 'change-neutral' : ($yearlyComparisonTotals['change'] >= 0 ? 'change-positive' : 'change-negative') ?>"><?= $yearlyComparisonTotals['change'] === null ? 'N/A' : (($yearlyComparisonTotals['change'] > 0 ? '+' : '') . number_format($yearlyComparisonTotals['change'], 2) . '%') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
</main>
</div>
<script>
document.addEventListener('submit', async function (event) {
    const form = event.target.closest('form[method="get"]');
    if (!form) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;

    try {
        const params = new URLSearchParams(new FormData(form));
        const response = await fetch(window.location.pathname + '?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        if (!response.ok) throw new Error('HTTP ' + response.status);

        const html = await response.text();
        const nextDocument = new DOMParser().parseFromString(html, 'text/html');
        const nextMain = nextDocument.querySelector('main.main');
        const currentMain = document.querySelector('main.main');
        if (!nextMain || !currentMain) throw new Error('Invalid report response.');

        currentMain.replaceWith(nextMain);
        document.title = nextDocument.title || document.title;

        const scripts = nextDocument.body.querySelectorAll('script');
        const reportScript = scripts[scripts.length - 1];
        if (reportScript && reportScript.textContent) {
            new Function(reportScript.textContent)();
        }
    } catch (error) {
        if (button) button.disabled = false;
        window.alert('Unable to update the report. Please try again.');
    }
}, true);

if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.color = '#8A8A93';
}
if (typeof ChartDataLabels !== 'undefined' && typeof Chart !== 'undefined') {
    Chart.register(ChartDataLabels);
}

function formatRM(n) {
    return 'RM ' + Number(n || 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderChannelRing(canvasId, labels, values, colors) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    if (!values.length || values.every(v => Number(v) === 0)) {
        canvas.closest('.hub-pie-wrap').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#8A8A93;font-size:13px;font-weight:600;">No data available</div>';
        return;
    }
    new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: values, backgroundColor: colors, borderColor: '#fff', borderWidth: 2 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 9, boxHeight: 9, font: { family: "'Plus Jakarta Sans'", weight: '600', size: 11 }, padding: 10 } },
                tooltip: {
                    backgroundColor: '#1B1B1F', padding: 10, cornerRadius: 8,
                    callbacks: { label: ctx => `${ctx.label}: ${formatRM(ctx.parsed)}` }
                },
                datalabels: {
                    color: '#fff', font: { family: "'Plus Jakarta Sans'", weight: '700', size: 11 },
                    formatter: (value, ctx) => {
                        const total = ctx.chart.data.datasets[0].data.reduce((a, b) => a + Number(b), 0);
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

const dailyChannels = <?= json_encode($dailyChannels, JSON_UNESCAPED_SLASHES) ?>;
const monthlyChannels = <?= json_encode($monthlyChannels, JSON_UNESCAPED_SLASHES) ?>;
const yearlyChannels = <?= json_encode($yearlyChannels, JSON_UNESCAPED_SLASHES) ?>;

function ring(data, canvasId) {
    renderChannelRing(
        canvasId,
        data.map(c => c.channel_code),
        data.map(c => c.total_sales),
        data.map(c => c.color)
    );
}

ring(dailyChannels, 'dailyPie');
ring(monthlyChannels, 'monthlyPie');
ring(yearlyChannels, 'yearlyPie');

function bindAjaxReportForm(scope) {
    scope.querySelectorAll('form[method="get"]').forEach(function (form) {
        if (form.dataset.ajaxBound === '1') return;
        form.dataset.ajaxBound = '1';
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            event.stopPropagation();

            const button = form.querySelector('button[type="submit"]');
            if (button) button.disabled = true;

            try {
                const params = new URLSearchParams(new FormData(form));
                const response = await fetch(window.location.pathname + '?' + params.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);

                const html = await response.text();
                const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                const nextMain = nextDocument.querySelector('main.main');
                const currentMain = document.querySelector('main.main');
                if (!nextMain || !currentMain) throw new Error('Invalid report response.');

                currentMain.replaceWith(nextMain);
                document.title = nextDocument.title || document.title;
                bindAjaxReportForm(nextMain);

                const scripts = nextDocument.body.querySelectorAll('script');
                const reportScript = scripts[scripts.length - 1];
                if (reportScript && reportScript.textContent) {
                    new Function(reportScript.textContent)();
                }
            } catch (error) {
                if (button) button.disabled = false;
                window.alert('Unable to update the report. Please try again.');
            }
        });
    });
}

bindAjaxReportForm(document);
</script>
</body>
</html>