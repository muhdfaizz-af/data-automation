<?php
/**
 * Active Agent and ASD Comparison
 *
 * ASD = Qualifying Total Sales / Unique Active Agents
 *
 * Qualifying orders:
 * - Repurchase Order
 * - On Behalf Repurchase Order
 *
 * Active agent:
 * - A distinct, non-empty orders.member_code
 * - Must have at least one confirmed qualifying order
 *
 * Sales:
 * - Uses orders.sub_total
 * - Singapore sales are converted to MYR
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

// Defines the SG conversion rate and permitted order types
define('SGD_TO_MYR_RATE', 3.27);

// Fixed report rules. These order types are not selected by the user.
define('QUALIFYING_ORDER_TYPES', [
    'Repurchase Order',
    'On Behalf Repurchase Order',
]);

// Authentication guard, redirect to login if not authenticated
if (empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

// Idle timeout
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

$adminUsername = $_SESSION['admin_username'] ?? '';
$activeNav = 'asd_comparison';
$navBasePath = '../';

// Create the PDO database connection.
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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}

// Validate a date in Y-m-d format.
function isValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed !== false &&
        $parsed->format('Y-m-d') === $date;
}

// Validate a reporting period.
function validatePeriod(
    string $from,
    string $to,
    string $periodName
): array {
    if (!isValidDate($from) || !isValidDate($to)) {
        return [
            'valid' => false,
            'error' => $periodName . ' contains an invalid date.',
        ];
    }

    if ($from > $to) {
        return [
            'valid' => false,
            'error' => $periodName . ' start date cannot be later than its end date.',
        ];
    }

    $fromDate = new DateTime($from);
    $toDate = new DateTime($to);
    $days = (int)$fromDate->diff($toDate)->format('%a');

    if ($days > 731) {
        return [
            'valid' => false,
            'error' => $periodName . ' cannot be longer than 732 days.',
        ];
    }

    return [
        'valid' => true,
        'error' => '',
    ];
}

// Allow only supported company filters.
function normalizeCompany(string $company): string
{
    return in_array($company, ['all', 'MY', 'SG'], true)
        ? $company
        : 'all';
}

// Return ASD statistics for one reporting period.
function getAsdMetrics(
    PDO $pdo,
    string $from,
    string $to,
    string $companyFilter,
    array $orderTypes
): array {
    $params = [
        'from_date'     => $from . ' 00:00:00',
        'to_exclusive'  => (new DateTime($to))
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00'),
        'order_status'  => 'Confirmed',
    ];

    $orderTypePlaceholders = [];

    foreach ($orderTypes as $index => $orderType) {
        $parameterName = 'order_type_' . $index;

        $orderTypePlaceholders[] = ':' . $parameterName;
        $params[$parameterName] = $orderType;
    }

    $orderTypeInClause = implode(', ', $orderTypePlaceholders);

    $companyCondition = '';

    /*
     * Business country rules:
     * - Brunei: Order ID starts with MYB OR Member ID starts with BN.
     */
    if ($companyFilter === 'BN_COUNTRY') {
        $companyCondition = "
            AND c.company_code = 'MY'
            AND (
                UPPER(TRIM(COALESCE(o.order_id, ''))) LIKE 'MYB%'
                OR UPPER(TRIM(COALESCE(o.member_code, ''))) LIKE 'BN%'
            )
        ";
    } elseif ($companyFilter === 'MY_COUNTRY') {
        $companyCondition = "
            AND c.company_code = 'MY'
            AND NOT (
                UPPER(TRIM(COALESCE(o.order_id, ''))) LIKE 'MYB%'
                OR UPPER(TRIM(COALESCE(o.member_code, ''))) LIKE 'BN%'
            )
        ";
    } elseif ($companyFilter !== 'all') {
        $companyCondition = ' AND c.company_code = :company_code';
        $params['company_code'] = $companyFilter;
    }

    /*
     * The sales conversion is performed per order:
     *
     * MY order: sub_total
     * SG order: sub_total × 3.27
     *
     * COUNT(DISTINCT ...) ensures that an agent with multiple orders
     * is counted only once.
     */
    $sql = "
        SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN c.company_code = 'SG'
                            THEN o.sub_total * " . SGD_TO_MYR_RATE . "
                        ELSE o.sub_total
                    END
                ),
                0
            ) AS total_sales,

            COUNT(
                DISTINCT CASE
                    WHEN o.member_code IS NOT NULL
                         AND TRIM(o.member_code) <> ''
                    THEN TRIM(o.member_code)
                    ELSE NULL
                END
            ) AS active_agents,

            COUNT(*) AS qualifying_orders

        FROM orders o
        INNER JOIN companies c
            ON c.id = o.company_id

        WHERE o.order_datetime >= :from_date
          AND o.order_datetime < :to_exclusive
          AND o.order_type IN ({$orderTypeInClause})
          AND o.order_status = :order_status
          {$companyCondition}
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $row = $statement->fetch();

    // Formula involved
    $totalSales = round((float)($row['total_sales'] ?? 0), 2);
    $activeAgents = (int)($row['active_agents'] ?? 0);
    $qualifyingOrders = (int)($row['qualifying_orders'] ?? 0);

    $asd = $activeAgents > 0
        // ASD = Qualifying Total Sales ÷ Unique Active Agents
        ? round($totalSales / $activeAgents, 2)
        : 0.00;

    return [
        'total_sales'       => $totalSales,
        'active_agents'     => $activeAgents,
        'qualifying_orders' => $qualifyingOrders,
        'asd'               => $asd,
    ];
}

