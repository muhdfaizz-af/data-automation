<?php
/**
 * Sales by Brand
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

// Authentication
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
$activeNav = 'sales_by_brand';
$navBasePath = '../';

// Create the database connection
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
function getSalesByBrand (
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
        $companyCondition =
            ' AND c.company_code = :company_code';

        $params['company_code'] = $companyFilter;
    }

    /*
     * starter_order determines the Starter Kit brand for each order.
     *
     * This lets generic STK items such as guidebooks inherit the
     * Starter Kit brand from another recognized item in that order.
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
                    /*
                     * Event Ticket must be checked before normal brands.
                     * Some ticket items are stored under CHOCO ALBAB.
                     */
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

                    /*
                     * Items stored under the generic STK brand inherit
                     * the Starter Kit brand identified for their order.
                     */
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

    // Display the highest-selling brand first.
    usort(
        $rows,
        static fn(array $a, array $b): int =>
            $b['total'] <=> $a['total']
    );

    return [
        'rows'        => $rows,
        'dates'       => $dates,
        'daily_totals' => $dailyTotals,
        'total_sales' => $totalSales,
    ];
}

// Default reporting period: current month through yesterday
$today = new DateTimeImmutable('today');
$yesterday = $today->modify('-1 day');

$defaultFrom = $yesterday
    ->modify('first day of this month')
    ->format('Y-m-d');

$defaultTo = $yesterday->format('Y-m-d');

$from = is_string($_GET['from'] ?? null)
    ? $_GET['from']
    : $defaultFrom;

$to = is_string($_GET['to'] ?? null)
    ? $_GET['to']
    : $defaultTo;

$companyFilter = normalizeCompany(
    $_GET['company'] ?? 'all'
);

$errors = [];

if (!isValidDate($from) || !isValidDate($to)) {
    $errors[] = 'The reporting period contains an invalid date.';
} elseif ($from > $to) {
    $errors[] =
        'The start date cannot be later than the end date.';
}

$report = [
    'rows'        => [],
    'dates'       => [],
    'daily_totals' => [],
    'total_sales' => 0.00,
];

$pdo = getDBConnection();

if(!$pdo) {
    $errors[] = 'Unable to connect to the database.';
}

if (empty($errors) && $pdo) {
    try {
        $report = getSalesByBrand(
            $pdo,
            $from,
            $to,
            $companyFilter
        );
    } catch (Throwable $e) {
        error_log(
            'Sales by Brand report failed: ' .
            $e->getMessage()
        );

        $errors[] = 'Unable to load the Sales by Brand report.';
    }
}

$periodLabel = '';

if (empty($errors)) {
    $periodLabel =
        date('d M Y', strtotime($from)) . 
        ' - ' .
        date('d M Y', strtotime($to));
}
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
    --red: #E0202E;--red-dark: #8E1620;--ink: #1B1B1F;
    --gray-700: #4A4A52;--gray-500: #8A8A93;--gray-300: #D8D8DE;
    --gray-100: #F2F2F4;--bg: #F5F5F7;--white: #FFFFFF;--green: #059669;
    --radius-lg: 18px;--radius-md: 12px;--shadow-card: 0 8px 24px rgba(30, 30, 40, .06);
    --sidebar-w: 256px;--sidebar-w-collapsed: 76px;--topbar-h: 64px;
}
*,*::before,*::after {box-sizing: border-box;margin: 0;padding: 0;}
body {background: var(--bg);color: var(--ink);font-family: "Plus Jakarta Sans", sans-serif;}

/* ── LAYOUT ── */
.layout {display: flex;min-height: calc(100vh - var(--topbar-h));margin-top: var(--topbar-h);}
.main {min-width: 0;flex: 1;margin-left: var(--sidebar-w);padding: 28px 32px 48px;transition: margin-left .25s ease;}

/* ── SIDEBAR ── */
body.sidebar-collapsed .main {margin-left: var(--sidebar-w-collapsed);}

