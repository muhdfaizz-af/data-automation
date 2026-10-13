<?php
require_once __DIR__ . '/../includes/sku_product_pilot.php';

function checkPilot($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function pilotInvoice(string $code, string $type, int $qty, float $sales, string $company = 'MY', int $order = 1): array
{
    return ['order_id' => $order, 'brand' => 'CHOCO ALBAB', 'product_type' => $type,
        'item_code' => $code, 'item_description' => 'BRAZILIAN COFFEE DRINK',
        'company_code' => $company, 'row_quantity' => $qty, 'invoice_sales' => $sales];
}

$master = ['product_sku' => 'BRC-001', 'product_name' => 'BRAZILIAN COFFEE DRINK', 'brand' => 'CHOCO ALBAB'];
$composites = [
    ['item_code' => 'BRCC-001', 'loose_items' => '[{"item_code":"BRC-001","quantity":18}]'],
    ['item_code' => 'Q-BRC-001', 'loose_items' => '[{"item_code":"BRC-001","quantity":4}]'],
    ['item_code' => 'PRE-BRC-001', 'status' => 'DISABLED', 'loose_items' => '[{"item_code":"BRC-001","quantity":1}]'],
    ['item_code' => 'DRINKS4', 'loose_items' => '[{"item_code":"BRC-001","quantity":1},{"item_code":"BMD-001","quantity":1}]'],
];
$rows = [
    pilotInvoice('BRC-001', 'normal', 20, 60),
    pilotInvoice('BRCC-001', 'composite', 1, 540),
    pilotInvoice('Q-BRC-001', 'composite', 2, 180),
    pilotInvoice('PRE-BRC-001', 'composite', 1, 30),
    pilotInvoice('BRC-001', 'normal', 1, 9.80, 'SG'),
    pilotInvoice('DRINKS4', 'composite', 1, 120),
    pilotInvoice('UNMAPPED', 'composite', 1, 15),
];
$report = buildSkuProductPilot($rows, $master, $composites);
checkPilot($report['catalogue']['total_quantity'], 21, 'Composite units must not double loose invoice quantity');
checkPilot($report['catalogue']['total_sales'], 842.05, 'Actual invoice sales, historical disabled packs and SG conversion');
checkPilot($report['pending_bundle_sales'], 120.0, 'Mixed bundle sales must remain unallocated');
checkPilot($report['legacy']['total_sales'], 977.05, 'Legacy classification remains independent, including its description matching');
$sources = array_column($report['sources'], null, 'code');
checkPilot($sources['BRCC-001']['mapped_units'], 18, 'Carton units are diagnostic');
checkPilot($sources['Q-BRC-001']['mapped_units'], 8, 'Pack quantity times configured component units');
checkPilot($sources['UNMAPPED']['scope'], 'Missing mapping', 'Legacy-only codes must be exposed for review');
checkPilot($sources['UNMAPPED']['allocated_sales'], 0.0, 'Unknown mappings must not invent catalogue attribution');
checkPilot($sources['DRINKS4']['allocated_sales'], 0.0, 'Full mixed bundle value must not be assigned to coffee');

$changed = $composites;
$changed[0]['loose_items'] = '[{"item_code":"BRC-001","quantity":24}]';
$updated = buildSkuProductPilot($rows, $master, $changed);
checkPilot(array_column($updated['sources'], null, 'code')['BRCC-001']['mapped_units'], 24, 'Catalogue edits update mapped units');
checkPilot($updated['catalogue']['total_sales'], $report['catalogue']['total_sales'], 'Pack size changes must not reprice historic invoices');

$missing = buildSkuProductPilot([pilotInvoice('BRCC-001', 'composite', 1, 540)], $master, $composites);
checkPilot($missing['catalogue']['total_quantity'], 0, 'Missing loose rows must not silently trigger pack expansion');
checkPilot(count($missing['warnings']), 1, 'Orders without loose invoice rows need review');

$invalid = buildSkuProductPilot($rows, $master, [['item_code' => 'BRCC-001', 'loose_items' => '[{"item_code":"BRC-001","quantity":0}]']]);
checkPilot(count($invalid['warnings']), 1, 'Invalid mapping quantities must be reported');
$empty = buildSkuProductPilot([], $master, $composites);
checkPilot($empty['catalogue'], ['total_quantity' => 0, 'total_sales' => 0.0], 'Empty reporting period');
echo "SKU product pilot checks passed.\n";
