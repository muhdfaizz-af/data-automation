<?php

session_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

// Define SG rate
define('SGD_TO_MYR_RATE', 3.27);

define('PURCHASE_ORDER_TYPES', [
    'Repurchase Order',
    'On Behalf Repurchase Order',
]);

if (empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

// Release the session lock before report queries so new requests can proceed.
session_write_close();

// DB connection
function getDBConnection(): ?PDO

{
    try {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            ),
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (Throwable $exception) {
        error_log($exception->getMessage());

        return null;
    }
} 

// Check date validation
function validDate(string $date): bool 
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && 
        $parsed->format('Y-m-d') === $date;
}

// Converts number into display text
function formatMoney(float $amount): string 
{
    return 'RM' . number_format($amount, 2);
}

function convertSalesToMyr(string $companyCode, float $sales): float 
{
    return strtoupper(trim($companyCode)) === 'SG'
    ? $sales * SGD_TO_MYR_RATE
    : $sales;
}

// Define SPC
function isSpcMemberType(string $memberType): bool
{
    return in_array(strtoupper(trim($memberType)), ['PRIVILEGE MEMBER', 'SPC'], true);
}

// Read report date
$defaultDate = date('Y-m-d', strtotime('-1 day'));

$fromDate = $_GET['from_date'] ?? $defaultDate;
$toDate = $_GET['to_date'] ?? $defaultDate;

if (!is_string($fromDate) || !validDate($fromDate)) {
    $fromDate = $defaultDate;
}

if (!is_string($toDate) || !validDate($toDate)) {
    $toDate = $defaultDate;
}

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$monthlyFrom = (new DateTimeImmutable($toDate))
    ->modify('first day of this month')
    ->format('Y-m-d');