// Calculate percentage change from Last Month to Current Month.
function percentageChange(float $periodA, float $periodB): ?float
{
    if ($periodA == 0.0) {
        return null;
    }

    // Percentage Change = ((Current Month − Last Month) ÷ |Last Month|) × 100
    return round((($periodB - $periodA) / abs($periodA)) * 100, 2);
}

// Format a percentage change for display.
function formatChange(?float $change): string
{
    if ($change === null) {
        return 'N/A';
    }

    $prefix = $change > 0 ? '+' : '';

    return $prefix . number_format($change, 2) . '%';
}

function formatMetricDifference(
    float $previousValue,
    float $currentValue,
    bool $isCurrency = false,
    int $decimals = 2
): string {
    $difference = $currentValue - $previousValue;

    if ($isCurrency) {
        $absoluteDifference =
            ($difference < 0 ? '-RM' : 'RM') .
            number_format(abs($difference), $decimals);
    } else {
        $absoluteDifference =
            ($difference > 0 ? '+' : ($difference < 0 ? '-' : '')) .
            number_format(abs($difference), $decimals);
    }

    $percentage = percentageChange(
        $previousValue,
        $currentValue
    );

    return $absoluteDifference .
        ' (' .
        formatChange($percentage) .
        ')';
}

// Determine the CSS class for a change value.
function changeClass(?float $change): string
{
    if ($change === null || $change === 0.0) {
        return 'neutral';
    }

    return $change > 0 ? 'positive' : 'negative';
}

/**
 * Default comparison periods:
 *
 * Last Month:
 * - First day of the previous month
 * - Same elapsed day range as the current month
 *
 * Current Month:
 * - First day of the current month
 * - Yesterday
 */
$today = new DateTimeImmutable('today');
$currentMonthStart = $today->modify('first day of this month');
$yesterday = $today->modify('-1 day');

$lastMonthStart = $today->modify('first day of last month');

/*
 * On the first day of a month, yesterday belongs to the previous month.
 * Use today temporarily to prevent the Current Month start date from
 * being later than its end date.
 */
$currentPeriodEnd = $yesterday < $currentMonthStart
    ? $today
    : $yesterday;

/*
 * Give Last Month the same number of elapsed calendar days.
 * The minimum protects shorter months, such as February.
 */
$matchingDay = min(
    (int)$currentPeriodEnd->format('j'),
    (int)$lastMonthStart->format('t')
);

$lastMonthEnd = $lastMonthStart->modify(
    '+' . ($matchingDay - 1) . ' days'
);

$defaultPeriodAFrom = $lastMonthStart->format('Y-m-d');
$defaultPeriodATo = $lastMonthEnd->format('Y-m-d');
$defaultPeriodBFrom = $currentMonthStart->format('Y-m-d');
$defaultPeriodBTo = $currentPeriodEnd->format('Y-m-d');

