<?php
/**
 * Sales by Brand
 *
 * Single file that serves both:
 *  - The normal page (topnav, sidebar, filters, table shell)
 *  - The AJAX data endpoint (same URL, called via fetch() with the
 *    X-Requested-With: XMLHttpRequest header) which returns JSON only
 *
 * Source:
 * - orders
 * - order_items (Tax Invoice)
 * - companies
 *
 * Formula:
 * - MY sales = invoice_amount
 * - SG sales = invoice_amount × 3.27
 * - Percentage = Brand Sales ÷ Total Sales × 100
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

define('SGD_TO_MYR_RATE', 3.27);

// false = hide event ticket when its sales are zero
// true = always show event ticket, including RM0.00
define('SHOW_ZERO_EVENT_TICKET', false);

// Is this an AJAX data request, or a normal full-page load?
$isAjax = (
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
);

// ---- Authentication (both AJAX and normal requests need this) ----

if (empty($_SESSION['admin_id'])) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'errors' => ['Session expired. Please log in again.'], 'auth' => false]);
        exit;
    }
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

    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'errors' => ['Your session has expired.'], 'auth' => false]);
        exit;
    }
    header('Location: ../index.php?expired=1');
    exit;
}

$_SESSION['last_activity'] = time();

$adminUsername = $_SESSION['admin_username'] ?? '';
$activeNav = 'sales_by_brand';
$navBasePath = '../';

// ---- Helpers ----

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

// Validate YYYY-MM-DD
function isValidDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false &&
        $parsed->format('Y-m-d') === $date;
}

// Validate company filter
function normalizeCompany($company): string
{
    if (!is_string($company)) {
        return 'all';
    }

    return in_array($company, ['all', 'MY', 'SG'], true)
        ? $company
        : 'all';
}

// Load and calculate sales by brand
function getSalesByBrand(
    PDO $pdo,
    string $from,
    string $to,
    string $companyFilter
): array {
    $params = [
        'from_date' => $from . ' 00:00:00',

        'to_exclusive' => (new DateTimeImmutable($to))
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00'),

        'confirmed_status' => 'confirmed',
    ];

    $companyCondition = '';

    if ($companyFilter !== 'all') {
        $companyCondition = ' AND c.company_code = :company_code';
        $params['company_code'] = $companyFilter;
    }

    /*
     * starter_order determines the Starter Kit brand for each order.
     *
     * PERFORMANCE: this is joined to `orders` and filtered by the same
     * date range as the main query, so it only aggregates the orders
     * in the selected period — not the entire order_items table on
     * every request (that was the original slowdown).
     */
    $sql = "
        SELECT
            classified.sale_date,
            classified.report_brand,
            SUM(classified.converted_amount) AS total_sales

        FROM (
            SELECT
                DATE(o.order_datetime) AS sale_date,
                CASE
                    WHEN UPPER(TRIM(COALESCE(oi.brand, ''))) =
                         'EVENTTICKET'

                      OR UPPER(TRIM(COALESCE(
                          oi.item_description,
                          ''
                      ))) LIKE '%TICKET%'

                    THEN 'Event Ticket'

                    WHEN UPPER(TRIM(COALESCE(oi.brand, ''))) =
                         'CHOCO ALBAB'
                    THEN 'Choco Albab'

                    WHEN UPPER(TRIM(COALESCE(oi.brand, ''))) =
                         'NAFESA'
                    THEN 'Nafesa'

                    WHEN UPPER(TRIM(COALESCE(oi.brand, ''))) =
                         'ZEKY'
                    THEN 'Zeky'

                    WHEN UPPER(TRIM(COALESCE(oi.brand, ''))) = 'STK'
                    THEN starter_order.starter_brand

                    ELSE NULL
                END AS report_brand,

                CASE
                    WHEN c.company_code = 'SG'
                    THEN COALESCE(oi.invoice_amount, 0) *
                         " . SGD_TO_MYR_RATE . "

                    ELSE COALESCE(oi.invoice_amount, 0)
                END AS converted_amount

            FROM orders o

            INNER JOIN order_items oi
                ON oi.order_id = o.id

            INNER JOIN companies c
                ON c.id = o.company_id

            LEFT JOIN (
                SELECT
                    kit_items.order_id,

                    CASE
                        WHEN SUM(
                            UPPER(TRIM(kit_items.item_code)) =
                                'STK-CA'

                            OR UPPER(TRIM(kit_items.item_code))
                                LIKE 'STK-CA-%'

                            OR UPPER(TRIM(kit_items.item_description))
                                LIKE '%CHOCO ALBAB STARTER KIT%'
                        ) > 0
                        THEN 'Choco Albab'

                        WHEN SUM(
                            UPPER(TRIM(kit_items.item_code)) =
                                'STK-NF'

                            OR UPPER(TRIM(kit_items.item_code))
                                LIKE 'STK-NF-%'

                            OR UPPER(TRIM(kit_items.item_description))
                                LIKE '%NAFESA STARTER KIT%'
                        ) > 0
                        THEN 'Nafesa'

                        WHEN SUM(
                            UPPER(TRIM(kit_items.item_code)) =
                                'STK-ZEKY'

                            OR UPPER(TRIM(kit_items.item_code))
                                LIKE 'STK-ZEKY-%'

                            OR UPPER(TRIM(kit_items.item_description))
                                LIKE '%ZEKY STARTER KIT%'
                        ) > 0
                        THEN 'Zeky'

                        ELSE NULL
                    END AS starter_brand

                FROM order_items kit_items

                -- Scoped to the SAME orders the outer query cares
                -- about, instead of the whole table.
                INNER JOIN orders o2
                    ON o2.id = kit_items.order_id
                   AND o2.order_datetime >= :from_date_sub
                   AND o2.order_datetime < :to_exclusive_sub
                   AND o2.order_status = :confirmed_status_sub

                GROUP BY kit_items.order_id
            ) starter_order
                ON starter_order.order_id = o.id

            WHERE o.order_datetime >= :from_date
              AND o.order_datetime < :to_exclusive
              AND o.order_status = :confirmed_status
              AND c.company_code IN ('MY', 'SG')

              {$companyCondition}
        ) classified

        WHERE classified.report_brand IS NOT NULL

        GROUP BY classified.sale_date, classified.report_brand
        ORDER BY classified.sale_date, classified.report_brand
    ";

    $params['from_date_sub'] = $params['from_date'];
    $params['to_exclusive_sub'] = $params['to_exclusive'];
    $params['confirmed_status_sub'] = $params['confirmed_status'];

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $dates = [];
    $cursor = new DateTimeImmutable($from);
    $lastDate = new DateTimeImmutable($to);

    while ($cursor <= $lastDate) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    $sales = [];
    foreach (['Choco Albab', 'Nafesa', 'Zeky', 'Event Ticket'] as $brand) {
        $sales[$brand] = array_fill_keys($dates, 0.00);
    }

    while ($row = $statement->fetch()) {
        $brand = (string)$row['report_brand'];
        $saleDate = (string)$row['sale_date'];

        if (isset($sales[$brand][$saleDate])) {
            $sales[$brand][$saleDate] = round(
                (float)$row['total_sales'],
                2
            );
        }
    }

    $dailyTotals = array_fill_keys($dates, 0.00);
    foreach ($sales as $dailySales) {
        foreach ($dailySales as $saleDate => $amount) {
            $dailyTotals[$saleDate] = round($dailyTotals[$saleDate] + $amount, 2);
        }
    }
    $totalSales = round(array_sum($dailyTotals), 2);

    $rows = [];

    foreach ($sales as $brand => $dailySales) {
        $brandSales = round(array_sum($dailySales), 2);
        if (
            $brand === 'Event Ticket' &&
            $brandSales <= 0 &&
            !SHOW_ZERO_EVENT_TICKET
        ) {
            continue;
        }

        $percentage = $totalSales > 0
            ? round(($brandSales / $totalSales) * 100, 2)
            : 0.00;

        $rows[] = [
            'brand'      => $brand,
            'daily'      => $dailySales,
            'total'      => $brandSales,
            'percentage' => $percentage,
        ];
    }

    usort(
        $rows,
        static fn(array $a, array $b): int =>
            $b['total'] <=> $a['total']
    );

    return [
        'rows'         => $rows,
        'dates'        => $dates,
        'daily_totals' => $dailyTotals,
        'total_sales'  => $totalSales,
    ];
}