// Main calculation receives the database connection, dates, and mode
function getAgentBehaviourReport(
    PDO $pdo,
    string $from,
    string $to
): array {
    $params = [
        'from_date' => $from . ' 00:00:00',
        'to_exclusive' => (new DateTimeImmutable($to))
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00'),
        'status' => 'Confirmed',
        'member_type' => 'Distributor',
        'upgrade_from' => (new DateTimeImmutable($from))
            ->modify('first day of this month')->format('Y-m-d 00:00:00'),
        'upgrade_to' => (new DateTimeImmutable($to))
            ->modify('first day of next month')->format('Y-m-d 00:00:00'),
    ];

    /*
     * This query loads qualifying Distributor and SPC repurchase orders.
     *
     * Only repurchase subtotals contribute to sales. New Agent is determined
     * from joining or a confirmed upgrade in the order's calendar month. 
     */
    $sql = "
        SELECT
        /* Retrieve order id, member information, order type, and company code */
            o.id,
            o.company_id,
            o.order_id,
            o.order_datetime,
            TRIM(o.member_code) AS member_code,
            o.member_name,
            o.member_type,
            o.order_type,
            c.company_code,

        /* Determine join member date */
            CASE WHEN m.joined_date IS NOT NULL THEN m.joined_date
                 ELSE fallback_member.joined_date END AS joined_date,
        
        /* Retrieve member highest rank */
            CASE WHEN m.id IS NOT NULL THEN m.highest_rank
                 ELSE fallback_member.highest_rank END AS highest_rank,
        /* Determine if member upgrade in the same month, and determine new agent category */
            (upgrades.member_code IS NOT NULL) AS upgraded_in_month,

        /* SG conversion */
            CASE
                WHEN c.company_code = 'SG'
                    THEN COALESCE(o.sub_total, 0) * " . SGD_TO_MYR_RATE . "
                ELSE COALESCE(o.sub_total, 0)
            END AS sales_myr

        FROM orders o

        /* Retrieve company id from orders */
        INNER JOIN companies c
            ON c.id = o.company_id

        /* Lists of members with SPC upgrade with order status Confirmed */
        LEFT JOIN (
            SELECT DISTINCT company_id, TRIM(member_code) AS member_code,
                   DATE_FORMAT(order_datetime, '%Y-%m') AS upgrade_month
            FROM orders
            WHERE order_type = 'SPC Upgrade Order'
              AND order_status = 'Confirmed'
              AND order_datetime >= :upgrade_from
              AND order_datetime < :upgrade_to
        ) upgrades
        
        /* Match list of upgrade and order */
            ON upgrades.company_id = o.company_id
           AND upgrades.member_code = TRIM(o.member_code)

        /* The upgrade and purchase must be in the same year and month. */
           AND upgrades.upgrade_month = DATE_FORMAT(o.order_datetime, '%Y-%m')

        /* Find the member profile belonging to the order's company. LEFT JOIN keeps the order even when the profile is missing. */
        LEFT JOIN members m
            ON m.company_id = o.company_id
           AND m.member_code = TRIM(o.member_code)

        /* Missing local joining dates may use exactly one dated member profile. */
        LEFT JOIN (
            SELECT member_code, MAX(joined_date) AS joined_date,
                   MAX(highest_rank) AS highest_rank
            FROM members
            WHERE joined_date IS NOT NULL
            GROUP BY member_code
            HAVING COUNT(*) = 1
        ) fallback_member
            ON m.joined_date IS NULL
           AND fallback_member.member_code = TRIM(o.member_code)

        WHERE o.order_datetime >= :from_date
          AND o.order_datetime < :to_exclusive
          AND o.order_status = :status

        /* If any purchase means every Confirmed SPC order. */
          AND UPPER(TRIM(COALESCE(o.member_type, ''))) IN (
              UPPER(:member_type),
              'PRIVILEGE MEMBER',
              'SPC'
          )

          AND o.order_type IN (
              'Repurchase Order',
              'On Behalf Repurchase Order'
          )

          AND o.member_code IS NOT NULL
          AND TRIM(o.member_code) <> ''

        ORDER BY o.member_code, o.order_datetime, o.id
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $selectedRows = $statement->fetchAll();

    // Load repurchase history to identify each agent's first purchase day.
    $memberCodes = array_values(array_unique(
        array_column($selectedRows, 'member_code')
    ));

    if (empty($memberCodes)) {
        $emptyMetrics = [
            'existing_agent' => 0,
            'new_agent' => 0,
            'first_purchase' => 0,
            'spc' => 0,
            'total' => 0,
        ];

        return [
            'summary' => $emptyMetrics,
            'agentCounts' => $emptyMetrics,
            'asd' => $emptyMetrics,
            'firstPurchaseAgents' => [],
        ];
    }

    $memberPlaceholders = [];
    $historyParams = [
        'status' => $params['status'],
        'member_type' => $params['member_type'],
    ];

    foreach ($memberCodes as $index => $memberCode) {
        $parameter = 'member_' . $index;

        $memberPlaceholders[] = ':' . $parameter;
        $historyParams[$parameter] = $memberCode;
    }

    $memberInClause = implode(', ', $memberPlaceholders);

    $historySql = "
        SELECT
            o.company_id,
            TRIM(o.member_code) AS member_code,
            MIN(o.order_datetime) AS first_purchase_date

        FROM orders o

        WHERE o.order_status = :status
          AND UPPER(TRIM(COALESCE(o.member_type, ''))) =
              UPPER(:member_type)

          AND TRIM(o.member_code) IN ({$memberInClause})

          AND o.order_type IN (
              'Repurchase Order',
              'On Behalf Repurchase Order'
          )

        GROUP BY o.company_id, TRIM(o.member_code)
    ";
    
    $historyStatement = $pdo->prepare($historySql);
    $historyStatement->execute($historyParams);

    $historyRows = $historyStatement->fetchAll();

    $agentHistory = [];

    foreach ($historyRows as $row) {
        // Member codes are unique within a company, not across all companies.
        $memberCode = $row['company_id'] . ':' . $row['member_code'];

        $agentHistory[$memberCode] = [
            'first_purchase_date' => new DateTimeImmutable($row['first_purchase_date']),
        ];
    }

    $summary = [
        'existing_agent' => 0.00,
        'new_agent' => 0.00,
        'first_purchase' => 0.00,
        'spc' => 0.00,
        'total' => 0.00,
    ];

    $purchasingMembers = [
        'existing_agent' => [],
        'new_agent' => [],
        'first_purchase' => [],
        'spc' => [],
        'total' => [],
    ];

    $firstPurchaseAgents = [];

    foreach ($selectedRows as $row) {
        $memberCode = $row['member_code'];
        $agentKey = $row['company_id'] . ':' . $memberCode;

        // SPC purchases remain separate from all Distributor categories.
        if (isSpcMemberType((string)($row['member_type']?? ''))) {
            $sales = (float)$row['sales_myr'];

            $summary['spc'] += $sales;
            $summary['total'] += $sales;

            $purchasingMembers['spc'][$agentKey] = true;
            $purchasingMembers['total'][$agentKey] = true;

            continue;
        }

        $orderType = $row['order_type'];
        $orderDate = new DateTimeImmutable($row['order_datetime']);
        $history = $agentHistory[$agentKey] ?? null;

        if (!$history) {
            continue;
        }

        $sales = (float)$row['sales_myr'];
        $category = 'existing_agent';

        $joinDate = !empty($row['joined_date'])
            ? new DateTimeImmutable($row['joined_date'])
            : null;
        $firstPurchaseDate = $history['first_purchase_date'];

        // Each order uses its own month, including reports spanning several months.
        if (
            ($joinDate !== null && $joinDate->format('Y-m') === $orderDate->format('Y-m'))
            || (bool)$row['upgraded_in_month']
        ) {
            $category = 'new_agent';
        } elseif (
            in_array($orderType, PURCHASE_ORDER_TYPES, true) &&
            $firstPurchaseDate !== null &&
            $firstPurchaseDate->format('Y-m-d') ===
                $orderDate->format('Y-m-d')
        ) {
            $category = 'first_purchase';

            $firstPurchaseAgents[$agentKey] = [
                'member_code' => $memberCode,
                'member_name' => $row['member_name'] ?? '',
                'join_date' => $joinDate
                    ? $joinDate->format('Y-m-d')
                    : null,
                'first_purchase_date' => $firstPurchaseDate
                    ->format('Y-m-d'),

                'highest_rank' => $row['highest_rank'] ?? 'N/A',
            ];
        }

        $summary[$category] += $sales;
        $summary['total'] += $sales;
        $purchasingMembers[$category][$agentKey] = true;
        $purchasingMembers['total'][$agentKey] = true;
    }

    foreach ($summary as $key => $value) {
        $summary[$key] = round($value, 2);
    }

    $summary['total'] = round(
        $summary['existing_agent']
        + $summary['first_purchase']
        + $summary['new_agent']
        + $summary['spc'],
        2
    );

    $agentCounts = [];
    $asd = [];

    foreach ($purchasingMembers as $category => $members) {
        $agentCounts[$category] = count($members);

        $asd[$category] = $agentCounts[$category] > 0
            ? round($summary[$category] / $agentCounts[$category], 2)
            : 0.00;
    }

    return [
        'summary' => $summary,
        'agentCounts' => $agentCounts,
        'asd' => $asd,
        'firstPurchaseAgents' => array_values($firstPurchaseAgents),
    ];
}

