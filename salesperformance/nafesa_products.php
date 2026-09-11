<?php
/**
 * Top and Bottom Product Report
 *
 * Source:
 * - Tax Invoice MY and SG, stored in order_items
 *
 * Rules:
 * - Include Nafesa products only
 * - Divide products into Scarf, Inner and Hand Socks
 * - Include all regions
 * - Consolidate codes representing the same product/design
 * - Convert SG invoice amounts to MYR
 * - Rank by total sales
 * - Exclude zero/negative sales from Bottom 10
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

// Define SG exchange rate
define('SGD_TO_MYR_RATE', 3.27);

// Fix the report to Nafesa and define the selectable product types.
define('REPORT_BRAND', 'NAFESA');

define('ALLOWED_PRODUCT_TYPES', [
    'scarf'      => 'Scarf',
    'inner'      => 'Inner',
    'hand_socks' => 'Hand Socks',
]);

define('DEFAULT_PRODUCT_TYPE', 'scarf');

define('ALLOWED_REGIONS', [
    'all' => 'All Regions',
    'SM'  => 'Semenanjung',
    'BT'  => 'Bintulu',
    'SG'  => 'Singapore',
]);

/**
 * Explicit aliases for codes that represent the same product.
 *
 * Add additional mappings here when the business confirms that two
 * different codes refer to the same scarf/product/design.
 *
 * Alias code => Master code
 */