/* ── HEADER ── */
.page-header {margin-bottom: 24px;}
.page-header h1 {margin-bottom: 5px;font-size: 25px;font-weight: 800;}
.page-header p {color: var(--gray-500);font-size: 13px;}

/* ── CARD ── */
.card {margin-bottom: 24px;padding: 24px;border: 1px solid var(--gray-100);border-radius: var(--radius-lg);background: var(--white);box-shadow: var(--shadow-card);}
.card-title {margin-bottom: 4px;font-size: 16px;font-weight: 800;}
.card-subtitle {color: var(--gray-500);font-size: 12px;}

/* ── FILTER SECTION ── */
.filter-grid {display: grid;grid-template-columns:repeat(2, minmax(180px, 1fr))minmax(180px, 240px)auto;gap: 16px;align-items: end;margin-top: 20px;}
.field {display: flex;min-width: 0;flex-direction: column;gap: 7px;}
.field label {color: var(--gray-700);font-size: 10.5px;font-weight: 800;text-transform: uppercase;}
.field input,.field select {width: 100%;min-height: 44px;padding: 10px 12px;border: 1.5px solid var(--gray-300);border-radius: 9px;background: var(--white);color: var(--ink);font: inherit;font-size: 13px;}

/* ── APPLY BUTTON ── */
.apply-button {min-height: 44px;padding: 10px 22px;border: 0;border-radius: 9px;background: var(--red);color: var(--white);cursor: pointer;font-size: 13px;font-weight: 800;}
.apply-button:hover {background: var(--red-dark);}

