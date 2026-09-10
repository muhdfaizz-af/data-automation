<?php
/**
 * Overall Top and Bottom 5 Loose Products
 *
 * Source:
 * - orders
 * - order_items (Tax Invoice)
 * - companies
 *
 * Included regions:
 * - West Malaysia
 * - East Malaysia
 * - Brunei
 * - Singapore
 *
 * Formula:
 * MY sales = invoice_amount
 * SG sales in MYR = invoice_amount × 3.27
 *
 * Products are ranked by total sales, not quantity.
 */

session_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

define('SGD_TO_MYR_RATE', 3.27);
define('RANKING_LIMIT', 5);

/**
 * Codes confirmed to represent the same product can be mapped here.
 *
 * Format:
 * 'ALIAS-CODE' => 'MASTER-CODE'
 */
define('PRODUCT_CODE_MAP', [
    // Example:
    // 'PRE-BCD-002' => 'BCD-002',
    // 'OLD-BCD-002' => 'BCD-002',
]);

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
$activeNav = 'top_product';
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

// Validate a YYYY-MM-DD date
function isValidDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date
    );

    return $parsed !== false &&
        $parsed->format('Y-m-d') === $date;
}

// Validate the selected reporting period
function validatePeriod(string $from, string $to): string
{
    if(!isValidDate($from) || !isValidDate($to)) {
        return 'The reporting period contains an invalid date.';
    }

    if ($from > $to) {
        return 'The start date cannot be later than the end date.';
    }

    $days = (int)(
        new DateTimeImmutable($from)
    )->diff(
        new DateTimeImmutable($to)
    )->format('%a');

    if ($days > 731) {
        return 'The reporting period cannot exceed 732 days.';
    }

    return '';
}

// Convert product-code variants to one master code.
function canonicalizeItemCode(string $itemCode): string
{
    $itemCode = strtoupper(trim($itemCode));

    if (isset(PRODUCT_CODE_MAP[$itemCode])) {
        return PRODUCT_CODE_MAP[$itemCode];
    }

    // Remove common preorder prefixes
    $itemCode = preg_replace(
        '/^(?:PRE|PREORDER)-/i',
        '',
        $itemCode
    );

    return $itemCode !== '' ? $itemCode : 'UNKNOWN';
}

// Clean unnecessary wording from the displayed product name
function cleanProductName(string $description): string
{
    $description = trim($description);

    $description = preg_replace(
        '/^\s*\(PREORDER\)\s*/i',
        '',
        $description
    );

    $description = preg_replace(
        '/\s+/',
        ' ',
        $description
    );

    return trim($description);
}

/**
 * Check whether the row represents a loose product.
 *
 * product_type = normal is the main database indicator.
 * Text checks provide additional protection against incorrectly
 * classified sets, bundles, cartons and Starter Kits.
 */
function isLooseProduct(
    string $productType,
    string $itemCode,
    string $description
): bool {
    $productType = strtoupper(trim($productType));
    $itemCode = strtoupper(trim($itemCode));
    $description = strtoupper(trim($description));

    if ($productType !== 'NORMAL') {
        return false;
    }

    if (
        str_starts_with($itemCode, 'STK-') ||
        $itemCode === 'STK'
    ) {
        return false;
    }

    $excludedTerms = [
        'STARTER KIT',
        'BUNDLE',
        'COMBO',
        'PACKAGE',
        'PACKAGES',
        'CARTON',
        'SET A',
        'SET B',
        ' SET ',
        'BOX SET',
        'PAPERBAG',
        'PAPER BAG',
        'BUNTING',
        'BROCHURE',
        'FLYER',
        'VOUCHER',
        'DISPLAY',
        'MINI RAK',
        'MINI RACK',
        'TABLE CLOTH',
        'APRON',
        'PLASTIC CUP',
        'TUMBLER',
        'WOVEN BAG',
    ];

    foreach ($excludedTerms as $term) {
        if (str_contains($description, $term)) {
            return false;
        }
    }

    return true;
}