$periodAFrom = $_GET['period_a_from'] ?? $defaultPeriodAFrom;
$periodATo = $_GET['period_a_to'] ?? $defaultPeriodATo;
$periodBFrom = $_GET['period_b_from'] ?? $defaultPeriodBFrom;
$periodBTo = $_GET['period_b_to'] ?? $defaultPeriodBTo;
$companyFilter = normalizeCompany($_GET['company'] ?? 'all');

$selectedOrderTypes = QUALIFYING_ORDER_TYPES;

$errors = [];

$periodAValidation = validatePeriod(
    $periodAFrom,
    $periodATo,
    'Last Month'
);

$periodBValidation = validatePeriod(
    $periodBFrom,
    $periodBTo,
    'Current Month'
);

if (!$periodAValidation['valid']) {
    $errors[] = $periodAValidation['error'];
}

if (!$periodBValidation['valid']) {
    $errors[] = $periodBValidation['error'];
}

$emptyMetrics = [
    'total_sales'       => 0.00,
    'active_agents'     => 0,
    'qualifying_orders' => 0,
    'asd'               => 0.00,
];

$periodA = $emptyMetrics;
$periodB = $emptyMetrics;

// Company codes displayed in the country calculation breakdown.
$breakdownCountries = [
    'overall' => ['label' => 'Overall', 'filter' => 'all'],
    'malaysia' => ['label' => 'Malaysia', 'filter' => 'MY_COUNTRY'],
    'brunei' => ['label' => 'Brunei', 'filter' => 'BN_COUNTRY'],
    'singapore' => ['label' => 'Singapore', 'filter' => 'SG'],
];

$countryBreakdown = [];

$pdo = getDBConnection();

if (!$pdo) {
    $errors[] = 'Unable to connect to the database.';
}

if (empty($errors) && $pdo) {
    try {
        $periodA = getAsdMetrics(
            $pdo,
            $periodAFrom,
            $periodATo,
            $companyFilter,
            $selectedOrderTypes
        );

        $periodB = getAsdMetrics(
            $pdo,
            $periodBFrom,
            $periodBTo,
            $companyFilter,
            $selectedOrderTypes
        );

        foreach ($breakdownCountries as $countryKey => $country) {
            $countryPeriodA = getAsdMetrics(
                $pdo,
                $periodAFrom,
                $periodATo,
                $country['filter'],
                QUALIFYING_ORDER_TYPES
            );

            $countryPeriodB = getAsdMetrics(
                $pdo,
                $periodBFrom,
                $periodBTo,
                $country['filter'],
                QUALIFYING_ORDER_TYPES
            );

            $countryBreakdown[$countryKey] = [
                'label' => $country['label'],
                'period_a' => $countryPeriodA,
                'period_b' => $countryPeriodB,
                'agent_change' => percentageChange(
                    (float)$countryPeriodA['active_agents'],
                    (float)$countryPeriodB['active_agents']
                ),
                'asd_change' => percentageChange(
                    $countryPeriodA['asd'],
                    $countryPeriodB['asd']
                ),
            ];
        }
    } catch (Throwable $e) {
        error_log('ASD report query failed: ' . $e->getMessage());
        $errors[] = 'Unable to load the ASD report data.';
    }
}

$salesChange = percentageChange(
    $periodA['total_sales'],
    $periodB['total_sales']
);

$agentsChange = percentageChange(
    (float)$periodA['active_agents'],
    (float)$periodB['active_agents']
);

$ordersChange = percentageChange(
    (float)$periodA['qualifying_orders'],
    (float)$periodB['qualifying_orders']
);

$asdChange = percentageChange(
    $periodA['asd'],
    $periodB['asd']
);

$periodALabel = date('d M Y', strtotime($periodAFrom)) .
    ' – ' .
    date('d M Y', strtotime($periodATo));

$periodBLabel = date('d M Y', strtotime($periodBFrom)) .
    ' – ' .
    date('d M Y', strtotime($periodBTo));

$periodAMonthName = strtoupper(
    date('F', strtotime($periodAFrom))
);