/* ── ERROR MESSAGE SECTION ── */
.error-box {margin-bottom: 20px;padding: 14px 17px;border: 1px solid #FECACA;border-radius: 10px;background: #FEF2F2;color: #991B1B;font-size: 12px;}
.error-box ul {padding-left: 18px;}

/* ── SUMMARY SECTION ── */
.summary-grid {display: grid;grid-template-columns: repeat(2, minmax(0, 1fr));gap: 16px;margin-bottom: 24px;}
.summary-card {padding: 19px;border: 1px solid var(--gray-100);border-radius: var(--radius-md);background: var(--white);box-shadow: var(--shadow-card);}
.summary-label {margin-bottom: 5px;color: var(--gray-500);font-size: 10px;font-weight: 800;text-transform: uppercase;}
.summary-value {font-size: 21px;font-weight: 800;}

/* ── SALES TABLE ── */
.table-wrap {overflow: hidden;border: 1px solid #E6E6EA;border-radius: 12px;background: var(--white);}
.brand-table {width: 100%;border-collapse: separate;border-spacing: 0;}.brand-table th,.brand-table td {padding: 14px 16px;border-bottom: 1px solid #ECECF0;text-align: left;font-size: 12px;vertical-align: middle;}
.brand-table th {background: #F7F7F9;color: var(--gray-700);font-size: 10.5px;font-weight: 800;letter-spacing: .3px;text-transform: uppercase;}
.brand-table tbody tr {transition: background-color .15s ease;}
.brand-table tbody tr:hover td {background: #FAFAFB;}
.brand-table tbody tr:last-child td {border-bottom: 0;}
.brand-table th:not(:first-child),.brand-table td:not(:first-child) {text-align: right;}
.brand-name {font-weight: 800;}
.brand-total {font-weight: 800;white-space: nowrap;}

/* ── PERCENTAGE SECTION ── */
.percentage-bar {display: inline-flex;width: 100%;max-width: 170px;align-items: center;justify-content: flex-end;gap: 9px;}
.percentage-track {width: 90px;height: 7px;overflow: hidden;border-radius: 20px;background: var(--gray-100);}
.percentage-fill {height: 100%;border-radius: 20px;background: var(--red);}
.total-row td {background: #F7F7F9 !important;font-weight: 800;}
.note {margin-top: 15px;color: var(--gray-500);font-size: 11px;line-height: 1.6;}
@media (max-width: 950px) {.filter-grid {grid-template-columns: repeat(2, minmax(0, 1fr));}}
@media (max-width: 900px) {.main,body.sidebar-collapsed .main {margin-left: 0;padding: 20px;}}
@media (max-width: 650px) {.filter-grid,.summary-grid {grid-template-columns: 1fr;}.apply-button {width: 100%;}.table-wrap {overflow-x: auto;}.brand-table {min-width: 650px;}}
</style>
</head>

<body>

<script>
(function() {
    try {
        if (
            window.innerWidth >= 900 &&
            localStorage.getItem(
                'adminSidebarCollapsed'
            ) === '1'
        ) {
            document.body.classList.add(
                'sidebar-collapsed'
            );
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

    <?php if (!empty($errors)): ?>
        <div class="error-box" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li>
                        <?= htmlspecialchars($error) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card-title">Report Filters</div>
        <div class="card-subtitle">Select an invoice period and company.</div>
        <form method="get" action="sales_bybrand.php" class="filter-grid"
>
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
                    <option value="all" <?= $companyFilter === 'all' ? 'selected' : '' ?>>
                        All Companies
                    </option>
                    <option value="MY" <?= $companyFilter === 'MY' ? 'selected' : '' ?>>
                        Malaysia
                    </option>
                    <option value="SG" <?= $companyFilter === 'SG' ? 'selected' : '' ?>>
                        Singapore
                    </option>
                </select>
            </div>
            <button type="submit" name="apply" value="1" class="apply-button">
                Generate Report
            </button>
        </form>
    </section>

    <?php if (empty($errors)): ?>
        <section class="summary-grid">
            <article class="summary-card">
                <div class="summary-label">Reporting Period</div>
                <div class="summary-value"><?= htmlspecialchars($periodLabel) ?></div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Total Converted Sales</div>
                <div class="summary-value">
                    RM<?= number_format($report['total_sales'], 2) ?>
                </div>
            </article>
        </section>

        <section class="card">
            <div class="card-title">Brand Sales Breakdown</div>
            <div class="card-subtitle">
                Each selected date is shown separately; brands are ranked by total converted sales.
            </div>
            <div class="table-wrap">
                <table class="brand-table">
                    <thead>
                        <tr>
                            <th>Brand</th>
                            <?php foreach ($report['dates'] as $saleDate): ?>
                                <th><?= htmlspecialchars(date('d M Y', strtotime($saleDate))) ?></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                            <th>(%)</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($report['rows'] as $row): ?>
                            <tr>
                                <td class="brand-name">
                                    <?= htmlspecialchars($row['brand']) ?>
                                </td>
                                <?php foreach ($report['dates'] as $saleDate): ?>
                                    <td>RM<?= number_format($row['daily'][$saleDate], 2) ?></td>
                                <?php endforeach; ?>
                                <td class="brand-total">RM<?= number_format($row['total'], 2) ?>
                                </td>
                                <td>
                                    <div class="percentage-bar">
                                        <div class="percentage-track">
                                            <div class="percentage-fill" style="width: <?= min(100,max(0,$row['percentage'])) ?>%"></div>
                                        </div>
                                        <span>
                                            <?= number_format($row['percentage'], 2) ?>%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>Total</td>
                            <?php foreach ($report['dates'] as $saleDate): ?>
                                <td>RM<?= number_format($report['daily_totals'][$saleDate], 2) ?></td>
                            <?php endforeach; ?>
                            <td>
                                RM<?= number_format($report['total_sales'], 2) ?>
                            </td>
                            <td>
                                <?= $report['total_sales'] > 0 ? '100.00%' : '0.00%' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="note">
                Singapore Invoice Amount is converted using
                SGD × <?= number_format(SGD_TO_MYR_RATE, 2) ?>.
                Event Ticket appears only when ticket transactions
                exist during the selected period.
            </div>
        </section>
    <?php endif; ?>

</main>
</div>

</body>
</html>