// Load and consolidate loose products from Tax Invoice MY and SG
function getOverallProducts(
    PDO $pdo,
    string $from,
    string $to
): array {
    $params = [
        'from_date' => $from . ' 00:00:00',

        // Exclusive end date includes the entire selected final day
        'to_exclusive' => (
            new DateTimeImmutable($to)
        )->modify('+1 day')->format('Y-m-d 00:00:00'),

        'confirmed_status' => 'confirmed',
    ];

    $sql = "
        SELECT
            UPPER(TRIM(COALESCE(oi.brand, ''))) AS brand,
            oi.product_type,
            oi.item_code,
            oi.item_description,
            c.company_code,

            SUM(COALESCE(oi.qty, 0)) AS total_quantity,

            SUM(
                COALESCE(oi.invoice_amount, 0)
            ) AS invoice_sales

        FROM order_items oi

        INNER JOIN orders o
            ON o.id = oi.order_id

        INNER JOIN companies c
            ON c.id = o.company_id

        WHERE o.order_datetime >= :from_date
            AND o.order_datetime < :to_exclusive
            AND o.order_status = :confirmed_status

           /*
           * MY includes West Malaysia, East Malaysia and Brunei.
           * SG includes Singapore.
           */
           AND c.company_code IN ('MY', 'SG')

           /*
           * Main product brands only.
           * STK and EVENTTICKET are excluded.
           */
           AND UPPER(TRIM(COALESCE(oi.brand, ''))) IN (
               'CHOCO ALBAB',
               'NAFESA',
               'ZEKY'
            )

        GROUP BY
            UPPER(TRIM(COALESCE(oi.brand, ''))),
            oi.product_type,
            oi.item_code,
            oi.item_description,
            c.company_code

        ";

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        $products = [];

        while ($row = $statement->fetch()) {
            $rawCode = (string)$row['item_code'];
            $description = (string)($row['item_description'] ?? '');
            $productType = (string)($row['product_type'] ?? '');

            if (
                !isLooseProduct(
                    $productType,
                    $rawCode,
                    $description
                )
            ) {
                continue;
            }

            $masterCode = canonicalizeItemCode($rawCode);
            $productKey = $masterCode;

            $sales = (float)$row['invoice_sales'];

            // Convert Singapore Tax Invoice sales to MYR
            if ($row['company_code'] === 'SG') {
                $sales *= SGD_TO_MYR_RATE;
            }

            if (!isset($products[$productKey])) {
                $products[$productKey] = [
                    'item_code'      => $masterCode,
                    'product_name'   => cleanProductName($description),
                    'brands'         => [],
                    'source_codes'   => [],
                    'total_quantity' => 0,
                    'total_sales'    => 0.00,
                ];
            }

            $products[$productKey]['total_quantity'] +=
                (int)$row['total_quantity'];

            $products[$productKey]['total_sales'] += $sales;

            $products[$productKey]['brands'][
                (string)$row['brand']
            ] = true;

            $products[$productKey]['source_codes'][
                strtoupper(trim($rawCode))
            ] = true;

            if (
                $products[$productKey]['product_name'] === '' &&
                $description !== ''
            ) {
                $products[$productKey]['product_name'] =
                    cleanProductName($description);
            }
        }

        $result = [];

        foreach ($products as $product) {
            /*
            * Bottom 5 normally means products with at least one
            * positive sale during the selected period.
            */
            if (
                $product['total_quantity'] <= 0 ||
                $product['total_sales'] <= 0
            ) {
                continue;
            }

            $product['total_sales'] = round(
                $product['total_sales'],
                2
            );

            $product['brands'] = array_keys(
                $product['brands']
            );

            $product['source_codes'] = array_keys(
                $product['source_codes']
            );

            sort($product['brands']);
            sort($product['source_codes']);

            if ($product['product_name'] === '') {
                $product['product_name'] =
                    $product['item_code'];
            }

            $result[] = $product;
        }

        return $result;
}

/**
 * Rank products and return the Top 5 and Bottom 5.
 */
function splitRankings(array $products): array
{
    /*
     * Highest sales first.
     * Product name is used when two products have equal sales.
     */
    usort(
        $products,
        static function (array $a, array $b): int {
            $salesComparison =
                $b['total_sales'] <=> $a['total_sales'];

            if ($salesComparison !== 0) {
                return $salesComparison;
            }

            return strcasecmp(
                $a['product_name'],
                $b['product_name']
            );
        }
    );

    foreach ($products as $index => &$product) {
        $product['overall_rank'] = $index + 1;
    }

    unset($product);

    $top = array_slice(
        $products,
        0,
        RANKING_LIMIT
    );

    /*
     * Keep overall rank numbering for the Bottom 5.
     * The lowest product appears first.
     */
    $bottom = array_reverse(
        array_slice($products, -RANKING_LIMIT)
    );

    return [
        'top'   => $top,
        'bottom' => $bottom,
    ];
}