// ---- Shared request parsing (both AJAX and normal page load use this) ----

$today = new DateTimeImmutable('today');
$yesterday = $today->modify('-1 day');

$defaultFrom = $yesterday->modify('first day of this month')->format('Y-m-d');
$defaultTo = $yesterday->format('Y-m-d');

$from = is_string($_GET['from'] ?? null) ? $_GET['from'] : $defaultFrom;
$to = is_string($_GET['to'] ?? null) ? $_GET['to'] : $defaultTo;
$companyFilter = normalizeCompany($_GET['company'] ?? 'all');

$errors = [];

if (!isValidDate($from) || !isValidDate($to)) {
    $errors[] = 'The reporting period contains an invalid date.';
} elseif ($from > $to) {
    $errors[] = 'The start date cannot be later than the end date.';
}

// ======================================================================
// AJAX BRANCH — run the query, output JSON only, stop before any HTML
// ======================================================================
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    if (!empty($errors)) {
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }

    $pdo = getDBConnection();

    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'errors' => ['Unable to connect to the database.']]);
        exit;
    }

    try {
        $report = getSalesByBrand($pdo, $from, $to, $companyFilter);
    } catch (Throwable $e) {
        error_log('Sales by Brand report failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'errors' => ['Unable to load the Sales by Brand report.']]);
        exit;
    }

    $periodLabel = date('d M Y', strtotime($from)) . ' - ' . date('d M Y', strtotime($to));

    echo json_encode([
        'ok'           => true,
        'errors'       => [],
        'from'         => $from,
        'to'           => $to,
        'company'      => $companyFilter,
        'period_label' => $periodLabel,
        'sgd_rate'     => SGD_TO_MYR_RATE,
        'rows'         => $report['rows'],
        'dates'        => $report['dates'],
        'daily_totals' => $report['daily_totals'],
        'total_sales'  => $report['total_sales'],
    ]);
    exit;
}