$periodBMonthName = strtoupper(
    date('F', strtotime($periodBFrom))
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Active Agent &amp; ASD — S ASIA SALES REPORT</title>
<link rel="icon" href="../images/icon-sasia.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --red: #E0202E;--red-dark: #8E1620;
    --ink: #1B1B1F;--gray-700: #4A4A52;--gray-500: #8A8A93;
    --gray-300: #D8D8DE;--gray-100: #F2F2F4;--bg: #F5F5F7;
    --white: #FFFFFF;
    --radius-lg: 18px;--radius-md: 12px;
    --shadow-card: 0 8px 24px rgba(30, 30, 40, .06);--sidebar-w: 260px;
    --sidebar-w-collapsed: 82px;--topbar-h: 64px;
}
*,*::before,*::after {box-sizing: border-box;margin: 0;padding: 0;}
body {min-height: 100vh;background: var(--bg);color: var(--ink);font-family: 'Plus Jakarta Sans', sans-serif;}
a {color: inherit;text-decoration: none;}
button,input,select {font: inherit;}

/* ── LAYOUT ── */
.layout {display: flex;margin-top: var(--topbar-h);}
.main {min-width: 0;flex: 1;margin-left: var(--sidebar-w);padding: 28px 32px 48px;transition: margin-left .25s ease;}
body.sidebar-collapsed .main {margin-left: var(--sidebar-w-collapsed);}

/* ── PAGE HEADER ── */
.page-header {margin-bottom: 24px;}
.page-header h1 {margin-bottom: 4px;font-size: 24px;font-weight: 800;}
.page-header p {color: var(--gray-500);font-size: 13.5px;}
.card {margin-bottom: 24px;padding: 24px;border: 1px solid var(--gray-100);border-radius: var(--radius-lg);background: var(--white);box-shadow: var(--shadow-card);}
.card-title {margin-bottom: 4px;font-size: 16px;font-weight: 800;}
.card-subtitle {color: var(--gray-500);font-size: 12px; margin-bottom: 10px;}
.form-grid {display: grid;grid-template-columns: 1fr 1fr 190px auto;gap: 18px;align-items: end;margin-top: 20px;}
.period-box {padding: 16px;border-radius: var(--radius-md);background: var(--gray-100);}
.period-title {margin-bottom: 12px;font-size: 13px;font-weight: 800;}
.date-grid {display: grid;grid-template-columns: 1fr 1fr;gap: 10px;}
.field {display: flex;flex-direction: column;gap: 6px;}
.field label {color: var(--gray-700);font-size: 10.5px;font-weight: 800;letter-spacing: .35px;text-transform: uppercase;}
.field input,
.field select {width: 100%;padding: 10px 11px;border: 1.5px solid var(--gray-300);border-radius: 9px;outline: none;background: var(--white);color: var(--ink);font-size: 13px;}
.field input:focus,.field select:focus {border-color: var(--red);box-shadow: 0 0 0 3px rgba(224, 32, 46, .10);}
.apply-button {min-height: 42px;padding: 10px 20px;border: 0;border-radius: 9px;background: var(--red);box-shadow: 0 4px 14px rgba(224, 32, 46, .22);color: var(--white);cursor: pointer;font-size: 13px;font-weight: 800;}
.apply-button:hover {background: var(--red-dark);}
.error-box {margin-bottom: 20px;padding: 13px 15px;border: 1px solid #FECACA;border-radius: 10px;background: var(--red-soft);color: #991B1B;font-size: 13px;font-weight: 600;}
.error-box ul {padding-left: 18px;}
.definition {display: flex;gap: 12px;align-items: center;margin-bottom: 24px;padding: 15px 18px;border-radius: 10px;background: var(--white);box-shadow: var(--shadow-card);color: var(--gray-700);font-size: 13px;}
.definition strong {color: var(--ink);}

/* ── PERIOD STYLING ── */
.period-heading-grid {display: grid;grid-template-columns: 1fr 1fr;gap: 20px;margin-bottom: 16px;}
.period-heading {padding: 15px 18px;border-radius: var(--radius-md);background: var(--white);box-shadow: var(--shadow-card);}
.period-heading-name {margin-bottom: 4px;font-size: 14px;font-weight: 800;}
.period-heading-date {color: var(--gray-500);font-size: 12px;font-weight: 600;}
.metric-grid {display: grid;grid-template-columns: repeat(3, minmax(0, 1fr));gap: 18px;}
.metric-card {min-width: 0;padding: 19px;border: 1px solid var(--gray-100);border-radius: var(--radius-lg);background: var(--white);box-shadow: var(--shadow-card);margin-bottom: 20px;}
.metric-name {margin-bottom: 14px;color: var(--gray-500);font-size: 10px;font-weight: 800;letter-spacing: .4px;text-transform: uppercase;}
.metric-values {display: grid;grid-template-columns: repeat(2, minmax(0, 1fr));gap: 16px;}
.metric-period {font-size: 10px;font-weight: 700;color: var(--black-500);}
.metric-value {max-width: 100%;font-size: 20;font-weight: 700;line-height: 1.25;overflow-wrap: anywhere;}
.metric-change {margin-top: 14px;padding-top: 12px;border-top: 1px solid var(--gray-100);font-size: 12px;font-weight: 800;}
.metric-change.positive {color: var(--green);}
.metric-change.negative {color: var(--red);}
.metric-change.neutral {color: var(--gray-500);}
.table-wrap {overflow-x: auto;}

/* ── COMPARISON TABLE ── */
.comparison-table {width: 100%;border-collapse: collapse;}
.comparison-table th,.comparison-table td {padding: 13px 15px;border-bottom: 1px solid var(--black-100);text-align: right;font-size: 13px;}
.comparison-table th:first-child,.comparison-table td:first-child {text-align: left;}
.comparison-table th {color: var(--black-500);font-size: 10.5px;letter-spacing: .35px;text-transform: uppercase;}
.comparison-table td {font-weight: 700;}
.note {margin-top: 16px;color: var(--black-500);font-size: 11.5px;line-height: 1.7;}
.order-type-option:hover {border-color: var(--red);}
.order-type-option input {width: 16px;height: 16px;accent-color: var(--red);}
.order-type-help {margin-top: 7px;color: var(--black-500);font-size: 11px;}
@media (max-width: 1100px) {.form-grid {grid-template-columns: 1fr 1fr;}}
@media (max-width: 900px) {.main,body.sidebar-collapsed .main {margin-left: 0;padding: 20px;}}
@media (max-width: 650px) {.form-grid,.period-heading-grid,.metric-grid {grid-template-columns: 1fr;}
.date-grid {grid-template-columns: 1fr;}}

/* ── ASD COMPARISON FILTER LAYOUT ── */
#asdFilterForm {margin-top:20px;}
.company-filter-row {display:flex;margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid var(--gray-100);}
.company-field {width:min(100%,360px);}
.company-field select {min-height:46px;font-size:14px;font-weight:700;}
.period-filter-grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-bottom:18px;}
.period-box {padding:18px;border:1px solid var(--gray-100);border-radius:var(--radius-md);background:var(--gray-100);}
.period-title {margin-bottom:14px;font-size:14px;font-weight:800;}
.date-grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}

