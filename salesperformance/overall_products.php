<?php
/**
 * Overall Top and Bottom 10 Consolidated Products
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
define('RANKING_LIMIT', 10);

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
$activeNav = 'overall_products';
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

// Remove operational wording from a displayed product category name.
function cleanProductName(string $description): string
{
    $description = trim($description);
    $description = preg_replace('/^\s*\(PREORDER\)\s*/i', '', $description);
    $description = preg_replace('/\s*\(FULFILMENT[^)]*\)\s*/i', '', $description);
    $description = preg_replace('/\s+/', ' ', $description);

    return trim((string)$description);
}

// Consolidate related product codes into one reporting category
function identifyProductCategory(
    string $brand,
    string $itemCode,
    string $description,
    string $productType
): ?string {
    $brand = strtoupper(trim($brand));
    $itemCode = strtoupper(trim($itemCode));
    $description = strtoupper(trim($description));
    $productType = strtoupper(trim($productType));

    // Exclude transactions that cannot represent product sales.
    $excludedTerms = [
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
        'MENU BOARD',
        ' BOARD ',
    ];

    foreach ($excludedTerms as $term) {
        if (str_contains($description, $term)) {
            return null;
        }
    }

    // Belgian Chocolate Drink family
    if (
        preg_match('/^(?:BCD-002|BCDC-002|CBCDA-002|STK-BCDS-002)(?:-|$)/', $itemCode) ||
        str_contains($description, '(BCDB) BOX BELGIAN CHOCOLATE DRINK')
    ) {
        return '(BCDB) BOX BELGIAN CHOCOLATE DRINK';
    }

    // Unicorn Strawberry Chocolate family
    if (
        in_array($itemCode, ['CA-6', 'CA-006'], true) ||
        str_starts_with($itemCode, 'CAC-011') ||
        str_starts_with($itemCode, 'STK-CA-6') ||
        str_contains($description, 'UNICORN STRAWBERRY')
    ) {
        return 'UNICORN STRAWBERRY CHOCOLATE TUB';
    }

    if (str_contains($description, 'CUTIE MINI CHOCO CRUNCH')) {
        return 'CUTIE MINI CHOCO CRUNCH TUB';
    }

    if (str_contains($description, 'BUTTERCREAM LATTE')) {
        return 'BUTTERCREAM LATTE DRINK';
    }

    if (str_contains($description, 'CUTIE CHOCO BALL')) {
        return 'CUTIE CHOCO BALL TUB';
    }

    if (str_contains($description, 'CUTIE MINI CHOCO DORAYAKI')) {
        return 'CUTIE MINI CHOCO DORAYAKI TUB';
    }

    if (str_contains($description, 'CUTIE CHOCO RICE')) {
        return 'CUTIE CHOCO RICE TUB';
    }

    if (str_contains($description, 'PISTACHIO DREAM')) {
        return 'PISTACHIO DREAM TUB';
    }

    if (str_contains($description, 'COTTON CANDY CHOCOLATE')) {
        return 'COTTON CANDY CHOCOLATE TUB';
    }

    // Brazilian Coffee family
    if (
        preg_match('/^(?:BRC|BRCC|STK-BRC)/', $itemCode) ||
        str_contains($description, 'BRAZILIAN COFFEE')
    ) {
        return 'BRAZILIAN COFFEE DRINK';
    }

    // Belgian Mocha family
    if (
        preg_match('/^(?:BMD|BMDC)/', $itemCode) ||
        str_contains($description, 'BELGIAN MOCHA')
    ) {
        return 'BELGIAN MOCHA DRINK';
    }

    // Blueberry Chocolate family
    if (
        preg_match('/^(?:BBC|BBCC)/', $itemCode) ||
        str_contains($description, 'BLUEBERRY CHOCOLATE')
    ) {
        return 'BLUEBERRY CHOCOLATE DRINK';
    }

    // Zeky variants are consolidated into one category
    if (
        str_starts_with($itemCode, 'ZEKY-BH') ||
        str_starts_with($itemCode, 'STK-ZEKY-BH') ||
        str_contains($description, 'ZEKY BRAIN HERO')
    ) {
        return 'ZEKY BRAIN HERO';
    }

    // Free/component scarf codes inside a Nafesa Starter Kit.
    if (
        $brand === 'STK' &&
        preg_match('/^STK-N(?!F(?:-|$))/', $itemCode)
    ) {
        return 'SCARF';
    }

    // Consolidate all actual Nafesa scarves
    // Inner products are kept as seperate category
    if ($brand === 'NAFESA') {
        if (
            preg_match('/^(?:NCH|NTU|NST|NIN)/', $itemCode) ||
            str_contains($description, 'INNER')
        ) {
            return 'INNER';
        }

        return 'SCARF';
    }

    // Other Choco Albab products are consolidate by cleaned name
    if ($brand === 'CHOCO ALBAB') {
        return $productType === 'NORMAL'
            ? cleanProductName($description)
            : null;
    }

    return null;
}