// Render one ranking table
function renderProductRows(array $products): void
{
    if (empty($products)) {
        ?>
        <tr>
            <td colspan="5" class="empty-row">
                No qualifying loose-product sales were found.
            </td>
        </tr>
        <?php

        return;
    }

    foreach ($products as $product) {
        ?>
        <tr>
            <td>
                <span class="rank-number"><?= number_format($product['overall_rank']) ?></span>
            </td>
            <td>
                <strong><?= htmlspecialchars($product['product_name']) ?></strong>
                <div class="product-code">
                    Master code:
                    <?= htmlspecialchars($product['item_code']) ?>
                </div>
            </td>
            <td>
                <?= htmlspecialchars(implode(', ', $product['brands'])) ?>
            </td>
            <td class="number-cell">
                <?= number_format($product['total_quantity']) ?>
            </td>
            <td class="sales-value">
                RM<?= number_format($product['total_sales'], 2) ?>
            </td>
        </tr>
        <?php
    }
}

// Default period: current month until yesterday
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

$errors = [];

$periodError = validatePeriod($from, $to);

if ($periodError !== '') {
    $errors[] = $periodError;
}

$products = [];

$rankings = [
    'top'    => [],
    'bottom' => [],
];

$pdo = getDBConnection();

if(!$pdo) {
    $errors[] = 'Unable to connect to the database.';
}

if(empty($errors) && $pdo) {
    try {
        $products = getOverallProducts(
            $pdo,
            $from,
            $to
        );

        $rankings = splitRankings($products);
    } catch (Throwable $e) {
        error_log(
            'Overall Top/Bottom Product report failed: ' .
            $e->getMessage()
        );

        $errors[] =
            'Unable to load the product ranking report.';
    }
}

$periodLabel = '';

if (isValidDate($from) && isValidDate($to)) {
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
<title>Overall Top &amp; Bottom 5 Products</title>
<link rel="icon" href="../images/icon-sasia.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --red: #e0202e;--red-dark: #a91520;--ink: #1b1b1f;
    --gray-700: #4a4a52;--gray-500: #8a8a93;--gray-300: #d8d8de;
    --gray-100: #f2f2f4;--background: #f5f5f7;--white: #ffffff;
    --sidebar-width: 256px;--sidebar-collapsed: 76px;--topbar-height: 64px;
}
*,*::before,*::after {box-sizing: border-box;margin: 0;padding: 0;}
body {min-height: 100vh;background: var(--background);color: var(--ink);font-family: "Plus Jakarta Sans", sans-serif;}

/* ── LAYOUT ── */
.layout {display: flex;min-height: calc(100vh - var(--topbar-height));margin-top: var(--topbar-height);}
.main {min-width: 0;flex: 1;margin-left: var(--sidebar-width);padding: 28px 32px 48px;transition: margin-left .25s ease;}

/* ── SIDEBAR ── */
body.sidebar-collapsed .main {margin-left: var(--sidebar-collapsed);}

/* ── HEADER SECTION ── */
.page-header {margin-bottom: 24px;}
.page-header h1 {margin-bottom: 5px;font-size: 25px;font-weight: 800;}
.page-header p {color: var(--gray-500);font-size: 13px;}

