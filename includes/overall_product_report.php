<?php
/** Shared invoice filtering and existing overall-product calculations. */

define('SGD_TO_MYR_RATE', 3.27);
define('RANKING_LIMIT', 10);
define('BCD_UNIT_PRICE_MYR', 37.00);
define('ALLOWED_REGIONS', [
    'all' => 'All Regions',
    'SM' => 'Semenanjung',
    'BT' => 'Bintulu',
    'SG' => 'Singapore',
]);

function normalizeRegion($region): string
{
    return is_string($region) && array_key_exists($region, ALLOWED_REGIONS)
        ? $region
        : 'all';
}

function overallRegionCondition(string $region): string
{
    // Match the item-level region rules used by Nafesa Products.
    $isBintulu = "(
        UPPER(TRIM(COALESCE(oi.order_processed_location, ''))) = 'MYBTWH'
        OR UPPER(TRIM(COALESCE(o.invoice_prefix, ''))) = 'MYBT'
    )";

    return match ($region) {
        'BT' => "AND c.company_code = 'MY' AND {$isBintulu}",
        'SM' => "AND c.company_code = 'MY' AND NOT {$isBintulu}",
        'SG' => "AND c.company_code = 'SG'",
        default => "AND c.company_code IN ('MY', 'SG')",
    };
}


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
    $description = preg_replace('/\s*\((?:NORMAL|COMPOSITE)\)\s*/i', ' ', $description);
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

    // Exclude JOY CUP sets.
    if (str_starts_with($itemCode, 'BPCC-') || str_contains($description, 'JOY CUP')) {
        return null;
    }

    foreach ($excludedTerms as $term) {
        if (str_contains($description, $term)) {
            return null;
        }
    }

    // Belgian Chocolate Drink family
    if (
        preg_match('/^(?:BCD-002|BCDC-002|CBCDA-002|STK-BCDS-002|JOY-BUNDLE-1|Q-BCD-002|Q-DRINKS4|Q-DRINKS4-C)(?:-|$)/', $itemCode) ||
        str_contains($description, '(BCDB) BOX BELGIAN CHOCOLATE DRINK') ||
        str_contains($description, 'BELGIAN CHOCOLATE DRINK')
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
    string $productType,
    string $description
): bool {
    $itemCode = strtoupper(trim($itemCode));
    $productType = strtoupper(trim($productType));
    $description = strtoupper(trim($description));
    $itemSearchText = $itemCode . ' ' . $description;

    if ($category === 'SCARF') {
        foreach ([
            'CHARM',
            'BRACELET',
            'KEYCHAIN',
            'ENAMEL PIN',
            'HANDSOCK',
            'HAND SOCK',
            'STK-NMJ04-MR',
            'STK-NMJ02-BG',
            'STK-NMJ06-RC',
            'STK-NRQ01-EM',
            'STK-NRW01-EM',
        ] as $excludedTerm) {
            if (str_contains($itemSearchText, $excludedTerm)) {
                return false;
            }
        }
    }

    return match ($category) {
        // Joy bundles already have their loose BCD quantity recorded separately.
        '(BCDB) BOX BELGIAN CHOCOLATE DRINK' => $itemCode === 'BCD-002',
        
        // CA-6 is the loose component generated from Unicorn cartons and packs. Not count CA-006/CAC-011
        'UNICORN STRAWBERRY CHOCOLATE TUB' => $itemCode === 'CA-6',

        'CUTIE MINI CHOCO CRUNCH TUB' => $itemCode === 'CA-9',

        'CUTIE CHOCO BALL TUB' => $itemCode === 'CA-8',

        'CUTIE MINI CHOCO DORAYAKI TUB' => $itemCode === 'CA-13',

        'CUTIE CHOCO RICE TUB' => $itemCode === 'CA-12',

        'PISTACHIO DREAM TUB' => $itemCode === 'CA-15',

        'COTTON CANDY CHOCOLATE TUB' => $itemCode === 'CA-10',

        // Reference Zeky quantity comes from its loose component
        'ZEKY BRAIN HERO' => $itemCode === 'ZEKY-BH',

        // Brazillian Coffee loose component
        'BRAZILIAN COFFEE DRINK' => $itemCode === 'BRC-001',

        // Nafesa scarves
        'SCARF' => $productType === 'NORMAL' || (bool)preg_match('/^STK-N(?!F(?:-|$))/', $itemCode),
        'INNER' => $productType === 'NORMAL',

        // Other categories use normal/loose rows only
        default => $productType === 'NORMAL',
    };
}