/* ── ORDER TYPE FILTER ── */
.order-type-field {min-width:0;margin:0;padding:18px;border:1px solid var(--gray-300);border-radius:var(--radius-md);background:var(--white);}
.order-type-header {display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:16px;}
.order-type-header legend {margin-bottom:4px;color:var(--ink);font-size:13px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;}
.order-type-header p {color:var(--gray-500);font-size:11.5px;line-height:1.5;}
.order-type-groups {display:block;}
.order-type-group {display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:12px;padding:14px;border-radius:10px;background:var(--gray-100);}
.order-group-title {grid-column:1 / -1;margin-bottom:2px;color:var(--gray-700);font-size:11px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;}
.order-type-option {position:relative;display:flex;align-items:flex-start;gap:10px;min-height:62px;padding:11px 12px;border:1.5px solid var(--gray-300);border-radius:9px;background:var(--white);cursor:pointer;transition:border-color .15s,background .15s,box-shadow .15s;}
.order-type-option:hover {border-color:var(--red);}
.order-type-option:has(input:checked) {border-color:var(--red);background:#fff5f5;box-shadow:0 0 0 2px rgba(224,32,46,.06);}
.order-type-option input {position:absolute;width:1px;height:1px;overflow:hidden;opacity:0;}
.custom-checkbox {position:relative;width:19px;height:19px;margin-top:1px;flex:0 0 19px;border:2px solid var(--gray-300);border-radius:5px;background:var(--white);}
.order-type-option input:checked + .custom-checkbox {border-color:var(--red);background:var(--red);}
.order-type-option input:checked + .custom-checkbox::after {content:'';position:absolute;top:2px;left:5px;width:4px;height:8px;border-right:2px solid var(--white);border-bottom:2px solid var(--white);transform:rotate(45deg);}
.order-type-option input:focus-visible + .custom-checkbox {outline:3px solid rgba(224,32,46,.18);outline-offset:2px;}
.order-type-text {display:flex;min-width:0;flex-direction:column;gap:3px;}
.order-type-text strong {color:var(--ink);font-size:12px;line-height:1.35;}
.order-type-text small {color:var(--gray-500);font-size:10.5px;line-height:1.4;}
.filter-footer {display:flex;align-items:center;justify-content:flex-end;gap:16px;margin-top:18px;}
.selection-status {color:var(--gray-500);font-size:12px;font-weight:600;}
.apply-button {min-width:180px;}
.metric-card,.metric-values,.metric-values > div {min-width:0;}
.metric-values {grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
.metric-value {max-width:100%;line-height:1.25;overflow-wrap:anywhere;}

/* ── COUNTRY BREAKDOWN TABLE ── */
.country-breakdown-table {width: 100%;table-layout: fixed;}
.country-breakdown-table th,.country-breakdown-table td {padding: 14px 10px;text-align: center;vertical-align: middle;}
.country-breakdown-table th:first-child,.country-breakdown-table td:first-child {width: 14%;text-align: left;font-weight: 800;}
.country-breakdown-table th:nth-child(2),.country-breakdown-table td:nth-child(2) 
.country-breakdown-table th:nth-child(3),.country-breakdown-table td:nth-child(3) 
.country-breakdown-table th:nth-child(5),.country-breakdown-table td:nth-child(5) 
.country-breakdown-table th:nth-child(6),.country-breakdown-table td:nth-child(6) 
.country-breakdown-table td:nth-child(5),.country-breakdown-table td:nth-child(6) {white-space:nowrap;}
.country-breakdown-table tbody tr:first-child td {font-weight: 800;}
@media (max-width: 900px) {.table-wrap {overflow-x: auto;}.country-breakdown-table {min-width: 850px;}}
@media (max-width:800px) {.period-filter-grid,.order-type-groups,.metric-grid {grid-template-columns:1fr;}.order-type-header,.filter-footer {flex-direction:column;align-items:stretch;}.checkbox-actions {align-self:flex-start;}.apply-button {width:100%;}}
@media (max-width:500px) {.date-grid {grid-template-columns:1fr;}.company-field {width:100%;}}
</style>
<link rel="stylesheet" href="../includes/report_tables.css">
</head>
<body>
<script>
(function () {
    try {
        if (window.innerWidth >= 900 && localStorage.getItem('adminSidebarCollapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (e) {}
})();
</script>

<?php
$pageTitle = 'Active Agent & ASD';
include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="layout">
<main class="main">
    <div class="page-header">
        <h1>Active Agent &amp; ASD Comparison</h1>
        <p>Compare repurchase sales, unique purchasing agents and
            Average Sales per Agent between two reporting periods.
        </p>
     </div>

     <?php if (!empty($errors)): ?>
        <div class="error-box" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card-title">Comparison Filters</div>
        <div class="card-subtitle">
            Compare matching month-to-date ranges ending on the same day number.
        </div>

        <form method="get" action="asd_comparison.php" id="asdFilterForm">
            <div class="period-filter-grid">
                <div class="period-box period-a">
                    <div class="period-title">Last Month</div>
                    <div class="date-grid">
                        <div class="field">
                            <label for="period_a_from">From</label>
                            <input type="date" id="period_a_from" name="period_a_from" value="<?= htmlspecialchars($periodAFrom) ?>" required>
                        </div>
                        <div class="field">
                            <label for="period_a_to">To</label>
                            <input type="date" id="period_a_to" name="period_a_to" value="<?= htmlspecialchars($periodATo) ?>" required>
                        </div>
                    </div>
                </div>

                <div class="period-box period-b">
                    <div class="period-title">Current Month</div>
                    <div class="date-grid">
                        <div class="field">
                            <label for="period_b_from">From</label>
                            <input type="date" id="period_b_from" name="period_b_from" value="<?= htmlspecialchars($periodBFrom) ?>" required>
                        </div>
                        <div class="field">
                            <label for="period_b_to">To</label>
                            <input type="date" id="period_b_to" name="period_b_to" value="<?= htmlspecialchars($periodBTo) ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="filter-footer">
                <button type="submit" name="apply" value="1" class="apply-button">
                    Compare Periods
                </button>
            </div>
        </form>
    </section>

            <?php if (empty($errors)): ?>

            <div class="period-heading-grid">
                <div class="period-heading a">
                    <div class="period-heading-name">Last Month</div>
                    <div class="period-heading-date">
                        <?= htmlspecialchars($periodALabel) ?>
                    </div>
                </div>

                <div class="period-heading b">
                    <div class="period-heading-name">Current Month</div>
                    <div class="period-heading-date">
                        <?= htmlspecialchars($periodBLabel) ?>
                    </div>
                </div>
            </div>

            <section class="metric-grid">

            <article class="metric-card">
                <div class="metric-name">Total Sales</div>

                <div class="metric-values">
                    <div>
                        <div class="metric-period">Last Month</div>
                        <div class="metric-value">
                            RM<?= number_format($periodA['total_sales'], 2) ?>
                        </div>
                    </div>

                    <div>
                        <div class="metric-period">Current Month</div>
                        <div class="metric-value">
                            RM<?= number_format($periodB['total_sales'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="metric-change <?= changeClass($salesChange) ?>">
                <?= htmlspecialchars(formatMetricDifference(
                    $periodA['total_sales'],
                    $periodB['total_sales'],
                    true,
                    2
                )) ?>
            </div>
            </article>

            <article class="metric-card">
                <div class="metric-name">Active Agents</div>

                <div class="metric-values">
                    <div>
                        <div class="metric-period">Last Month</div>
                        <div class="metric-value">
                            <?= number_format($periodA['active_agents']) ?>
                        </div>
                    </div>

                    <div>
                        <div class="metric-period">Current Month</div>
                        <div class="metric-value">
                            <?= number_format($periodB['active_agents']) ?>
                        </div>
                    </div>
                </div>

                <div class="metric-change <?= changeClass($agentsChange) ?>">
                    <?= htmlspecialchars(formatMetricDifference(
                        (float)$periodA['active_agents'],
                        (float)$periodB['active_agents'],
                        false,
                        0
                    )) ?>
                </div>
            </article>

            <article class="metric-card">
                <div class="metric-name">Average Sales per Agent (ASD)</div>

                <div class="metric-values">
                    <div>
                        <div class="metric-period">Last Month</div>

                        <div class="metric-value">
                            RM<?= number_format($periodA['asd'], 2) ?>
                        </div>
                    </div>

                    <div>
                        <div class="metric-period">Current Month</div>

                        <div class="metric-value">
                            RM<?= number_format($periodB['asd'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="metric-change <?= changeClass($asdChange) ?>">
                    <?= htmlspecialchars(formatMetricDifference(
                        $periodA['asd'],
                        $periodB['asd'],
                        true,
                        2
                    )) ?>
                </div>
            </article>

            </section>

        <section class="card">
            <div class="card-title">Calculation Breakdown</div>
            <div class="card-subtitle">
                Active Agents and ASD by country. Singapore sales are converted
                to MYR before ASD is calculated.
            </div>

            <div class="table-wrap">
                <table class="comparison-table country-breakdown-table">
                    <thead>
                        <tr>
                            <th>Country</th>
                            <th>Active Agent <?= htmlspecialchars($periodAMonthName) ?></th>
                            <th>Active Agent <?= htmlspecialchars($periodBMonthName) ?></th>
                            <th>Difference (%)</th>
                            <th>ASD <?= htmlspecialchars($periodAMonthName) ?></th>
                            <th>ASD <?= htmlspecialchars($periodBMonthName) ?></th>
                            <th>Difference (%)</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($countryBreakdown as $country): ?>
                            <tr>
                                <td><?= htmlspecialchars($country['label']) ?></td>
                                <td><?= number_format($country['period_a']['active_agents']) ?></td>
                                <td><?= number_format($country['period_b']['active_agents']) ?></td>
                                <td class="<?= changeClass($country['agent_change']) ?>"><?= formatChange($country['agent_change']) ?></td>
                                <td>RM<?= number_format($country['period_a']['asd'], 2) ?></td>
                                <td>RM<?= number_format($country['period_b']['asd'], 2) ?></td>
                                <td class="<?= changeClass($country['asd_change']) ?>"><?= formatChange($country['asd_change']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    <?php endif; ?>

</main>
</div>

<script src="../includes/report_ajax.js"></script>

</body>
</html>