/* ── CARD SECTION ── */
.card {margin-bottom: 24px;padding: 24px;border: 1px solid #ececf0;border-radius: 18px;background: var(--white);box-shadow: 0 5px 18px rgba(30, 30, 40, .06);}
.card-title {margin-bottom: 4px;font-size: 17px;font-weight: 800;}
.card-subtitle {color: var(--gray-500);font-size: 12px;}

/* ── FILTER SECTION ── */
.filter-grid {display: grid;grid-template-columns:repeat(2, minmax(180px, 1fr)) auto;gap: 16px;align-items: end;margin-top: 20px;}
.field {display: flex;flex-direction: column;gap: 7px;}
.field label {color: var(--gray-700);font-size: 11px;font-weight: 800;text-transform: uppercase;}
.field input {width: 100%;min-height: 44px;padding: 10px 12px;border: 1.5px solid var(--gray-300);border-radius: 9px;font: inherit;}

/* ── BUTTON ── */
.apply-button {min-height: 44px;padding: 10px 22px;border: 0;border-radius: 9px;background: var(--red);color: var(--white);cursor: pointer;font-weight: 800;}
.apply-button:hover {background: var(--red-dark);}

/* ── ERROR SECTION ── */
.error-box {margin-bottom: 20px;padding: 14px 17px;border: 1px solid #fecaca;border-radius: 10px;background: #fef2f2;color: #991b1b;}
.error-box ul {padding-left: 20px;}

/* ── SUMMARY SECTION ── */
.summary-grid {display: grid;grid-template-columns: repeat(2, minmax(0, 1fr));gap: 16px;margin-bottom: 24px;}
.summary-card {padding: 18px;border: 1px solid #ececf0;border-radius: 12px;background: var(--white);}
.summary-label {margin-bottom: 5px;color: var(--gray-500);font-size: 10px;font-weight: 800;text-transform: uppercase;}
.summary-value {font-size: 17px;font-weight: 800;}

/* ── RANKING SECTION ── */
.ranking-grid {display: grid;grid-template-columns: repeat(2, minmax(0, 1fr));gap: 20px;}
.ranking-card {min-width: 0;}
.table-wrap {margin-top: 18px;overflow: hidden;border: 1px solid #e6e6ea;border-radius: 12px;}
.ranking-table {width: 100%;table-layout: fixed;border-collapse: separate;border-spacing: 0;}
.ranking-table th,.ranking-table td {padding: 13px 10px;border-bottom: 1px solid #ececf0;font-size: 11px;overflow-wrap: anywhere;vertical-align: middle;}
.ranking-table th {background: #f7f7f9;color: var(--gray-700);font-size: 9px;font-weight: 800;text-transform: uppercase;}
.ranking-table tbody tr:last-child td {border-bottom: 0;}
.ranking-table tbody tr:hover td {background: #fafafb;}
.ranking-table th:nth-child(1),.ranking-table td:nth-child(1) {width: 10%;}
.ranking-table th:nth-child(2),.ranking-table td:nth-child(2) {width: 36%;}
.ranking-table th:nth-child(3),.ranking-table td:nth-child(3) {width: 20%;}
.ranking-table th:nth-child(4),.ranking-table td:nth-child(4) {width: 14%;text-align: right;}
.ranking-table th:nth-child(5),.ranking-table td:nth-child(5) {width: 20%;text-align: right;}
.rank-number {display: inline-flex;width: 27px;height: 27px;align-items: center;justify-content: center;border-radius: 50%;background: var(--gray-100);font-weight: 800;}
.product-code {margin-top: 4px;color: var(--gray-500);font-size: 10px;}
.sales-value {font-weight: 800;}
.empty-row {padding: 30px !important;color: var(--gray-500);text-align: center !important;}
.note {margin-top: 14px;color: var(--gray-500);font-size: 11px;line-height: 1.6;}
@media (max-width: 1150px) {.ranking-grid {grid-template-columns: 1fr;}}
@media (max-width: 900px) {.main, body.sidebar-collapsed .main {margin-left: 0; padding: 20px;}}
@media (max-width: 650px) {.filter-grid,.summary-grid {grid-template-columns: 1fr;}.table-wrap {overflow-x: auto;}.ranking-table {min-width: 650px;}}
</style>
</head>
<body>

<script>
(function () {
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
        // The report remains usable without localStorage
    }
})();
</script>

<?php
$pageTitle = 'Overall Top & Bottom 5 Products';

include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="layout">
<main class="main">

    <header class="page-header">
        <h1>Overall Top &amp; Bottom 5 Products</h1>
        <p>Loose-product rankings across Malaysia, Brunei and Singapore.</p>
    </header>

    <?php if(!empty($errors)): ?>
        <div class="error-box" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card-title">Report Filter</div>

        <div class="card-subtitle">
            Select the Tax Invoice reporting period.
        </div>

        <form method="get" action="top_product.php" class="filter-grid">
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>" required>
            </div>

            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>" required>
            </div>

            <button type="submit" name="apply" value="1" class="apply-button">
                Generate Ranking
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
                <div class="summary-label">Qualifying Loose Products</div>
                <div class="summary-value"><?= number_format(count($products)) ?></div>
            </article>
        </section>

        <div class="ranking-grid">
            <section class="card ranking-card">
                <div class="card-title">Top 5 by Total Sales</div>
                <div class="card-subtitle">Products with the highest converted sales.</div>
                <div class="table-wrap">
                    <table class="ranking-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Product</th>
                                <th>Brand</th>
                                <th>Quantity</th>
                                <th>Total Sales</th>
                            </tr>
                        </thead>
                        <tbody><?php renderProductRows($rankings['top']); ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card ranking-card">
                <div class="card-title">Bottom 5 by Total Sales</div>
                <div class="card-subtitle">Products with the lowest positive sales.</div>
                <div class="table-wrap">
                    <table class="ranking-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Product</th>
                                <th>Brand</th>
                                <th>Quantity</th>
                                <th>Total Sales</th>
                            </tr>
                        </thead>
                        <tbody><?php renderProductRows($rankings['bottom']);?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="note">
            Rankings use confirmed MY and SG Tax Invoice
            lines. Singapore sales are converted using SGD ×
            <?= number_format(SGD_TO_MYR_RATE, 2) ?>.
            Sets, bundles, packages, cartons and Starter Kits
            are excluded.
        </div>
    <?php endif; ?>
    </main>
    </div>
</body>
</html>