// Run report and handle failures
$errors = [];

$reports = [
    'daily' => [
        'title' => 'Agent Behaviour Sales - Selected Period',
        'from' => $fromDate,
        'to' => $toDate,
        'data' => null,
    ],

    'monthly' => [
        'title' => 'Monthly Agent Behaviour Sales',
        'from' => $monthlyFrom,
        'to' => $toDate,
        'data' => null,
    ],
];

$pdo = getDBConnection();

if (!$pdo) {
    $errors[] = 'Unable to connect to the database.';
} else {
    foreach ($reports as $reportMode => $configuration) {
        try {
            // Initializes an empty report, and connects to the database
            $reports[$reportMode]['data'] = getAgentBehaviourReport(
                $pdo,
                $configuration['from'],
                $configuration['to']
            );
        } catch (Throwable $exception) {
            error_log(
                'Agent Behaviour ' . $reportMode . ' report failed: '
                . $exception->getMessage()
            );

            $errors[] = 'Unable to load the '
                . $reportMode
                . ' Agent Behaviour report.';
        }
    }
}

// Calculate percentages
function calculateContributions(array $summary, array $categoryKeys): array
{
    $units = array_fill_keys($categoryKeys, 0);
    $total = (float)$summary['total'];

    if ($total <= 0 || empty($categoryKeys)) {
        return $units;
    }

    $largestCategory = $categoryKeys[0];

    foreach ($categoryKeys as $key) {
        // Integer hundredths of a percent: 100.00% = 10000.
        $units[$key] = (int)round(
            ((float)$summary[$key] / $total) * 10000
        );

        if($summary[$key] > $summary[$largestCategory]) {
            $largestCategory = $key;
        }
    }

    // Reconcile displayed rounding to exactly 100.00%
    $units[$largestCategory] += 10000 - array_sum($units);


    return array_map(
        static fn ($value) => $value / 100,
        $units
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agent Behaviour &mdash; S ASIA SALES REPORT</title>
<link rel="icon" href="../images/icon-sasia.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --red:#E0202E;--red-dark:#8E1620;--red-soft:#fff0f0;
    --ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;
    --gray-300:#D8D8DE;--gray-100:#F2F2F4;--bg:#F5F5F7;--white:#FFFFFF;
    --radius-lg:18px;--radius-md:12px;
    --shadow-card:0 8px 24px rgba(30,30,40,.06);
    --sidebar-w:260px;--sidebar-w-collapsed:82px;--topbar-h:64px;
}
*,*::before,*::after {box-sizing:border-box;margin:0;padding:0;}

/* ── BODY ── */
body {min-height:100vh;background:var(--bg);color:var(--ink);font-family:'Plus Jakarta Sans',sans-serif;}
a {color:inherit;text-decoration:none;}

/* ── BUTTON ── */
button,input,select {font:inherit;}
.hamburger-btn {display:none;}

/* ── LAYOUT STYLING ── */
.layout {display:flex;margin-top:var(--topbar-h);}
.main {min-width:0;flex:1;margin-left:var(--sidebar-w);padding:28px 32px 48px;transition:margin-left .25s ease;}

/* ── SIDEBAR & PAGE HEADER ── */
body.sidebar-collapsed .main {margin-left:var(--sidebar-w-collapsed);}
.page-header {margin-bottom:24px;}
.page-header h1 {margin-bottom:4px;font-size:1.5rem;font-weight:800;}
.page-header p {color:var(--gray-500);font-size:.875rem;}

/* ── CARD STYLING ── */
.card {margin-bottom:24px;padding:24px;border:1px solid var(--gray-100);border-radius:var(--radius-lg);background:var(--white);box-shadow:var(--shadow-card);}
.card-title {margin-bottom:4px;font-size:1rem;font-weight:800;}
.card-subtitle {margin-bottom:10px;color:var(--gray-500);font-size:.75rem;line-height:1.5;}

/* ── REPORT FILTER AND DATE PERIOD BOX STYLING ── */
.report-filter {margin-top:20px;}
.period-box {padding:18px;border:1px solid var(--gray-100);border-radius:var(--radius-md);background:var(--gray-100);}
.period-title {margin-bottom:14px;font-size:.875rem;font-weight:800;}
.date-grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
.field {display:flex;min-width:0;flex-direction:column;gap:6px;}
.field label {color:var(--gray-700);font-size:.6875rem;font-weight:800;letter-spacing:.35px;text-transform:uppercase;}
.field input,.field select {width:100%;min-width:0;padding:10px 11px;border:1.5px solid var(--gray-300);border-radius:9px;outline:none;background:var(--white);color:var(--ink);font-size:.8125rem;}
.field input:focus,.field select:focus {border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.10);}
.filter-footer {display:flex;align-items:center;justify-content:flex-end;gap:16px;margin-top:18px;}