// ======================================================================
// NORMAL PAGE LOAD BRANCH — render the HTML shell.
// The filter values above are only used to pre-fill the form;
// the actual report data is fetched by JS after the page loads.
// ======================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales by Brand — S ASIA SALES REPORT</title>
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
body {background: var(--bg);color: var(--ink);font-family: "Plus Jakarta Sans", sans-serif;}

.layout {display: flex;min-height: calc(100vh - var(--topbar-h));margin-top: var(--topbar-h);}
.main {min-width: 0;flex: 1;margin-left: var(--sidebar-w);padding: 28px 32px 48px;transition: margin-left .25s ease;}

body.sidebar-collapsed .main {margin-left: var(--sidebar-w-collapsed);}

.page-header {margin-bottom: 24px;}
.page-header h1 {margin-bottom: 5px;font-size: 25px;font-weight: 800;}
.page-header p {color: var(--gray-500);font-size: 13px;}

.card {margin-bottom: 24px;padding: 24px;border: 1px solid var(--gray-100);border-radius: var(--radius-lg);background: var(--white);box-shadow: var(--shadow-card);}
.card-title {margin-bottom: 4px;font-size: 16px;font-weight: 800;}
.card-subtitle {color: var(--gray-500);font-size: 12px; margin-bottom: 10px;}

.filter-grid {display: grid;grid-template-columns:repeat(2, minmax(180px, 1fr))minmax(180px, 240px)auto;gap: 16px;align-items: end;margin-top: 20px;}
.field {display: flex;min-width: 0;flex-direction: column;gap: 7px;}
.field label {color: var(--gray-700);font-size: 10.5px;font-weight: 800;text-transform: uppercase;}
.field input,.field select {width: 100%;min-height: 44px;padding: 10px 12px;border: 1.5px solid var(--gray-300);border-radius: 9px;background: var(--white);color: var(--ink);font: inherit;font-size: 13px;}

.apply-button {min-height: 42px;padding: 10px 20px;border: 0;border-radius: 9px;background: var(--red);box-shadow: 0 4px 14px rgba(224, 32, 46, .22);color: var(--white);cursor: pointer;font-size: 13px;font-weight: 800;}
.apply-button:hover {background: var(--red-dark);}
.apply-button:disabled {opacity: .7;cursor: default;}

