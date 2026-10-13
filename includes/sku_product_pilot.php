<?php
/** Catalogue comparison only; this does not replace the product rankings. */
require_once __DIR__ . '/overall_product_report.php';

function skuPilotCode(string $value): string
{
    return strtoupper(trim($value));
}

function buildSkuProductPilot(array $invoiceRows, array $master, array $composites): array
{
    $sku = skuPilotCode((string) $master['product_sku']);
    $legacyCategory = identifyProductCategory(
        (string) ($master['brand'] ?? ''), $sku,
        (string) ($master['product_name'] ?? ''), 'NORMAL'
    );
    $legacyReport = consolidateOverallProductRows($invoiceRows);
    $legacy = ['total_quantity' => 0, 'total_sales' => 0.0, 'source_codes' => []];
    foreach ($legacyReport['products'] as $product) {
        if ($product['category'] === $legacyCategory) {
            $legacy = $product;
            break;
        }
    }

    $mappings = [];
    $warnings = [];
    foreach ($composites as $composite) {
        $code = skuPilotCode((string) $composite['item_code']);
        try {
            $items = json_decode($composite['loose_items'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($items)) throw new UnexpectedValueException('Component list is not an array.');
            $components = [];
            foreach ($items as $item) {
                if (!is_array($item)) throw new UnexpectedValueException('Invalid component.');
                $itemSku = skuPilotCode((string) ($item['item_code'] ?? ''));
                $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($itemSku === '' || $quantity === false) throw new UnexpectedValueException('Invalid component SKU or quantity.');
                $components[$itemSku] = ($components[$itemSku] ?? 0) + $quantity;
            }
            if (!isset($components[$sku])) continue;
            if (isset($mappings[$code])) throw new UnexpectedValueException('Duplicate composite SKU.');
            $mappings[$code] = [
                'code' => $code,
                'description' => (string) ($composite['item_description'] ?? ''),
                'quantity' => $components[$sku],
                'mixed' => count($components) > 1,
            ];
        } catch (Throwable $exception) {
            $warnings[] = "Mapping {$code}: " . $exception->getMessage();
        }
    }

    $sources = [];
    $looseOrders = [];
    $mappedOrders = [];
    $quantity = 0;
    $sales = 0.0;
    $pendingBundleSales = 0.0;
    foreach ($invoiceRows as $row) {
        $code = skuPilotCode((string) $row['item_code']);
        $type = skuPilotCode((string) ($row['product_type'] ?? ''));
        $isLoose = $code === $sku && $type === 'NORMAL';
        $mapping = $mappings[$code] ?? null;
        $isLegacySource = in_array($code, $legacy['source_codes'], true);
        if (!$isLoose && $mapping === null && !$isLegacySource) continue;

        $rowSales = (float) $row['invoice_sales'];
        if ($row['company_code'] === 'SG') $rowSales *= SGD_TO_MYR_RATE;
        $rowQuantity = (int) $row['row_quantity'];
        $scope = $isLoose ? 'Loose product' : ($mapping === null ? 'Missing mapping' : ($mapping['mixed'] ? 'Mixed bundle — allocation pending' : 'Single-product pack'));
        $sourceKey = $code . '|' . $type . '|' . $row['company_code'];
        $sources[$sourceKey] ??= [
            'code' => $code, 'type' => $type, 'company' => $row['company_code'],
            'scope' => $scope, 'invoice_quantity' => 0, 'component_quantity' => $mapping['quantity'] ?? null,
            'mapped_units' => 0, 'invoice_sales' => 0.0, 'allocated_sales' => 0.0,
        ];
        $sources[$sourceKey]['invoice_quantity'] += $rowQuantity;
        $sources[$sourceKey]['invoice_sales'] += $rowSales;
        if ($isLoose) {
            $quantity += $rowQuantity;
            $looseOrders[(int) $row['order_id']] = true;
            $sales += $rowSales;
            $sources[$sourceKey]['allocated_sales'] += $rowSales;
        } elseif ($mapping !== null) {
            $mappedOrders[(int) $row['order_id']] = true;
            $sources[$sourceKey]['mapped_units'] += $rowQuantity * $mapping['quantity'];
            if ($mapping['mixed']) {
                // The full bundle value is shown for review, never assigned to one product.
                $pendingBundleSales += $rowSales;
            } else {
                $sales += $rowSales;
                $sources[$sourceKey]['allocated_sales'] += $rowSales;
            }
        }
    }
    ksort($sources);
    ksort($mappings);
    $missingLooseOrders = count(array_diff_key($mappedOrders, $looseOrders));
    if ($missingLooseOrders > 0) {
        $warnings[] = "{$missingLooseOrders} orders containing mapped packs have no {$sku} NORMAL row in this report scope. Quantity needs review; pack units were not added.";
    }
    return [
        'sku' => $sku, 'name' => (string) ($master['product_name'] ?? $sku),
        'legacy' => $legacy,
        'catalogue' => ['total_quantity' => $quantity, 'total_sales' => round($sales, 2)],
        'pending_bundle_sales' => round($pendingBundleSales, 2),
        'sources' => array_values($sources), 'mappings' => array_values($mappings), 'warnings' => $warnings,
    ];
}