/**
 * Decide whether an item row supplies the displayed loose quantity.
 *
 * Sales can come from cartons, sets and bundles, but the displayed
 * quantity should come from their loose-product component code.
 */
function isCategoryQuantityRow(
    string $category,
    string $itemCode,
    string $productType
): bool {
    $itemCode = strtoupper(trim($itemCode));
    $productType = strtoupper(trim($productType));

    return match ($category) {
        // Reference quantity for BCDB comes from BCD-002
        '(BCDB) BOX BELGIAN CHOCOLATE DRINK' =>
            $itemCode === 'BCD-002',

        // CA-6 is the loose component generated from Unicorn cartons and packs. Not count CA-006/CAC-011
        'UNICORN STRAWBERRY CHOCOLATE TUB' =>
            $itemCode === 'CA-6',

        'CUTIE MINI CHOCO CRUNCH TUB' =>
            $itemCode === 'CA-9',

        'CUTIE CHOCO BALL TUB' =>
            $itemCode === 'CA-8',

        'CUTIE MINI CHOCO DORAYAKI TUB' =>
            $itemCode === 'CA-13',

        'CUTIE CHOCO RICE TUB' =>
            $itemCode === 'CA-12',

        'PISTACHIO DREAM TUB' =>
            $itemCode === 'CA-15',

        'COTTON CANDY CHOCOLATE TUB' =>
            $itemCode === 'CA-10',

        // Reference Zeky quantity comes from its loose component
        'ZEKY BRAIN HERO' =>
            $itemCode === 'ZEKY-BH',

        // Brazillian Coffee loose component
        'BRAZILIAN COFFEE DRINK' =>
            $itemCode === 'BRC-001',

        // Nafesa scarves
        'SCARF' =>
            $productType === 'NORMAL' ||
            (bool)preg_match('/^STK-N(?!F(?:-|$))/', $itemCode),

        'INNER' =>
            $productType === 'NORMAL',

        // Other categories use normal/loose rows only
        default =>
            $productType === 'NORMAL',
    };
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

        SUM(COALESCE(oi.qty, 0)) AS row_quantity,

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
            AND c.company_code IN ('MY', 'SG')

            AND UPPER(TRIM(COALESCE(oi.brand, ''))) IN (
                'CHOCO ALBAB',
                'NAFESA',
                'ZEKY',
                'STK'
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

        $categories = [];
        $overallSales = 0.00;

        while ($row = $statement->fetch()) {
            $brand = (string)$row['brand'];
            $itemCode = (string)$row['item_code'];
            $description = (string)($row['item_description'] ?? '');
            $productType = (string)($row['product_type'] ?? '');

            $sales = (float)$row['invoice_sales'];

            if ($row['company_code'] === 'SG') {
                $sales *= SGD_TO_MYR_RATE;
            }

            // Overall sales includes every MY/SG Tax Invoice line in scope.
            $overallSales += $sales;

            $upperDescription = strtoupper($description);

            // Mixed cartons are divided equally between their two products.
            if (str_contains($upperDescription, '30 MCC & 30 BALL')) {
                $allocations = [
                    'CUTIE MINI CHOCO CRUNCH TUB' => 0.5,
                    'CUTIE CHOCO BALL TUB' => 0.5,
                ];
            } elseif (str_contains($upperDescription, '30 RICE & 30 DORAYAKI')) {
                $allocations = [
                    'CUTIE CHOCO RICE TUB' => 0.5,
                    'CUTIE MINI CHOCO DORAYAKI TUB' => 0.5,
                ];
            } else {
                $category = identifyProductCategory(
                    $brand,
                    $itemCode,
                    $description,
                    $productType
                );

                $allocations = $category === null
                    ? []
                    : [$category => 1.0];
            }

            foreach ($allocations as $category => $salesShare) {
                if (!isset($categories[$category])) {
                    $categories[$category] = [
                        'category'       => $category,
                        'total_quantity' => 0,
                        'total_sales'    => 0.00,
                        'source_codes'   => [],
                    ];
                }

                $categories[$category]['total_sales'] +=
                    $sales * $salesShare;

                if (
                    $salesShare === 1.0 &&
                    isCategoryQuantityRow(
                        $category,
                        $itemCode,
                        $productType
                    )
                ) {
                    $categories[$category]['total_quantity'] +=
                        (int)$row['row_quantity'];
                }

                $categories[$category]['source_codes'][
                    strtoupper(trim($itemCode))
                ] = true;
            }
        }

        $result = [];

        foreach ($categories as $category) {
            if ($category['total_sales'] <= 0) {
                continue;
            }

            $category['total_sales'] = round(
                $category['total_sales'],
                2
            );

            $category['source_codes'] = array_keys(
                $category['source_codes']
            );

            sort($category['source_codes']);

            $result[] = $category;
        }

        return [
            'products' => $result,
            'overall_sales' => round($overallSales, 2),
        ];
}

/**
 * Rank products and return the Top 10 and Bottom 10.
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
                $a['category'],
                $b['category']
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
     * Keep overall rank numbering for the Bottom 10.
     * The lowest product appears first.
     */
    $remainingProducts = array_slice($products, RANKING_LIMIT);

    $bottom = array_reverse(
        array_slice($remainingProducts, -RANKING_LIMIT)
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
                No qualifying product sales were found.
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
                <strong><?= htmlspecialchars($product['category']) ?></strong>
            </td>
            <td class="number-cell">
                <?= number_format($product['total_quantity']) ?>
            </td>
            <td class="sales-value">
                RM<?= number_format($product['total_sales'], 2) ?>
            </td>
            <td class="number-cell">
                <?= number_format($product['percentage'], 0) ?>%
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

$requestData = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $_POST
    : $_GET;

$from = is_string($requestData['from'] ?? null)
    ? $requestData['from']
    : $defaultFrom;

$to = is_string($requestData['to'] ?? null)
    ? $requestData['to']
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
$overallSales = 0.00;
$topSales = 0.00;
$topPercentage = 0.00;

$pdo = getDBConnection();

if(!$pdo) {
    $errors[] = 'Unable to connect to the database.';
}

if(empty($errors) && $pdo) {
    try {
        $productReport = getOverallProducts(
            $pdo,
            $from,
            $to
        );

        $products = $productReport['products'];
        $overallSales = $productReport['overall_sales'];

        foreach ($products as &$product) {
            $product['percentage'] = $overallSales > 0
                ? round(($product['total_sales'] / $overallSales) * 100, 2)
                : 0.00;

        }
        unset($product);

        $rankings = splitRankings($products);

        $topSales = round(
            array_sum(array_column($rankings['top'], 'total_sales')),
            2
        );

        $topPercentage = $overallSales > 0
            ? round(($topSales / $overallSales) * 100, 2)
            : 0.00;
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
<title>Overall Top &amp; Bottom 10 Products</title>
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

/* ── LAYOUT ── */
.layout {display:flex;min-height:calc(100vh - var(--topbar-h));margin-top:var(--topbar-h);}
.main {min-width:0;flex:1;margin-left:var(--sidebar-w);padding:28px 32px 48px;transition:margin-left .25s ease;}
body.sidebar-collapsed .main {margin-left: var(--sidebar-w-collapsed);}

/* ── PAGE HEADER ── */
.page-header {margin-bottom: 24px;}
.page-header h1 {margin-bottom: 5px;font-size: 25px;font-weight: 800;}
.page-header p {color: var(--gray-500);font-size: 13px;}

/* ── CARD SECTION ── */
.card {margin-bottom: 24px;padding: 24px;border: 1px solid #ececf0;border-radius: 18px;background: var(--white);box-shadow: 0 5px 18px rgba(30, 30, 40, .06);}
.card-title {margin-bottom: 4px;font-size: 16px;font-weight: 800;}
.card-subtitle {color: var(--gray-500);font-size: 12px;margin-bottom: 10px;}

/* ── FILTER SECTION ── */
.filter-grid {display: grid;grid-template-columns:repeat(2, minmax(180px, 1fr)) auto;gap: 16px;align-items: end;margin-top: 20px;}
.field {display: flex;flex-direction: column;gap: 7px;}
.field label {color: var(--gray-700);font-size: 11px;font-weight: 800;text-transform: uppercase;}
.field input {width: 100%;min-height: 44px;padding: 10px 12px;border: 1.5px solid var(--gray-300);border-radius: 9px;font: inherit;}

/* ── BUTTON ── */
.apply-button {min-height: 42px;padding: 10px 20px;border: 0;border-radius: 9px;background: var(--red);box-shadow: 0 4px 14px rgba(224, 32, 46, .22);color: var(--white);cursor: pointer;font-size: 13px;font-weight: 800;}
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
.ranking-grid {display: grid;grid-template-columns: 1fr;gap: 20px;}
.ranking-card {min-width: 0;}
.table-wrap {width: 100%;overflow-x: auto;border: 1px solid #e6e6ea;border-radius: 12px;background: #fff;}
.ranking-table {width: 100%;min-width: 650px;border-collapse: separate;border-spacing: 0;}
.ranking-table th,.ranking-table td {padding: 13px 12px;border-bottom: 1px solid #ececf0;font-size: 11px;vertical-align: middle;}
.ranking-table th {background: #f7f7f9;color: var(--gray-700);font-size: 9.5px;font-weight: 800;letter-spacing: .3px;text-transform: uppercase;white-space: nowrap;}
.ranking-table tbody tr:last-child td {border-bottom: 0;}
.ranking-table tbody tr:hover td {background: #fafafb;}
.ranking-table th:nth-child(1),.ranking-table td:nth-child(1) {width: 10%;text-align: center;}
.ranking-table th:nth-child(2),.ranking-table td:nth-child(2) {width: 42%;text-align: left;}
.ranking-table th:nth-child(n+3),.ranking-table td:nth-child(n+3) {text-align: right;white-space: nowrap;}
.rank-number {display: inline-flex;width: 27px;height: 27px;align-items: center;justify-content: center;border-radius: 50%;background: var(--gray-100);font-weight: 800;}
.product-code {margin-top: 4px;color: var(--gray-500);font-size: 10px;}
.sales-value {font-weight: 800;}
.empty-row {padding: 30px !important;color: var(--gray-500);text-align: center !important;}
.note {margin-top: 14px;color: var(--gray-500);font-size: 11px;line-height: 1.6;}
@media (max-width: 900px) {.main, body.sidebar-collapsed .main {margin-left: 0; padding: 20px;}}
@media (max-width: 650px) {.filter-grid,.summary-grid {grid-template-columns: 1fr;}}
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
            // The report still works if localStorage is unavailable.
        }
})();
</script>

<?php
$pageTitle = 'Overall Top & Bottom 10 Products';

include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="layout">
<main class="main">

    <header class="page-header">
        <h1>Overall Top &amp; Bottom 10 Products</h1>
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

        <form method="post" action="" class="filter-grid" id="report-filter">
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

    <div id="report-results">
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
                <div class="card-title">Top 10 Products by Sales</div>
                <div class="card-subtitle">Products with the highest converted sales.</div>
                <div class="table-wrap">
                    <table class="ranking-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Total Sales</th>
                                <th>(%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php renderProductRows($rankings['top']); ?>
                            <?php if (!empty($rankings['top'])): ?>
                                <tr class="total-row">
                                    <td colspan="3">Top 10 Sales</td>
                                    <td>RM<?= number_format($topSales, 2) ?></td>
                                    <td><?= number_format($topPercentage, 2) ?>%</td>
                                </tr>
                                <tr class="total-row">
                                    <td colspan="3">Overall Sales</td>
                                    <td>RM<?= number_format($overallSales, 2) ?></td>
                                    <td><?= $overallSales > 0 ? '100.00%' : '0.00%' ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card ranking-card">
                <div class="card-title">Bottom 10 Products by Sales</div>
                <div class="card-subtitle">Products with the lowest positive sales.</div>
                <div class="table-wrap">
                    <table class="ranking-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Total Sales</th>
                                <th>(%)</th>
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
    </div>
    </main>
    </div>
</body>
<script>
    document
        .getElementById('report-filter')
        .addEventListener('submit', async function(event) {
            event.preventDefault();

            const form = event.currentTarget;
            const button = form.querySelector('button[type="submit"]');
            const results = document.getElementById('report-results');
            const originalLabel = button.textContent;

            button.disabled = true;
            button.textContent = 'Loading...';

            try {
                const response = await fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (!response.ok) {
                    throw new Error('Unable to load the report.');
                }

                const html = await response.text();
                const documentParser = new DOMParser();
                const nextDocument = documentParser.parseFromString(html, 'text/html');
                const nextResults = nextDocument.getElementById('report-results');

                if (!nextResults) {
                    throw new Error('Invalid report response.');
                }

                results.replaceWith(nextResults);
            } catch (error) {
                results.innerHTML = '<div class="error-box" role="alert">' +
                    'Unable to load the report. Please try again.' +
                    '</div>';
            } finally {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        });
</script>
</html>