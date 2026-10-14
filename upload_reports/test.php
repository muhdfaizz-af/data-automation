<?php
function loadPromoWeights(PDO $pdo, string $sku, string $priceField): array
{
    if (!in_array($priceField, ['wmdp', 'sbhdp', 'emdp', 'sgdp'], true)) {
        throw new InvalidArgumentException('Invalid regional price field.');
    }

    $statement = $pdo->prepare(
        'SELECT loose_items FROM composite_items
         WHERE UPPER(TRIM(item_code)) = :sku LIMIT 1'
    );
    $statement->execute(['sku' => strtoupper(trim($sku))]);
    $json = $statement->fetchColumn();

    if (!is_string($json)) {
        throw new RuntimeException("Missing component mapping for {$sku}.");
    }

    $items = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($items) || !$items) {
        throw new RuntimeException("Empty component mapping for {$sku}.");
    }

    $quantities = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new RuntimeException("Invalid component in {$sku}.");
        }

        $code = strtoupper(trim((string) ($item['item_code'] ?? '')));
        $quantity = filter_var(
            $item['quantity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($code === '' || $quantity === false) {
            throw new RuntimeException("Invalid component SKU or quantity in {$sku}.");
        }

        $quantities[$code] = ($quantities[$code] ?? 0) + $quantity;
    }

    $placeholders = implode(',', array_fill(0, count($quantities), '?'));
    $statement = $pdo->prepare(
        "SELECT product_sku, product_name, brand, {$priceField} AS unit_price
         FROM price_code
         WHERE UPPER(TRIM(product_sku)) IN ({$placeholders})"
    );
    $statement->execute(array_keys($quantities));

    $products = [];
    foreach ($statement->fetchAll() as $product) {
        $products[strtoupper(trim($product['product_sku']))] = $product;
    }

    $weights = [];
    foreach ($quantities as $code => $quantity) {
        $product = $products[$code] ?? null;

        if (!$product || $product['unit_price'] === null ||
            (float) $product['unit_price'] <= 0) {
            throw new RuntimeException("Missing positive {$priceField} price for {$code}.");
        }

        // Preserve the existing category grouping for this pilot.
        $category = identifyProductCategory(
            (string) ($product['brand'] ?? ''),
            $code,
            (string) ($product['product_name'] ?? ''),
            'NORMAL'
        );

        if ($category === null) {
            throw new RuntimeException("No reporting category for {$code}.");
        }

        $weights[$category] = ($weights[$category] ?? 0)
            + $quantity * (float) $product['unit_price'];
    }

    return $weights;
}

function allocatePromoSales(float $sales, array $weights): array
{
    $totalWeight = array_sum($weights);
    if ($totalWeight <= 0) {
        throw new RuntimeException('Promo allocation has no positive weights.');
    }

    // Distribute cents so allocations equal the invoice total.
    $totalCents = (int) round(abs($sales) * 100);
    $cents = [];
    $fractions = [];

    foreach ($weights as $category => $weight) {
        $exact = $totalCents * $weight / $totalWeight;
        $cents[$category] = (int) floor($exact);
        $fractions[$category] = $exact - $cents[$category];
    }

    // Give remaining cents to the largest fractional allocations.
    $remaining = $totalCents - array_sum($cents);
    arsort($fractions, SORT_NUMERIC);

    foreach (array_keys($fractions) as $category) {
        if ($remaining <= 0) break;
        $cents[$category]++;
        $remaining--;
    }

    $sign = $sales < 0 ? -1 : 1;
    foreach ($cents as $category => $amount) {
        $cents[$category] = $sign * $amount / 100;
    }

    return $cents;
}