// Load the confirmed daily sales total for the selected reporting period.
function getDailySalesTotal(
    PDO $pdo,
    string $from,
    string $to
): float {
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(
            CASE
                WHEN c.company_code = 'SG'
                THEN o.sub_total * :sg_rate
                ELSE o.sub_total
            END
        ), 0) AS daily_sales
        FROM orders o
        INNER JOIN companies c ON c.id = o.company_id
        WHERE o.order_datetime >= :from_date
          AND o.order_datetime < :to_exclusive
          AND o.order_status = :confirmed_status
          AND c.company_code IN ('MY', 'SG')"
    );

    $statement->execute([
        'sg_rate' => SGD_TO_MYR_RATE,
        'from_date' => $from . ' 00:00:00',
        'to_exclusive' => (new DateTimeImmutable($to))
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00'),
        'confirmed_status' => 'confirmed',
    ]);

    return round((float)$statement->fetchColumn(), 2);
}

// Keep the rankings and catalogue pilot on the same invoice filters.
function getOverallProducts(
    PDO $pdo,
    string $from,
    string $to,
    string $regionFilter = 'all'
): array {
    return consolidateOverallProductRows(loadOverallProductRows($pdo, $from, $to, $regionFilter));
}

function loadOverallProductRows(
    PDO $pdo,
    string $from,
    string $to,
    string $regionFilter = 'all'
): array {
    $params = [
        'from_date' => $from . ' 00:00:00',

        // Exclusive end date includes the entire selected final day
        'to_exclusive' => (
            new DateTimeImmutable($to)
        )->modify('+1 day')->format('Y-m-d 00:00:00'),

        'confirmed_status' => 'confirmed',
    ];

    $regionCondition = overallRegionCondition($regionFilter);

    $sql = "
    SELECT
        oi.order_id,
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
            {$regionCondition}

            AND UPPER(TRIM(COALESCE(oi.brand, ''))) IN (
                'CHOCO ALBAB',
                'NAFESA',
                'ZEKY',
                'STK'
            )

            GROUP BY
                oi.order_id,
                UPPER(TRIM(COALESCE(oi.brand, ''))),
                oi.product_type,
                oi.item_code,
                oi.item_description,
                c.company_code
        ";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function consolidateOverallProductRows(array $rows): array
{
    $categories = [];
    $overallSales = 0.00;
    $soldScarfCodes = [];
    $joyBundleOrderIds = [];
    $joyCupLooseQuantities = [];

    foreach ($rows as $row) {
        $itemCode = strtoupper(trim((string)$row['item_code']));
        $orderId = (int)$row['order_id'];

        if ($itemCode === 'JOY-BUNDLE-1') {
            $joyBundleOrderIds[$orderId] = true;
        }

        if (in_array($itemCode, ['BPC-001', 'BPC-002'], true)) {
            $joyCupLooseQuantities[$orderId][$itemCode] =
                ($joyCupLooseQuantities[$orderId][$itemCode] ?? 0) +
                (int)$row['row_quantity'];
        }
    }

    /*
     * Record every Nafesa scarf master code with positive sales. 
     * Sales may be held by a COMPOSITE Q-code while quantity is held by its
     * corresponding NORMAL/loose code.
     */
    foreach ($rows as $row) {
        $brand = strtoupper(trim((string)$row['brand']));
        $itemCode = (string)$row['item_code'];
        $description = (string)($row['item_description'] ?? '');
        $productType = (string)($row['product_type'] ?? '');

        $category = identifyProductCategory(
            $brand,
            $itemCode,
            $description,
            $productType
        );

        if ($brand !== 'NAFESA' || $category !== 'SCARF') {
            continue;
        }

        $rowSales = (float)$row['invoice_sales'];

        if ($row['company_code'] === 'SG') {
            $rowSales *= SGD_TO_MYR_RATE;
        }

        if ($rowSales > 0) {
            $soldScarfCodes[
                canonicalizeReportingCode($itemCode)
            ] = true;
        }
    }

    foreach ($rows as $row) {
        $brand = strtoupper(trim((string)$row['brand']));
        $itemCode = (string)$row['item_code'];
        $description = (string)($row['item_description'] ?? '');
        $productType = (string)($row['product_type'] ?? '');
        $sales = (float)$row['invoice_sales'];
        $normalizedItemCode = strtoupper(trim($itemCode));
        $isJoyBundle = $normalizedItemCode === 'JOY-BUNDLE-1';
        $isJoyBundleLoose =
            $normalizedItemCode === 'BCD-002' &&
            isset($joyBundleOrderIds[(int)$row['order_id']]);
        $joyCupQuantitySource = match ($normalizedItemCode) {
            'BPCC-001', 'BPCC-002' => 'BPC-001',
            'BPCC-003' => 'BPC-002',
            default => null,
        };

        $category = identifyProductCategory(
            $brand,
            $itemCode,
            $description,
            $productType
        );

        // Only JOY-BUNDLE-1 uses its loose BCD quantity for sales.
        if ($isJoyBundle) {
            continue;
        }

        if ($isJoyBundleLoose) {
            $sales = (float)$row['row_quantity'] * BCD_UNIT_PRICE_MYR;
        } elseif ($row['company_code'] === 'SG') {
            $sales *= SGD_TO_MYR_RATE;
        }

        $overallSales += $sales;
        $upperDescription = strtoupper($description);

        // Divide mixed-carton sales equally between both products.
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
            $allocations = $category === null
                ? []
                : [$category => 1.0];
        }

        foreach ($allocations as $category => $salesShare) {
            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'category' => $category,
                    'total_quantity' => 0,
                    'total_sales' => 0.00,
                    'source_codes' => [],
                ];
            }

            $categories[$category]['total_sales'] +=
                $sales * $salesShare;

            $isPositiveSaleScarf = true;

            if ($category === 'SCARF' && $brand === 'NAFESA') {
                $isPositiveSaleScarf = isset(
                    $soldScarfCodes[
                        canonicalizeReportingCode($itemCode)
                    ]
                );
            }

            if (
                $salesShare === 1.0 &&
                $isPositiveSaleScarf &&
                isCategoryQuantityRow(
                    $category,
                    $itemCode,
                    $productType,
                    $description
                )
            ) {
                $categories[$category]['total_quantity'] +=
                    (int)$row['row_quantity'];
            }

            if (
                $salesShare === 1.0 &&
                $joyCupQuantitySource !== null &&
                isset($joyCupLooseQuantities[(int)$row['order_id']][$joyCupQuantitySource])
            ) {
                $categories[$category]['total_quantity'] +=
                    $joyCupLooseQuantities[(int)$row['order_id']][$joyCupQuantitySource];
            }

            $normalizedSourceCode = strtoupper(trim($itemCode));

            if ($normalizedSourceCode !== '') {
                $categories[$category]['source_codes'][
                    $normalizedSourceCode
                ] = true;
            }
        }
    }

    $result = [];

    foreach ($categories as $category) {
        if ($category['total_sales'] <= 0) {
            continue;
        }

        $category['total_sales'] = round($category['total_sales'], 2);
        $category['source_codes'] = array_keys($category['source_codes']);
        sort($category['source_codes']);
        $result[] = $category;
    }

    return [
        'products' => $result,
        'overall_sales' => round($overallSales, 2),
    ];
}

/**
 * Convert composite and preorder codes to their loose master code.
 */
function canonicalizeReportingCode(string $itemCode): string
{
    $itemCode = strtoupper(trim($itemCode));

    $itemCode = preg_replace(
        '/^(?:(?:PREORDER|PRE|Q)-)+/i',
        '',
        $itemCode
    );

    return trim((string)$itemCode);
}