/* ── APPLY BUTTON & ERROR BOX STYLING ── */
.apply-button {min-width:180px;min-height:42px;padding:10px 20px;border:0;border-radius:9px;background:var(--red);box-shadow:0 4px 14px rgba(224,32,46,.22);color:var(--white);cursor:pointer;font-size:.8125rem;font-weight:800;}
.apply-button:hover {background:var(--red-dark);}
.apply-button:disabled {opacity:.65;cursor:wait;}
.error-box {margin-bottom:20px;padding:13px 15px;border:1px solid #FECACA;border-radius:10px;background:var(--red-soft);color:#991B1B;font-size:.8125rem;font-weight:600;}

/* ── TABLE STYLING ── */
.table-wrap {overflow: hidden;border: 1px solid #E6E6EA;border-radius: 12px;background: var(--white);}
.brand-table {width: 100%;border-collapse: separate;border-spacing: 0;}
.brand-table th,.brand-table td {padding: 14px 16px;border-bottom: 1px solid #ECECF0;text-align: left;font-size: 0.75rem;vertical-align: middle;}
.brand-table th {background: #F7F7F9;color: var(--gray-700);font-size: 0.6875rem;font-weight: 800;letter-spacing: .3px;text-transform: uppercase;}
.brand-table tbody tr {transition: background-color .15s ease;}
.brand-table tbody tr:hover td {background: #FAFAFB;}
.brand-table tbody tr:last-child td {border-bottom: 0;}
.brand-table th:not(:first-child),.brand-table td:not(:first-child) {text-align: right;}
.brand-name {font-weight: 800;}
.brand-total {font-weight: 800;white-space: nowrap;}
.table-wrap {overflow-x: auto;}
.sales-table {min-width: 760px;}
.sales-table th, .sales-table td:not(:first-child) {white-space: nowrap;}

/*
.contribution-chart {margin: 18px 0 24px; padding: 18px 20px 16px; border: 1px solid var(--gray-100); border-radius: var(--radius-lg); background: var(--white); box-shadow: var(--shadow-card);}
.contribution-chart .card-title {margin-bottom: 2px; font-size: .875rem;}
.contribution-chart .card-subtitle {margin-bottom: 0; font-size: .6875rem;}
.contribution-chart-box {width: 100%; max-width: 850px; min-width: 0; margin: 14px auto 0;}
.contribution-chart-box svg {display: block; width: 100%; min-width: 0; height: auto;}
.contribution-chart-box svg text {font-family: inherit;}
.contribution-chart-empty {padding: 40px 0; color: var(--gray-500); font-size: .75rem; text-align: center;}
 */

/* KPI CARD STYLING */
.agent-kpi-grid {display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 24px;}
.agent-kpi-card {min-width: 0; padding: 18px 20px; border: 1px solid var(--gray-100); border-radius: 16px; background: var(--white); box-shadow: var(--shadow-card);}
.agent-kpi-header {display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px;}
.agent-kpi-label {color: var(--gray-700); font-size: .75rem; font-weight: 700;}
.agent-kpi-icon {display: inline-flex; flex: 0 0 38px; width: 38px; height: 38px; align-items: center; justify-content: center; border-radius: 10px; font-size: 18px;}
.agent-kpi-icon-sales {background: #FCE8EB;}
.agent-kpi-icon-agents {background: #F1EAFE;}
.agent-kpi-icon-asd {background: #FFF3E3;}
.agent-kpi-value {color: var(--ink); font-size: clamp(1.15rem, 1.6vw, 1.5rem); font-weight: 800; line-height: 1.3; font-variant-numeric: tabular-nums; overflow-wrap: anywhere;}
.agent-kpi-note {margin-top: 6px; color: var(--gray-500); font-size: .6875rem; line-height: 1.5;}
.agent-kpi-period {margin-bottom: 10px; color: var(--gray-700); font-size: .75rem;}
@media (max-width: 1100px) {.agent-kpi-grid {grid-template-columns: repeat(2, minmax(0, 1fr));}}
@media (max-width: 600px) {.agent-kpi-grid {grid-template-columns: 1fr;}}

/* ── PERCENTAGE INDICATOR ── */
.percentage-bar {display: inline-flex;width: 100%;max-width: 170px;align-items: center;justify-content: flex-end;gap: 9px;}
.percentage-track {width: 90px;height: 7px;overflow: hidden;border-radius: 20px;background: var(--gray-100);}
.percentage-fill {height: 100%;border-radius: 20px;background: var(--red);}
.total-row td {background: #F7F7F9 !important;font-weight: 800;}
@media (max-width:650px) {.table-wrap {overflow-x:auto;}.brand-table {min-width:650px;}}
@media (max-width:900px) {.main,body.sidebar-collapsed .main {margin-left:0;padding:20px;}}
@media (max-width:800px) {.filter-footer {flex-direction:column;align-items:stretch;}.apply-button {width:100%;}}
@media (max-width:500px) {.date-grid {grid-template-columns:1fr;}}
@media (max-width:500px) {.contribution-chart {padding: 14px 8px 12px;}}
</style>
</head>
<body>
    <?php
    $pageTitle = 'Agent Behaviour';
    $activeNav = 'performance_agent';
    $navBasePath = '../';
    $adminUsername = $_SESSION['admin_username'] ?? '';

    include __DIR__ . '/../includes/topnav.php';
    include __DIR__ . '/../includes/sidebar.php';
    ?>

    <div class="layout">
    <main class="main">
        <header class="page-header">
            <h1>Agent Behaviour</h1>

            <p>
                Confirmed eligible sales for Existing Agents,
                New Agents, First Purchase, and SPC members.
            </p>
        </header>

        <?php if (!empty($errors)): ?>
            <div class="error-box" role="alert">
                <?= htmlspecialchars(implode(' ', $errors)) ?>
            </div>
        <?php endif; ?>

        <section class="card">
            <h2 class="card-title">Report Filters</h2>

            <p class="card-subtitle">
                Select a date range for Agent Behaviour Sales.
                The monthly report runs from the first day of the
                ending month through To Date.
                New Agent sales include members who joined or upgraded in the
                same calendar month as each order.
            </p>

            <form method="get" class="report-filter">
                <div class="period-box">
                    <div class="period-title">Reporting Period</div>

                    <div class="date-grid">
                        <div class="field">
                            <label for="from_date">Order From Date</label>
                            <input type="date" id="from_date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" required>
                        </div>

                        <div class="field">
                            <label for="to_date">Order To Date</label>
                            <input type="date" id="to_date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" required>
                        </div>
                    </div>
                </div>

                <div class="filter-footer">
                    <button type="submit" class="apply-button">
                        Generate Reports
                    </button>
                </div>
            </form>
        </section>

        <?php
        $categoryLabels = [
            'existing_agent' => 'Existing Agent',
            'new_agent' => 'New Agent',
            'first_purchase' => 'First Purchase',
            'spc' => 'SPC',
        ];
        ?>

        <?php foreach ($reports as $reportMode => $configuration): ?>
            <section class="card">
                <h2 class="card-title">
                    <?= htmlspecialchars($configuration['title']) ?>
                </h2>

                <p class="card-subtitle">
                    <?= htmlspecialchars(
                        date('d M Y', strtotime($configuration['from']))
                    ) ?>
                    &ndash;
                    <?= htmlspecialchars(
                        date('d M Y', strtotime($configuration['to']))
                    ) ?>
                    &middot; Eligible Sales in MYR.
                    <br>
                    New Agent: joined or upgraded in the same month as the order.
                </p>

                <?php if ($configuration['data'] === null): ?>
                    <p class="card-subtitle">
                        Report data is unavailable.
                    </p>
                <?php else: ?>
                    <?php 
                        $summary = $configuration['data']['summary']; 
                        $agentCounts = $configuration['data']['agentCounts']; 
                        $asd = $configuration['data']['asd']; 
                        $contributions = calculateContributions($summary, array_keys($categoryLabels));
                    ?>

                    <?php 
                    /*<article class="contribution-chart">
                        <h3 class="card-title">
                            Sales Contribution % by Customer Type
                        </h3>

                        <p class="card-subtitle">
                            Share of eligible sales for this report period.
                        </p>

                        <?php if ($summary['total'] <= 0): ?>
                            <div class="contribution-chart-empty">
                                No positive sales total for this report period.
                            </div>
                            <?php else: ?>
                                <?php 
                                    $chartWidth = 720; $chartLeft = 140; $chartRight = 76; $chartTop = 18; $chartRowHeight = 52; $chartBarHeight = 22;
                                    $chartPlotWidth = $chartWidth - $chartLeft - $chartRight;
                                    $chartBottom = $chartTop + count($categoryLabels) * $chartRowHeight;
                                    $chartHeight = $chartBottom + 48;
                                    $chartTitleId = 'contribution-title-' . $reportMode; 
                                ?>

                                <div class="contribution-chart-box">
                                    <svg 
                                        viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" 
                                        xmlns="http://www.w3.org/2000/svg" 
                                        role="img" 
                                        aria-labelledby="<?= htmlspecialchars($chartTitleId) ?>"
                                    >

                                    <title 
                                        id="<?= htmlspecialchars($chartTitleId) ?>">
                                            Sales contribution by customer type:
                                        <?= htmlspecialchars($configuration['title']) ?>,
                                        <?= htmlspecialchars($configuration['from']) ?> to 
                                        <?= htmlspecialchars($configuration['to']) ?>
                                    </title>

                            <!-- Percentage gridlines and axis labels -->
                            <?php for ($tick = 0; $tick <= 100; $tick += 25): ?>
                                <?php $tickX = $chartLeft + ($tick / 100) * $chartPlotWidth; ?>
                                    <line 
                                        x1="<?= $tickX ?>"
                                        y1="<?= $chartTop ?>"
                                        x2="<?= $tickX ?>"
                                        y2="<?= $chartBottom ?>"
                                        stroke="#F0F0F3"
                                        stroke-width="1"
                                    />

                                    <text 
                                        x="<?= $tickX ?>"
                                        y="<?= $chartBottom + 20 ?>"
                                        text-anchor="middle"
                                        font-size="10"
                                        fill="#A0A0A8"
                                    >
                                    <?= $tick ?>%</text>
                                <?php endfor; ?>

                                <!-- One horizontal bar per customer type -->
                                 <?php $chartRowIndex = 0; ?>
                                 <?php foreach ($categoryLabels as $key => $label): ?>
                                    <?php $chartPercentage = (float)$contributions[$key];
                                          $chartCenterY = $chartTop + $chartRowIndex * $chartRowHeight + $chartRowHeight / 2;
                                          $chartBarWidth = (max(0, min(100, $chartPercentage)) / 100) * $chartPlotWidth;
                                          $chartValueX = $chartLeft + $chartBarWidth + 10;
                                    ?>
                                    <g>
                                        <title>
                                            <?= htmlspecialchars($label) ?>:
                                            <?= number_format($chartPercentage, 2) ?>% 
                                        </title>

                                        <text 
                                            x="<?= $chartLeft - 14 ?>"
                                            y="<?= $chartCenterY ?>"
                                            text-anchor="end"
                                            dominant-baseline="middle"
                                            font-size="11"
                                            font-weight="700"
                                            fill="#4A4A52"
                                        >
                                        <?= htmlspecialchars($label) ?></text>

                                    <?php if ($chartBarWidth > 0) : ?>
                                        <rect 
                                            x="<?= $chartLeft ?>"
                                            y="<?= $chartCenterY - $chartBarHeight / 2 ?>"
                                            width="<?= $chartBarWidth ?>"
                                            height="<?= $chartBarHeight ?>"
                                            fill="#E0202E"
                                            rx="3"
                                        />
                                    <?php endif; ?>
                                        <text
                                            x="<?= $chartValueX ?>"
                                            y="<?= $chartCenterY ?>"
                                            dominant-baseline="middle"
                                            font-size="11"
                                            font-weight="700"
                                            fill="#4A4A52"
                                        >
                                        <?= number_format($chartPercentage, 2) ?>%</text>
                                    </g>

                                    <?php $chartRowIndex++; ?>
                                    <?php endforeach; ?>

                                    <line
                                        x1="<?= $chartLeft ?>"
                                        y1="<?= $chartBottom ?>"
                                        x2="<?= $chartWidth - $chartRight ?>"
                                        y2="<?= $chartBottom ?>"
                                        stroke="#E2E2E8"
                                        stroke-width="1"
                                    />
                                </svg>
                            </div>
                        <?php endif; ?>
                    </article>
                    */ ?>

                    <?php
                    $kpiData = $configuration['data'];
                    $kpiAvailable = $kpiData !== null;
                    $kpiPeriodLabel = $reportMode === 'monthly' ? 'Monthly Period' : 'Selected Period';

                    $kpiPeriod = date('d M Y', strtotime($configuration['from']))
                        . ' – '
                        . date('d M Y', strtotime($configuration['to']));
                    ?>

                    <section aria-label="<?= htmlspecialchars($configuration['title']) ?> key performance indicators">
                        <p class="agent-kpi-period"><strong><?= htmlspecialchars($kpiPeriodLabel) ?>:</strong> <?= htmlspecialchars($kpiPeriod) ?></p>

                        <div class="agent-kpi-grid">
                            <article class="agent-kpi-card">
                                <div class="agent-kpi-header">
                                    <h2 class="agent-kpi-label">Total Sales</h2>
                                    <span class="agent-kpi-icon agent-kpi-icon-sales"aria-hidden="true">📈</span>
                                </div>

                                <div class="agent-kpi-value">
                                    <?= $kpiAvailable ? 'RM ' . number_format($kpiData['summary']['total'], 2) : 'Unavailable' ?>
                                </div>

                                <p class="agent-kpi-note">Eligible sales across all customer types.</p>
                            </article>

                            <article class="agent-kpi-card">
                                <div class="agent-kpi-header">
                                    <h2 class="agent-kpi-label">Total Purchasing Agents</h2>
                                    <span class="agent-kpi-icon agent-kpi-icon-agents" aria-hidden="true">👥</span>
                                </div>

                                <div class="agent-kpi-value">
                                    <?= $kpiAvailable ? number_format($kpiData['agentCounts']['total']) : 'Unavailable' ?>
                                </div>

                                <p class="agent-kpi-note">Unique purchasers, including SPC members.</p>
                            </article>

                            <article class="agent-kpi-card">
                                <div class="agent-kpi-header">
                                    <h2 class="agent-kpi-label">Overall ASD</h2>
                                    <span class="agent-kpi-icon agent-kpi-icon-asd" aria-hidden="true" >🎯</span>
                                </div>

                                <div class="agent-kpi-value">
                                    <?= $kpiAvailable ? 'RM ' . number_format($kpiData['asd']['total'], 2) : 'Unavailable' ?>
                                </div>

                                <p class="agent-kpi-note">Total sales divided by unique purchasers.</p>
                            </article>
                        </div>
                    </section>

                    <div class="table-wrap">
                        <table class="brand-table sales-table">
                            <thead>
                                <tr>
                                    <th scope="col">Customer Type</th>
                                    <th scope="col">Sales (RM)</th>
                                    <th scope="col">Contribution %</th>
                                    <th scope="col">No. of Agents</th>
                                    <th scope="col">ASD(RM)</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($categoryLabels as $key => $label): ?>
                                    <?php
                                    $percentage = $contributions[$key];
                                    $barWidth = max(0, min(100, $percentage));
                                    ?>

                                    <tr>
                                        <td class="brand-name"><?= htmlspecialchars($label) ?></td>

                                        <td class="brand-total"><?= number_format($summary[$key], 2) ?></td>

                                        <td>
                                            <div class="percentage-bar">
                                                <div class="percentage-track" aria-hidden="true">
                                                    <div class="percentage-fill" style="width:<?= $barWidth ?>%"></div>
                                                </div>
                                                <span><?= number_format($percentage, 2) ?>%</span>
                                            </div>
                                        </td>

                                        <td><?= number_format($agentCounts[$key]) ?></td>

                                        <td class="brand-total"><?= number_format($asd[$key], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>

                                <tr class="total-row">
                                    <td>Total</td>

                                    <td class="brand-total"><?= number_format($summary['total'], 2) ?></td>

                                    <td><?= $summary['total'] > 0 ? '100.00%' : '0.00%' ?></td>

                                    <td><?= number_format($agentCounts['total']) ?></td>

                                    <td class="brand-total"><?= number_format($asd['total'], 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="card-subtitle">
                        No. of Agents counts unique purchasing members within each customer type;
                        SPC counts purchasing SPC members.
                        Total counts each purchaser once across all customer types.
                        ASD = Sales / unique purchasing members.
                        Contributions are adjusted for rounding to total 100% when total sales
                        are positive.
                    </p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>
    </div>
        <script>
        (function () {
            'use strict';

            let activeRequest = null;

            function bindAjaxForm(scope) {
                const form = scope.querySelector('form.report-filter');

                if(!form || form.dataset.ajaxBound === '1') return;

                form.dataset.ajaxBound = '1';

                form.addEventListener('submit', async function (event) {
                    event.preventDefault();

                    if (!form.reportValidity()) return;

                    // Cancel the previous client request, if one is still pending.
                    if (activeRequest) activeRequest.abort();

                    const controller = new AbortController();
                    activeRequest = controller;

                    const currentMain = form.closest('main.main');
                    const button = form.querySelector('button[type="submit"]');

                    if (!currentMain) {
                        activeRequest = null;
                        return;
                    }

                    const originalText = button ? button.dataset.idleText || button.textContent : '';

                    if (button) {
                        button.dataset.idleText = originalText;

                        button.disabled = true;
                        button.textContent = 'Loading...';
                    }


                    currentMain.setAttribute('aria-busy', 'true');
                    currentMain.querySelector('.ajax-error-box')?.remove();

                    // Dates go into the fetch request, not the browser address bar.

                    const url = new URL(form.action || window.location.href);
                    url.search = new URLSearchParams(new FormData(form)).toString(); url.hash = '';

                    try {
                        const response = await fetch(url.toString(), {
                            method: 'GET',
                            headers: {
                                Accept: 'text/html'
                            },
                            credentials: 'same-origin',
                            cache: 'no-store',
                            signal: controller.signal
                        });

                        // Handle an expired login session

                        if (response.redirected) {
                            if (activeRequest === controller)
                            {
                                window.location.assign(response.url);
                            }
                            return;
                        }

                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }

                        const html = await response.text();

                        // Prevent an older response from replacing newer results.
                        if (activeRequest !== controller) return;
                        
                        const nextDocument = new DOMParser().parseFromString(html, 'text/html');

                        const nextMain = nextDocument.querySelector('main.main');

                        if (!nextMain || !nextMain.querySelector('form.report-filter')) {
                            throw new Error('Invalid report response.');
                        }

                        currentMain.replaceWith(nextMain);

                        document.title = nextDocument.title || document.title;

                        // The new form needs its own submit listener.
                        bindAjaxForm(nextMain);


                        nextMain.setAttribute('tabindex', '-1');

                        nextMain.focus({ preventScroll: true});

                        document.dispatchEvent(new CustomEvent('report:updated'));
                    } catch (error) {
                        if (
                            error.name === 'AbortError' || activeRequest !== controller
                        ) {
                            return;
                        }

                        const errorBox = document.createElement('div');
                        errorBox.className = 'error-box ajax-error-box';
                        errorBox.setAttribute('role', 'alert');
                        errorBox.textContent = 'Unable to update the reports. Previous results are still displayed. Please try again.';

                        currentMain.prepend(errorBox);

                    } finally {
                        if (activeRequest === controller) {
                            activeRequest = null;

                            if (currentMain.isConnected)
                            {
                                currentMain.removeAttribute('aria-busy');
                            }

                            if (button && button.isConnected) 
                            {
                                button.disabled = false;
                                button.textContent = originalText;
                            }
                        }
                    }
                });
            }
            bindAjaxForm(document);
        })();
</script>
</body>
</html>