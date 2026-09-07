<?php
/**
 * Top and Bottom Product Report
 *
 * Source:
 * - Tax Invoice MY and SG, stored in order_items
 *
 * Rules:
 * - Include Choco Albab and Zeky products
 * - Include Nafesa scarves, excluding known accessories
 * - Include all regions
 * - Consolidate codes representing the same product/design
 * - Convert SG invoice amounts to MYR
 * - Rank by total sales
 * - Exclude zero/negative sales from Bottom 5
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

// Define SG exchange rate
define('SGD_TO_MYR_RATE', 3.27);

// Define all brands to measure the top and bottom 5 products
define('ALLOWED_BRANDS', [
    'NAFESA',
    'CHOCO ALBAB',
    'ZEKY',
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
$activeNav = 'top_bottom';
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

    return '';
}

if ($days > 731) {
    return 'The reporting period cannot exceed 732 days.';
}

/**
 * Validate the company filter
 */
function normalizeCompany($company): string
{
    if (!is_string($company)) {
        return 'all';
    }

    return in_array($company, ['all', 'MY', 'SG'], true)
        ? $company
        : 'all';
}

/**
 * Validate submitted brands.
 */
function normalizeBrands($brands): array
{
    if (!is_array($brands)) {
        return[];
    }

    $brands = array_map(
        static fn($brand) => strtoupper(trim((string)$brand)),
        $brands
    );

    return array_values(array_unique(array_intersect(
        ALLOWED_BRANDS,
        $brands
    )));
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
 * Determine whether a Nafesa item is a scarf rather than an accessory.
 *
 * The database has a brand column but does not have a dedicated
 * "scarf" category. Therefore, known accessory code families are
 * excluded here.
 */
function isNafesaScarf(
    string $brand,
    string $itemCode,
    string $description
): bool {
    if ($brand !== 'NAFESA') {
        return true;
    }

    $code = canonicalizeItemCode($itemCode);
    $description = strtoupper(trim($description));

    // Known Nafesa non-scarf SKU families.
    $excludedCodePatterns = [
        '/^NTU/',     // Inner Tube
        '/^NCH/',     // Inner Chin
        '/^NST/',     // Inner Strap
        '/^NIN/',     // Inner Ninja
        '/^NAFESA-/', // Paper bags and general accessories
    ];

    foreach ($excludedCodePatterns as $pattern) {
        if (preg_match($pattern, $code)) {
            return false;
        }
    }

    $excludedDescriptionTerms = [
        'INNER TUBE',
        'INNER CHIN',
        'INNER STRAP',
        'INNER NINJA',
        'PAPERBAG',
        'PAPER BAG',
    ];

    foreach ($excludedDescriptionTerms as $term) {
        if (str_contains($description, $term)) {
            return false;
        }
    }

    return true;
}

/**
 * Load and consolidate qualifying Tax Invoice products.
 */
function getRankedProducts(
    PDO $pdo,
    string $from,
    string $to,
    string $companyFilter,
    array $brands
): array {
    if (empty($brands)) {
        return [];
    }

    $params = [
        'from_date'    => $from . ' 00:00:00',
        'to_exclusive' => (new DateTime($to))
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00'),
        'status'       => 'Confirmed',
    ];

    $brandPlaceholders = [];

    foreach ($brands as $index => $brand) {
        $parameterName = 'brand_' . $index;

        $brandPlaceholders[] = ':' . $parameterName;
        $params[$parameterName] = $brand;
    }

    $brandInClause = implode(', ', $brandPlaceholders);

    $companyCondition = '';

    if ($companyFilter !== 'all') {
        $companyCondition =
            ' AND c.company_code = :company_code';
        $params['company_code'] = $companyFilter;
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
        AND UPPER(TRIM(oi.brand)) IN ({$brandInClause})
        {$companyCondition}

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

    // Nafesa must contain scarves only.
    if (!isNafesaScarf($brand, $rawCode, $description)) {
        continue;
    }

    $masterCode = canonicalizeItemCode($rawCode);
    $key = $brand . '|' . $masterCode;

    if (!isset($products[$key])) {
        $products[$key] = [
            'brand'          => $brand,
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
    * Bottom 5 excludes products with zero sales.
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
 * Sort and return the Top 5 and Bottom 5.
 */
function splitRankings(array $products): array
{
    $topProducts = $products;

    usort(
        $topProducts,
        static function (array $a, array $b): int {
            $salesComparison =
                $b['total_sales'] <=> $a['total_sales'];
            
                if ($salesComparison !==0 ) {
                    return $salesComparison;
                }

                return strcmp(
                    $a['product_name'],
                    $b['product_name']
                );
        }
    );

    $bottomProducts = $products;

    usort(
        $bottomProducts,
        static function (array $a, array $b): int {
            $salesComparison =
                $a['total_sales'] <=> $b['total_sales'];

            if ($salesComparison !== 0) {
                return $salesComparison;
            }

            return strcmp(
                $a['product_name'],
                $b['product_name']
            );
        }
    );

    return [
        'top'    => array_slice($topProducts, 0, 5),
        'bottom' => array_slice($bottomProducts, 0, 5),
    ];
}

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');

$from = is_string($_GET['from'] ?? null)
    ? $_GET['from']
    : $defaultFrom;

$to = is_string($_GET['to'] ?? null)
    ? $_GET['to']
    : $defaultTo;

$companyFilter = normalizeCompany(
    $_GET['company'] ?? 'all'
);

$isSubmitted = isset($_GET['apply']);

$selectedBrands = normalizeBrands(
    $_GET['brands'] ??
    ($isSubmitted ? [] : ALLOWED_BRANDS)
);

$errors = [];

$periodError = validatePeriod($from, $to);

if ($periodError !== '') {
    $errors[] = $periodError;
}

if (empty($selectedBrands)) {
    $errors[] = 'Please select at least one brand.';
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
            $companyFilter,
            $selectedBrands
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

    foreach ($products as $index => $product) {
        $codes = implode(', ', $product['source_codes']);
        ?>
        <tr>
            <td>
                <span class="rank-number"><?= $index + 1 ?></span>
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
                    <?= htmlspecialchars($product['brand']) ?>
                </span>
            </td>

            <td>
                <?= number_format($product['total_quantity']) ?>
            </td>

            <td class="sales-value">
                RM<?= number_format($product['total_sales'], 2) ?>
            </td>

            <td class="source-codes">
                <?= htmlspecialchars($codes) ?>
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
<title>Top &amp; Bottom Products — S ASIA SALES REPORT</title>
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
.field label, .filter-label {color:var(--gray-700);font-size:10.5px;font-weight:800;letter-spacing:.35px;text-transform:uppercase;}
.field input,
.field select {width:100%;min-height:43px;padding:10px 12px;border:1.5px solid var(--gray-300);border-radius:9px;background:var(--white);outline:none;}
.field input:focus, .field select:focus {border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.10);}

/* ── BRAND SECTION ── */
.brand-section {margin-top:18px;}
.brand-options {display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;}
.brand-option {display:flex;align-items:center;gap:8px;padding:10px 13px;border:1.5px solid var(--gray-300);border-radius:9px;background:var(--white);cursor:pointer;font-size:12px;font-weight:700;}
.brand-option:has(input:checked) {border-color:var(--red);background:#FFF5F5;}
.brand-option input {width:16px;height:16px;accent-color:var(--red);}
.filter-footer {display:flex;justify-content:flex-end;margin-top:18px;}

/* ── APPLY BUTTON ── */
.apply-button {min-width:180px;padding:11px 20px;border:0;border-radius:9px;background:var(--red);color:var(--white);cursor:pointer;font-size:13px;font-weight:800;}
.apply-button:hover {background:var(--red-dark);}
.error-box,
.info-box {margin-bottom:20px;padding:13px 15px;border-radius:10px;font-size:12px;line-height:1.6;}
.error-box {border:1px solid #FECACA;background:#FEF2F2;color:#991B1B;}
.info-box {border:1px solid #BFDBFE;background:#EFF6FF;color:#1E3A8A;}

/* ── SUMMARY SECTION ── */
.summary-grid {display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:24px;}
.summary-card {padding:18px;border:1px solid var(--gray-100);border-radius:var(--radius-md);background:var(--white);box-shadow:var(--shadow-card);}
.summary-label {margin-bottom:5px;color:var(--gray-500);font-size:10px;font-weight:800;text-transform:uppercase;}
.summary-value {font-size:21px;font-weight:800;}

/* ── RANKING SECTION ── */
.ranking-grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;}
.ranking-card {min-width:0;}
.table-wrap {margin-top:18px;overflow-x:auto;}
.ranking-table {width:100%;min-width:760px;border-collapse:collapse;}
.ranking-table th, .ranking-table td {padding:12px;border-bottom:1px solid var(--gray-100);text-align:left;vertical-align:top;font-size:12px;}
.ranking-table th {color:var(--gray-500);font-size:10px;letter-spacing:.3px;text-transform:uppercase;}
.ranking-table th:nth-child(4),.ranking-table td:nth-child(4),.ranking-table th:nth-child(5),
.ranking-table td:nth-child(5) {text-align:right;}
.rank-number {display:inline-flex;width:25px;height:25px;align-items:center;justify-content:center;border-radius:50%;background:var(--gray-100);font-weight:800;}
.product-code,.source-codes {margin-top:4px;color:var(--gray-500);font-size:10px;line-height:1.4;}
.brand-badge {display:inline-flex;min-width:76px;min-height:32px;padding:5px 10px;align-items:center;justify-content:center;border-radius:20px;background:var(--gray-100);font-size:9px;font-weight:800;line-height:1.15;text-align:center;}
.sales-value {color:var(--red);font-weight:800;white-space:nowrap;}
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
$pageTitle = 'Top & Bottom Products';
include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="layout">
<main class="main">

    <div class="page-header">
        <h1>Top &amp; Bottom Products</h1>
        <p>Rank Tax Invoice MY and SG products by converted total sales.</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error-box">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="info-box">
        Bottom 5 includes only products with at least one sold unit and
        positive sales during the selected reporting period. All regions
        are included.
    </div>

    <section class="card">
        <div class="card-title">Report Filters</div>

        <div class="card-subtitle">
            Select the period, company and brands to include.
        </div>

        <form method="get" action="top_bottom.php">
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
                    <label for="company">Company</label>
                    <select id="company" name="company">
                        <option value="all" <?= $companyFilter === 'all' ? 'selected' : '' ?>>All Companies
                        </option>
                        <option value="MY" <?= $companyFilter === 'MY' ? 'selected' : '' ?>>Malaysia
                        </option>
                        <option value="SG" <?= $companyFilter === 'SG' ? 'selected' : '' ?>>Singapore
                        </option>
                    </select>
                </div>
            </div>

            <div class="brand-section">
                <div class="filter-label">Brand / Type</div>
                <div class="brand-options">
                    <?php foreach (ALLOWED_BRANDS as $brand): ?>
                        <label class="brand-option">
                            <input type="checkbox" name="brands[]" value="<?= htmlspecialchars($brand) ?>" <?= in_array($brand, $selectedBrands, true) ? 'checked' : '' ?>>
                            <span><?= $brand === 'NAFESA' ? 'Nafesa — Scarf only' : htmlspecialchars($brand) ?></span>
                        </label>
                    <?php endforeach; ?>
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
                <div class="summary-label">
                    Ranked Products
                </div>

                <div class="summary-value">
                    <?= number_format(count($products)) ?>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label">
                    Reporting Period
                </div>

                <div class="summary-value">
                    <?= htmlspecialchars(
                        date('d M Y', strtotime($from))
                    ) ?>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label">
                    Currency
                </div>

                <div class="summary-value">MYR</div>
            </div>
        </section>

        <div class="ranking-grid">

            <section class="card ranking-card top-card">
                <div class="card-title">
                    Top 5 by Total Sales
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
                                <th>Brand</th>
                                <th>Quantity</th>
                                <th>Total Sales</th>
                                <th>Consolidated Codes</th>
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
                    Bottom 5 by Total Sales
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
                                <th>Brand</th>
                                <th>Quantity</th>
                                <th>Total Sales</th>
                                <th>Consolidated Codes</th>
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

</main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector(
        'form [action="top_bottom.php"]'
    );

    const checkboxes = Array.from(
        document.querySelectorAll('input[name="brands[]"]')
    );

    if (!form) {
        return;
    }

    form.addEventListener('submit', function (event) {
        const hasSelectedBrand = checkboxes.some(
            function (checkbox) {
                return checkbox.checked;
            }
        );

        if (!hasSelectedBrand) {
            event.preventDefault();
            alert('Please select at least one brand.');
            checkboxes[0].focus();
        }
    });
});
</script>

</body>
</html>