define('PRODUCT_CODE_MAP', [
    // 'OLD-CODE' => 'MASTER-CODE',
    // 'SPECIAL-NMJ05-BL' => 'NMJ05-BL',
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
$activeNav = 'nafesa_products';
$navBasePath = '../';

/**
 * Create the PDO database connection.
 */
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

/**
 * Validate a date in Y-m-d format.
 */
function isValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed !== false &&
        $parsed->format('Y-m-d') === $date;
}

/**
 * Validate the reporting period.
 */
function validatePeriod(string $from, string $to): string
{
    if (!isValidDate($from) || !isValidDate($to)) {
        return 'The reporting period contains an invalid date.';
    }

    if ($from > $to) {
        return 'The start date cannot be later than the end date.';
    }

    $fromDate = new DateTime($from);
    $toDate = new DateTime($to);
    $days = (int)$fromDate->diff($toDate)->format('%a');

    if ($days > 731) {
        return 'The reporting period cannot exceed 732 days.';
    }

    return '';
}

/**
 * Validate the submitted region.
 *
 * Any invalid or missing region defaults to All Regions.
 */
function normalizeRegion($region): string
{
    if(!is_string($region)) {
        return 'all';
    }

    return array_key_exists($region, ALLOWED_REGIONS)
        ? $region
        : 'all';
}

/**
 * Validate one selected product type.
 */
function normalizeProductType($type): string
{
    if (!is_string($type)) {
        return DEFAULT_PRODUCT_TYPE;
    }

    $type = strtolower(trim($type));

    return array_key_exists($type, ALLOWED_PRODUCT_TYPES)
        ? $type
        : DEFAULT_PRODUCT_TYPE;
}

/**
 * Return a user-friendly product-type level
 */
function getProductTypeLabel(string $type): string
{
    return ALLOWED_PRODUCT_TYPES[$type] ?? 'Unknown';
}

/**
 * Convert an item code to its master/canonical code.
 *
 * First, explicitly configured aliases are applied.
 * Second, common PRE- and PREORDER- prefixes are removed.
 */
function canonicalizeItemCode(string $code): string
{
    $code = strtoupper(trim($code));

    if (isset(PRODUCT_CODE_MAP[$code])) {
        return PRODUCT_CODE_MAP[$code];
    }

    $code = preg_replace(
        '/^(?:PRE|PREORDER)-/i',
        '',
        $code
    );

    return $code ?: 'UNKNOWN';
}

/**
 * Clean preorder and fulfilment wording from the product name.
 */
function cleanProductName(string $description): string
{
    $description = trim($description);

    $description = preg_replace(
        '/^\s*\(PREORDER\)\s*/i',
        '',
        $description
    );

    $description = preg_replace(
        '/\s*\(Fulfilment[^)]*\)\s*/i',
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
 * Classify a Nafesa item as Scarf, Inner or Hand Socks.
 *
 * Return null for packaging, promotional items and other items that
 * should not participate in the product ranking.
 *
 */
function classifyNafesaProduct(
    string $itemCode,
    string $description
): ?string {
    $code = canonicalizeItemCode($itemCode);
    $description = strtoupper(trim($description));

    /*
     * Hand Socks must be checked before the general scarf fallback.
     */
    if (
        preg_match('/^NHS/', $code) ||
        str_contains($description, 'HANDSOCK') ||
        str_contains($description, 'HAND SOCK')
    ) {
        return 'hand_socks';
    }

    /*
     * Known Nafesa inner code families and descriptions.
     *
     * MERD-NCH codes are also captured through the description.
     */
    if (
        preg_match('/^(?:NTU|NCH|NST|NIN)/', $code) ||
        str_contains($code, '-NCH') ||
        str_contains($description, 'INNER TUBE') ||
        str_contains($description, 'INNER CHIN') ||
        str_contains($description, 'INNER STRAP') ||
        str_contains($description, 'INNER NINJA') ||
        str_contains($description, 'FREE INNER')
    ) {
        return 'inner';
    }

    /*
     * Exclude packaging and non-wearable promotional materials.
     */
    $excludedTerms = [
        'PAPERBAG',
        'PAPER BAG',
        'BUNTING',
        'BROCHURE',
        'VOUCHER',
        'DISPLAY',
        'PACKAGING',
        'CHARM',
        'KEYCHAIN',
        'ENAMEL PIN',
        
    ];

    foreach ($excludedTerms as $term) {
        if (str_contains($description, $term)) {
            return null;
        }
    }

    /*
     * Remaining qualifying Nafesa designs are treated as scarves.
     */
    return 'scarf';
}

/**
 * Load and consolidate qualifying Tax Invoice products.
 */
function getRankedProducts(
    PDO $pdo,
    string $from,
    string $to,
    string $regionFilter,
    string $selectedType
): array {
    $params = [
        'from_date'    => $from . ' 00:00:00',
        'to_exclusive' => (new DateTime($to))
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00'),
        'status'       => 'Confirmed', // Void orders are excluded
        'brand'       => REPORT_BRAND,
    ];

    /*
    * Region rules:
    *
    * all = Semenanjung + Bintulu + Singapore
    * SM  = Malaysia, excluding Bintulu
    * BT  = Malaysia Bintulu warehouse
    * SG  = Singapore
    *
    * When All Regions is selected, no additional region condition
    * is added. This allows every MY and SG transaction to qualify.
    */
    $regionCondition = '';

    if ($regionFilter === 'BT') {
        /*
        * Bintulu:
        * - Tax Invoice location MYBTWH, or
        * - Order invoice prefix MYBT
        */
        $regionCondition = "
            AND c.company_code = 'MY'

            AND (
                UPPER(TRIM(COALESCE(
                    oi.order_processed_location,
                    ''
                ))) = 'MYBTWH'

                OR UPPER(TRIM(COALESCE(
                    o.invoice_prefix,
                    ''
                ))) = 'MYBT'
            )
        ";
    } elseif ($regionFilter === 'SG') {
        /*
        * Singapore:
        * All Singapore company transactions.
        */
        $regionCondition = "
            AND c.company_code = 'SG'
        ";
    } elseif ($regionFilter === 'SM') {
        /*
        * Semenanjung:
        * Malaysia transactions that are not identified as Bintulu.
        */
        $regionCondition = "
            AND c.company_code = 'MY'

            AND NOT (
                UPPER(TRIM(COALESCE(
                    oi.order_processed_location,
                    ''
                ))) = 'MYBTWH'

                OR UPPER(TRIM(COALESCE(
                    o.invoice_prefix,
                    ''
                ))) = 'MYBT'
            )
        ";
    } else {
        /*
         * All Regions:
         * Include the two supported invoice companies. Malaysia covers
         * both Semenanjung and Bintulu, while SG covers Singapore.
         */
        $regionCondition = "
            AND c.company_code IN ('MY', 'SG')
        ";
    }

    /*
     * order_items is the Tax Invoice source.
     *
     * orders supplies:
     * - order date
     * - order status
     * - company relationship
     *
     * companies supplies:
     * - MY/SG company code
     */
    $sql = "
    SELECT
        UPPER(TRIM(oi.brand)) AS brand,
        oi.item_code,
        oi.item_description,
        c.company_code,
        SUM(oi.qty) AS total_quantity,
        SUM(COALESCE(oi.invoice_amount, 0)) AS sales_amount

    FROM order_items oi

    INNER JOIN orders o
        ON o.id = oi.order_id

    INNER JOIN companies c
        ON c.id = o.company_id

    WHERE o.order_datetime >= :from_date
        AND o.order_datetime < :to_exclusive
        AND o.order_status = :status
        AND UPPER(TRIM(oi.brand)) = :brand
        {$regionCondition}

    GROUP BY
        UPPER(TRIM(oi.brand)),
        oi.item_code,
        oi.item_description,
        c.company_code
";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $rows = $statement->fetchAll();
    $products = [];

    foreach ($rows as $row) {
    $brand = strtoupper(trim((string)$row['brand']));
    $rawCode = trim((string)$row['item_code']);
    $description = trim(
        (string)($row['item_description'] ?? '')
    );

   $productType = classifyNafesaProduct(
    $rawCode,
    $description
   );

   if (
    $productType === null ||
    $productType !== $selectedType
   ) {
    continue;
   }

    $masterCode = canonicalizeItemCode($rawCode);
    $key = $brand . '|' . $masterCode;

    if (!isset($products[$key])) {
        $products[$key] = [
            'brand'          => REPORT_BRAND,
            'product_type'   => $productType,
            'item_code'      => $masterCode,
            'product_name'   => cleanProductName($description),
            'total_quantity' => 0,
            'total_sales'    => 0.00,
            'source_codes'   => [],
        ];
    }

    $quantity = (int)$row['total_quantity'];
    $sales = (float)$row['sales_amount'];

    if ($row['company_code'] === 'SG') {
        $sales *= SGD_TO_MYR_RATE;
    }

    $products[$key]['total_quantity'] += $quantity;
    $products[$key]['total_sales'] += $sales;
    $products[$key]['source_codes'][$rawCode] = true;

    /*
    * Prefer a useful cleaned description if the first description
    * was blank.
    */
    $cleanName = cleanProductName($description);

    if (
        $products[$key]['product_name'] === '' &&
        $cleanName !== ''
    ) {
        $products[$key]['product_name'] = $cleanName;
    }
}

    $result = [];

    foreach ($products as $product) {

    /*
    * Bottom 10 excludes products with zero sales.
    * A product must also have at least one sold unit.
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

    $product['source_codes'] = array_keys(
        $product['source_codes']
    );

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
 * Assign overall sales ranks and return Top 10 and Bottom 10
 *
 * The complete list is first ranked from highest sales to lowest
 * sales. Bottom products retain their overall rank numbers.
 */

function splitRankings(array $products): array
{
    /*
     * Sort every qualifying product by Total Sales from highest
     * to lowest.
     *
     * The spaceship operator returns:
     * - Negative number when $b should come before $a
     * - Zero when both sales values are equal
     * - Positive number when $a should come before $b
     *
     * $b is placed before $a here, creating descending order.
     */
    usort(
        $products,
        static function (array $a, array $b): int {
            $salesComparison =
                $b['total_sales'] <=> $a['total_sales'];

            /*
             * If the sales values are different, use the sales
             * comparison as the sorting result.
             */
            if ($salesComparison !== 0) {
                return $salesComparison;
            }

             /*
             * If two products have exactly the same Total Sales,
             * sort them alphabetically by product name.
             *
             * This gives the report a consistent order when sales
             * values are tied.
             */
            return strcmp(
                $a['product_name'],
                $b['product_name']
            );
        }
    );

    /*
     * Assign overall rank:
     * highest sales = 1
     * lowest sales  = total number of products
     */

    foreach ($products as $index => &$product) {
        $product['overall_rank'] = $index + 1;
    }

    unset($product);

    $topProducts = array_slice($products, 0, 10);

    /*
     * Take the final 10 products from the descending list, then
     * reverse them so the lowest-selling product appears first.
     *
     * Example with 23 products:
     * 23, 22, 21, 20 ...
     */

    $bottomProducts = array_reverse(
        array_slice($products, -10)
    );

    return [
        'top'    => $topProducts,
        'bottom' => $bottomProducts,
    ];
}


$yesterday = date('Y-m-d', strtotime('-1 day'));
$defaultFrom = $yesterday;
$defaultTo = $yesterday;

$requestData = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $_POST
    : $_GET;

$from = is_string($requestData['from'] ?? null)
    ? $requestData['from']
    : $defaultFrom;

$to = is_string($requestData['to'] ?? null)
    ? $requestData['to']
    : $defaultTo;

$regionFilter = normalizeRegion(
    $requestData['region'] ?? 'all'
);

$isSubmitted = isset($requestData['apply']);

$selectedType = normalizeProductType(
    $requestData['type'] ?? DEFAULT_PRODUCT_TYPE
);

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

if (!$pdo) {
    $errors[] = 'Unable to connect to the database.';
}

if (empty($errors) && $pdo) {
    try {
        $products = getRankedProducts(
    $pdo,
    $from,
    $to,
    $regionFilter,
    $selectedType
);

        $rankings = splitRankings($products);
    } catch (Throwable $e) {
        error_log(
            'Top/Bottom report failed: ' .
            $e->getMessage()
        );

        $errors[] = 'Unable to load the product ranking.';
    }
}

function renderProductRows(array $products): void
{
    if (empty($products)) {
        echo '
            <tr>
                <td colspan="6" class="empty-row">
                    No qualifying product sales were found.
                </td>
            </tr>
        ';
        return;
    }

    foreach ($products as $product) {
        ?>
        <tr>
            <td>
                <span class="rank-number">
                    <?= number_format($product['overall_rank']) ?>
                </span>
            </td>

            <td>
                <strong>
                    <?= htmlspecialchars($product['product_name']) ?>
                </strong>

                <div class="product-code">
                    Master code:
                    <?= htmlspecialchars($product['item_code']) ?>
                </div>
            </td>

            <td>
                <span class="brand-badge">
                    <?= htmlspecialchars(
                        getProductTypeLabel($product['product_type'])
                    ) ?>
                </span>
            </td>

            <td>
                <?= number_format($product['total_quantity']) ?>
            </td>

            <td class="sales-value">
                RM<?= number_format($product['total_sales'], 2) ?>
            </td>

        </tr>
        <?php
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Top &amp; Bottom Nafesa Products — S ASIA SALES REPORT</title>
<link rel="icon" href="../images/icon-sasia.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --red:#E0202E;--red-dark:#8E1620;--ink:#1B1B1F;
    --gray-700:#4A4A52;--gray-500:#8A8A93;--gray-300:#D8D8DE;--gray-100:#F2F2F4;
    --bg:#F5F5F7;--white:#FFFFFF;--green:#059669;--gold:#D97706;--blue:#2563EB;
    --radius-lg:18px;--radius-md:12px;--sidebar-w:256px;--sidebar-w-collapsed:76px;
    --topbar-h:64px;--shadow-card:0 2px 8px rgba(20,20,30,.06);
}
*, *::before, *::after {box-sizing:border-box;margin:0;padding:0;}
body {min-height:100vh;background:var(--bg);color:var(--ink);font-family:'Plus Jakarta Sans',sans-serif;}
a {color:inherit;text-decoration:none;}
button, input, select {font:inherit;}

/* ── LAYOUT ── */
.layout {display:flex;margin-top:var(--topbar-h);}
.main {min-width:0;flex:1;margin-left:var(--sidebar-w);padding:28px 32px 48px;transition:margin-left .25s ease;}

/* ── LAYOUT ── */
body.sidebar-collapsed .main {margin-left:var(--sidebar-w-collapsed);}

/* ── HEADER ── */
.page-header {margin-bottom:24px;}
.page-header h1 {margin-bottom:4px;font-size:24px;font-weight:800;}
.page-header p {color:var(--gray-500);font-size:13px;}

/* ── CARD  ── */
.card {margin-bottom:24px;padding:24px;border:1px solid var(--gray-100);border-radius:var(--radius-lg);background:var(--white);box-shadow:var(--shadow-card);}
.card-title {margin-bottom:4px;font-size:16px;font-weight:800;}
.card-subtitle {color:var(--gray-500);font-size:12px;}

/* ── FILTER SECTION ── */
.filter-grid {display:grid;grid-template-columns:repeat(2,minmax(180px,1fr)) 200px;gap:16px;align-items:end;margin-top:20px;}
.field {display:flex;flex-direction:column;gap:6px;}
.field label, .filter-label {color:var(--gray-700);font-size:10px;font-weight:800;letter-spacing:.35px;text-transform:uppercase;}
.field input,
.field select {width:100%;min-height:43px;padding:10px 12px;font-size:16px;border:1.5px solid var(--gray-300);border-radius:9px;background:var(--white);outline:none;}
.field input:focus, .field select:focus {border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.10);}

/* ── PRODUCT TYPE SECTION ── */
.type-help {margin-top:9px;color:var(--gray-500);font-size:11px;line-height:1.5;}

/* ── BRAND SECTION ── */
.brand-section {margin-top:18px;}
.brand-options {display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;}
.brand-option {display:flex;align-items:center;gap:8px;padding:10px 13px;border:1.5px solid var(--gray-300);border-radius:9px;background:var(--white);cursor:pointer;font-size:12px;font-weight:700;}
.brand-option:has(input:checked) {border-color:var(--red);background:#FFF5F5;}
.brand-option input[type="radio"] {width:17px;height:17px;flex-shrink:0;accent-color:var(--red);cursor:pointer;}
.brand-option:has(input[type="radio"]:checked) {border-color:var(--red);background:#FFF5F5;box-shadow:0 0 0 2px rgba(224,32,46,.06);}
.filter-footer {display:flex;justify-content:flex-end;margin-top:18px;}

/* ── APPLY BUTTON ── */
.apply-button {min-width:180px;padding:11px 20px;border:0;border-radius:9px;background:var(--red);color:var(--white);cursor:pointer;font-size:13px;font-weight:800;}
.apply-button:hover {background:var(--red-dark);}
.error-box,
.info-box {margin-bottom:20px;padding:13px 15px;border-radius:10px;font-size:12px;line-height:1.6;}
.error-box {border:1px solid #FECACA;background:#FEF2F2;color:#991B1B;}
.info-box {border:1px solid #BFDBFE;background:#EFF6FF;color:#1E3A8A;}

/* ── SUMMARY SECTION ── */
.summary-grid {display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px;}
.summary-card {padding:18px;border:1px solid var(--gray-100);border-radius:var(--radius-md);background:var(--white);box-shadow:var(--shadow-card);}
.summary-label {margin-bottom:5px;color:var(--gray-500);font-size:10px;font-weight:800;text-transform:uppercase;}
.summary-value {font-size:16px;font-weight:800;}

/* ── RANKING SECTION ── */
.ranking-grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;}
.ranking-card {min-width:0;}
.table-wrap {width:100%;min-width:0;margin-top:18px;overflow-x:hidden;}
.ranking-table {width:100%;table-layout:fixed;border-collapse:collapse;}
.ranking-table th, .ranking-table td {padding:12px 8px;border-bottom:1px solid var(--black-100);text-align:left;vertical-align:top;font-size:11px;overflow-wrap:anywhere;}
.ranking-table th {color:var(--black-500);font-size:10px;letter-spacing:.3px;text-transform:uppercase;}
.ranking-table th:nth-child(1),.ranking-table td:nth-child(1) {width:10%;}
.ranking-table th:nth-child(2),.ranking-table td:nth-child(2) {width:39%;}
.ranking-table th:nth-child(3),.ranking-table td:nth-child(3) {width:16%;}
.ranking-table th:nth-child(4),.ranking-table td:nth-child(4) {width:14%;}
.ranking-table th:nth-child(5),.ranking-table td:nth-child(5) {width:21%;}
.ranking-table th:nth-child(4),.ranking-table td:nth-child(4),
.ranking-table th:nth-child(5),.ranking-table td:nth-child(5) {text-align:right;}
.rank-number {display:inline-flex;width:25px;height:25px;align-items:center;justify-content:center;border-radius:50%;background:var(--gray-100);font-weight:800;}
.product-code,.source-codes {margin-top:4px;color:var(--black-500);font-size:10px;line-height:1.4;}
.brand-badge {display:inline-flex;min-width:76px;min-height:32px;padding:5px 10px;align-items:center;justify-content:center;border-radius:20px;background:var(--gray-100);font-size:9px;font-weight:800;line-height:1.15;text-align:center;}
.sales-value {color:var(--black);font-weight:800;white-space:normal;}
.empty-row {padding:30px !important;color:var(--gray-500);text-align:center !important;}
@media(max-width:1100px) {.ranking-grid {grid-template-columns:1fr;}}
@media(max-width:900px) {.main,body.sidebar-collapsed .main {margin-left:0;padding:20px;}}
@media(max-width:700px) {.filter-grid,.summary-grid {grid-template-columns:1fr;}}
</style>
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
        // Continue without saved sidebar state
    }
})();
</script>

<?php
$pageTitle = 'Top & Bottom Nafesa Products';
include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="layout">
<main class="main">

    <div class="page-header">
        <h1>Top &amp; Bottom Nafesa Products</h1>
        <p>Rank Nafesa Scarf, Inner and Hand Socks products using converted Tax Invoice sales.</p>
    </div>

    <div id="report-results">
    <?php if (!empty($errors)): ?>
        <div class="error-box">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <section class="card">
        <div class="card-title">Report Filters</div>

        <div class="card-subtitle">
            Select the period, region and one Nafesa product type.
        </div>

        <form method="post" action="nafesa_products.php" id="report-filter">
            <div class="filter-grid">

                <div class="field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>" required>
                </div>

                <div class="field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>" required>
                </div>

                <div class="field">
                    <label for="region">Region</label>

                    <select id="region" name="region" required>
                        <?php foreach (
                            ALLOWED_REGIONS as $regionValue => $regionLabel
                        ): ?>
                            <option
                                value="<?= htmlspecialchars($regionValue) ?>"
                                <?= $regionFilter === $regionValue
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= htmlspecialchars($regionLabel) ?>

                                <?php if ($regionValue !== 'all'): ?>
                                    (<?= htmlspecialchars($regionValue) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="brand-section">
                <div class="filter-label">Nafesa Product Type</div>

                <div class="brand-options">
                    <?php foreach (
                        ALLOWED_PRODUCT_TYPES as $typeValue => $typeLabel
                    ): ?>
                        <label class="brand-option">
                            <input
                                type="radio"
                                name="type"
                                value="<?= htmlspecialchars($typeValue) ?>"
                                <?= $selectedType === $typeValue
                                    ? 'checked'
                                    : '' ?>
                                required
                            >

                            <span><?= htmlspecialchars($typeLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="type-help">
                    Select one product type. Rankings are generated separately
                    for Scarf, Inner or Hand Socks.
                </div>
            </div>

            <div class="filter-footer">
                <button type="submit" name="apply" value="1" class="apply-button">Generate Ranking</button>
            </div>
        </form>
    </section>

    <?php if (empty($errors)): ?>
        <section class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Ranked Products</div>

                <div class="summary-value">
                    <?= number_format(count($products)) ?>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label">Reporting Period</div>
                <div class="summary-value">
                    <?= htmlspecialchars(date('d M Y', strtotime($from))) ?>
                    &ndash;
                    <?= htmlspecialchars(date('d M Y', strtotime($to))) ?>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label">Region</div>

                <div class="summary-value">
                    <?= htmlspecialchars(
                        ALLOWED_REGIONS[$regionFilter]
                    ) ?>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Product Type</div>

                <div class="summary-value">
                    <?= htmlspecialchars(
                        getProductTypeLabel($selectedType)
                    ) ?>
                </div>
            </div>
        </section>

        <div class="ranking-grid">

            <section class="card ranking-card top-card">
                <div class="card-title">
                    Top 10 by Total Sales
                </div>

                <div class="card-subtitle">
                    Highest qualifying converted sales.
                </div>

                <div class="table-wrap">
                    <table class="ranking-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Quantity</th>
                                <th>Total Sales</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php renderProductRows($rankings['top']); ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card ranking-card bottom-card">
                <div class="card-title">
                    Bottom 10 by Total Sales
                </div>

                <div class="card-subtitle">
                    Lowest positive qualifying sales.
                </div>

                <div class="table-wrap">
                    <table class="ranking-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Quantity</th>
                                <th>Total Sales</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php renderProductRows($rankings['bottom']); ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    <?php endif; ?>

    </div>

</main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('report-filter');
    const results = document.getElementById('report-results');
    const button = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

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
            const nextDocument = new DOMParser().parseFromString(html, 'text/html');
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
});
</script>

</body>
</html>