.error-box {margin-bottom: 20px;padding: 14px 17px;border: 1px solid #FECACA;border-radius: 10px;background: #FEF2F2;color: #991B1B;font-size: 12px;}
.error-box ul {padding-left: 18px;}

.summary-grid {display: grid;grid-template-columns: repeat(2, minmax(0, 1fr));gap: 16px;margin-bottom: 24px;}
.summary-card {padding: 19px;border: 1px solid var(--gray-100);border-radius: var(--radius-md);background: var(--white);box-shadow: var(--shadow-card);}
.summary-label {margin-bottom: 5px;color: var(--gray-500);font-size: 10px;font-weight: 800;text-transform: uppercase;}
.summary-value {font-size: 21px;font-weight: 800;}

.table-wrap {overflow: hidden;border: 1px solid #E6E6EA;border-radius: 12px;background: var(--white);}
.brand-table {width: 100%;border-collapse: separate;border-spacing: 0;}
.brand-table th,.brand-table td {padding: 14px 16px;border-bottom: 1px solid #ECECF0;text-align: left;font-size: 12px;vertical-align: middle;}
.brand-table th {background: #F7F7F9;color: var(--gray-700);font-size: 10.5px;font-weight: 800;letter-spacing: .3px;text-transform: uppercase;}
.brand-table tbody tr {transition: background-color .15s ease;}
.brand-table tbody tr:hover td {background: #FAFAFB;}
.brand-table tbody tr:last-child td {border-bottom: 0;}
.brand-table th:not(:first-child),.brand-table td:not(:first-child) {text-align: right;}
.brand-name {font-weight: 800;}
.brand-total {font-weight: 800;white-space: nowrap;}

.percentage-bar {display: inline-flex;width: 100%;max-width: 170px;align-items: center;justify-content: flex-end;gap: 9px;}
.percentage-track {width: 90px;height: 7px;overflow: hidden;border-radius: 20px;background: var(--gray-100);}
.percentage-fill {height: 100%;border-radius: 20px;background: var(--red);}
.total-row td {background: #F7F7F9 !important;font-weight: 800;}
.note {margin-top: 15px;color: var(--gray-500);font-size: 11px;line-height: 1.6;}

.spinner {width: 15px;height: 15px;border: 2px solid rgba(255,255,255,.4);border-top-color: #fff;border-radius: 50%;animation: spin .7s linear infinite;display: none;}
.apply-button.is-loading .spinner {display: inline-block;}
.report-loading td {padding: 40px 16px;text-align: center;color: var(--gray-500);font-size: 12px;}
@keyframes spin {to {transform: rotate(360deg);}}

@media (max-width: 950px) {.filter-grid {grid-template-columns: repeat(2, minmax(0, 1fr));}}
@media (max-width: 900px) {.main,body.sidebar-collapsed .main {margin-left: 0;padding: 20px;}}
@media (max-width: 650px) {.filter-grid,.summary-grid {grid-template-columns: 1fr;}.apply-button {width: 100%;}.table-wrap {overflow-x: auto;}.brand-table {min-width: 650px;}}
</style>
<link rel="stylesheet" href="../includes/report_tables.css">
</head>

<body>

<script>
(function() {
    try {
        if (
            window.innerWidth >= 900 &&
            localStorage.getItem('adminSidebarCollapsed') === '1'
        ) {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (error) {
        // The report still works without localStorage
    }
})();
</script>

<?php
$pageTitle = 'Sales by Brand';

include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="layout">
<main class="main">

    <header class="page-header">
        <h1>Sales by Brand</h1>
        <p>Converted Tax Invoice sales for Choco Albab, Nafesa, Zeky and Event Tickets.</p>
    </header>

    <div id="errorContainer"></div>

    <section class="card">
        <div class="card-title">Report Filters</div>
        <div class="card-subtitle">Select an invoice period and company.</div>
        <form id="filterForm" class="filter-grid">
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>" required>
            </div>

            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>" required>
            </div>

            <div class="field">
                <label for="company">Company</label>
                <select id="company" name="company">
                    <option value="all" <?= $companyFilter === 'all' ? 'selected' : '' ?>>All Companies</option>
                    <option value="MY" <?= $companyFilter === 'MY' ? 'selected' : '' ?>>Malaysia</option>
                    <option value="SG" <?= $companyFilter === 'SG' ? 'selected' : '' ?>>Singapore</option>
                </select>
            </div>
            <button type="submit" id="applyButton" class="apply-button">
                <span class="spinner"></span>
                <span class="btn-label">Generate Report</span>
            </button>
        </form>
    </section>

    <div id="reportContainer">
        <section class="summary-grid">
            <article class="summary-card">
                <div class="summary-label">Reporting Period</div>
                <div class="summary-value" id="periodLabel">—</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Total Converted Sales</div>
                <div class="summary-value" id="totalSalesLabel">—</div>
            </article>
        </section>

        <section class="card">
            <div class="card-title">Brand Sales Breakdown</div>
            <div class="card-subtitle">
                Each selected date is shown separately; brands are ranked by total converted sales.
            </div>
            <div class="table-wrap">
                <table class="brand-table" id="brandTable">
                    <thead id="brandTableHead"></thead>
                    <tbody id="brandTableBody">
                        <tr class="report-loading"><td>Loading report…</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="note" id="rateNote"></div>
        </section>
    </div>

</main>
</div>

<script>
// Same URL as the current page — no separate API file needed.
const API_URL = window.location.pathname;
const PAGE_URL = window.location.pathname;

const form = document.getElementById('filterForm');
const applyButton = document.getElementById('applyButton');
const errorContainer = document.getElementById('errorContainer');
const periodLabelEl = document.getElementById('periodLabel');
const totalSalesLabelEl = document.getElementById('totalSalesLabel');
const tableHead = document.getElementById('brandTableHead');
const tableBody = document.getElementById('brandTableBody');
const rateNote = document.getElementById('rateNote');

let currentRequestId = 0;

function formatMoney(amount) {
    return 'RM' + Number(amount).toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDateHeader(isoDate) {
    const d = new Date(isoDate + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function setLoading(isLoading) {
    applyButton.disabled = isLoading;
    applyButton.classList.toggle('is-loading', isLoading);
    applyButton.querySelector('.btn-label').textContent = isLoading ? 'Loading…' : 'Generate Report';
}

function renderErrors(errors) {
    if (!errors || errors.length === 0) {
        errorContainer.innerHTML = '';
        return;
    }
    const items = errors.map(e => `<li>${escapeHtml(e)}</li>`).join('');
    errorContainer.innerHTML = `<div class="error-box" role="alert"><ul>${items}</ul></div>`;
}

function renderReport(data) {
    periodLabelEl.textContent = data.period_label;
    totalSalesLabelEl.textContent = formatMoney(data.total_sales);

    let headHtml = '<tr><th>Brand</th>';
    data.dates.forEach(d => {
        headHtml += `<th>${escapeHtml(formatDateHeader(d))}</th>`;
    });
    headHtml += '<th>Total</th><th>(%)</th></tr>';
    tableHead.innerHTML = headHtml;

    let bodyHtml = '';
    data.rows.forEach(row => {
        bodyHtml += '<tr>';
        bodyHtml += `<td class="brand-name">${escapeHtml(row.brand)}</td>`;
        data.dates.forEach(d => {
            bodyHtml += `<td>${formatMoney(row.daily[d] || 0)}</td>`;
        });
        bodyHtml += `<td class="brand-total">${formatMoney(row.total)}</td>`;
        const pct = Math.min(100, Math.max(0, row.percentage));
        bodyHtml += `<td><div class="percentage-bar"><div class="percentage-track">` +
            `<div class="percentage-fill" style="width:${pct}%"></div></div>` +
            `<span>${row.percentage.toFixed(2)}%</span></div></td>`;
        bodyHtml += '</tr>';
    });

    bodyHtml += '<tr class="total-row"><td>Total</td>';
    data.dates.forEach(d => {
        bodyHtml += `<td>${formatMoney(data.daily_totals[d] || 0)}</td>`;
    });
    bodyHtml += `<td>${formatMoney(data.total_sales)}</td>`;
    bodyHtml += `<td>${data.total_sales > 0 ? '100.00%' : '0.00%'}</td>`;
    bodyHtml += '</tr>';

    tableBody.innerHTML = bodyHtml;

    rateNote.textContent =
        `Singapore Invoice Amount is converted using SGD × ${Number(data.sgd_rate).toFixed(2)}. ` +
        `Event Ticket appears only when ticket transactions exist during the selected period.`;
}

async function loadReport(params, updateUrl) {
    const requestId = ++currentRequestId;
    setLoading(true);
    tableBody.innerHTML = '<tr class="report-loading"><td>Loading report…</td></tr>';

    const query = new URLSearchParams(params).toString();

    try {
        const response = await fetch(`${API_URL}?${query}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (requestId !== currentRequestId) return;

        if (response.status === 401) {
            window.location.href = '../index.php?expired=1';
            return;
        }

        const data = await response.json();

        if (requestId !== currentRequestId) return;

        if (!data.ok) {
            renderErrors(data.errors);
            tableBody.innerHTML = '';
            tableHead.innerHTML = '';
            totalSalesLabelEl.textContent = '—';
            periodLabelEl.textContent = '—';
            rateNote.textContent = '';
            return;
        }

        renderErrors([]);
        renderReport(data);

        if (updateUrl) {
            const urlParams = new URLSearchParams(params);
            history.replaceState(null, '', `${PAGE_URL}?${urlParams.toString()}`);
        }
    } catch (err) {
        if (requestId !== currentRequestId) return;
        renderErrors(['Unable to load the report. Please check your connection and try again.']);
    } finally {
        if (requestId === currentRequestId) {
            setLoading(false);
        }
    }
}

form.addEventListener('submit', function (e) {
    e.preventDefault();
    const params = {
        from: document.getElementById('from').value,
        to: document.getElementById('to').value,
        company: document.getElementById('company').value,
    };
    loadReport(params, true);
});

loadReport({
    from: document.getElementById('from').value,
    to: document.getElementById('to').value,
    company: document.getElementById('company').value,
}, false);
</script>

</body>
</html>
