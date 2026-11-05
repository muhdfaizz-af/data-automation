<?php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST .
    ';port=' . DB_PORT .
    ';dbname=' . DB_NAME .
    ';charset=' . DB_CHARSET,
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

// Require an authenticated admin (moved ABOVE every import/POST handler).
if (empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

if (
    !empty($_SESSION['last_activity']) &&
    time() - $_SESSION['last_activity'] > 7200
) {
    session_unset();
    session_destroy();

    header('Location: ../index.php?expired=1');
    exit;
}

$_SESSION['last_activity'] = time();
$_SESSION['composite_csrf'] ??= bin2hex(random_bytes(32));

// Download Excel import templates (generated on the fly, matches the import parsers).
if (in_array($_GET['download'] ?? '', ['composite_template', 'normal_template'], true)) {
    $isComposite = $_GET['download'] === 'composite_template';
    $templateSheet = new Spreadsheet();
    $ws = $templateSheet->getActiveSheet();

    if ($isComposite) {
        $ws->setTitle('Composite');
        $ws->fromArray([
            ['Company Code', 'Composite SKU', 'Composite Name', 'Normal SKU', 'Qty', 'Status'],
            ['ALL', 'DRINKS4', 'COMBO DRINKS X4', 'AC-010', 2, ''],
            ['ALL', 'DRINKS4', 'COMBO DRINKS X4', 'AC-011', 2, ''],
        ], null, 'A1');
        $ws->getStyle('A1:F1')->getFont()->setBold(true);
        foreach (range('A', 'F') as $col) {
            $ws->getColumnDimension($col)->setAutoSize(true);
        }
        $templateName = 'composite_sku_template.xlsx';
    } else {
        $ws->setTitle('Loose SKU');
        $ws->fromArray([
            ['Product SKU', 'Product Name', 'Brand', 'WMDP', 'SBHDP', 'EMDP', 'SG'],
            ['', '', '', 'Unit Price', 'Unit Price', 'Unit Price', 'Unit Price'],
            ['AC-010', 'DARK CHOCOLATE COOKIES', 'CHOCO ALBAB', 15.00, 15.50, 16.00, 18.00],
        ], null, 'A1');
        $ws->getStyle('A1:G2')->getFont()->setBold(true);
        foreach (range('A', 'G') as $col) {
            $ws->getColumnDimension($col)->setAutoSize(true);
        }
        $templateName = 'loose_sku_template.xlsx';
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $templateName . '"');
    header('Cache-Control: private, no-store');
    (new Xlsx($templateSheet))->save('php://output');
    exit;
}

// Remember the active mode in the session so the URL can stay static.
if (isset($_GET['mode'])) {
    $_SESSION['sku_mode'] = ($_GET['mode'] === 'normal') ? 'normal' : 'composite';
}
$mode = $_SESSION['sku_mode'] ?? 'composite';

$importMessage = '';
$importError = '';

// Import composite SKUs from Excel.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'import_excel'
) {
    try {
        if (
            !is_string($_POST['csrf'] ?? null) ||
            !hash_equals($_SESSION['composite_csrf'], $_POST['csrf'])
        ) {
            throw new RuntimeException('Your form expired. Reload this page and try again.');
        }

        if (
            !isset($_FILES['excel_file']) ||
            $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('Please select a valid Excel file.');
        }

        $extension = strtolower(
            pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION)
        );

        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException(
                'Only XLSX, XLS, and CSV files are supported.'
            );
        }

        $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, false);

        if (count($rows) < 2) {
            throw new RuntimeException('The Excel file contains no data.');
        }

        $headers = array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            array_shift($rows)
        );

        $headerIndex = array_flip($headers);

        $requiredHeaders = [
            'company code',
            'composite sku',
            'composite name',
            'normal sku',
            'qty'
        ];

        foreach ($requiredHeaders as $requiredHeader) {
            if (!array_key_exists($requiredHeader, $headerIndex)) {
                throw new RuntimeException(
                    "Missing required column: {$requiredHeader}"
                );
            }
        }

        $grouped = [];

        foreach ($rows as $excelRowNumber => $row) {
            $getValue = static function (string $header) use (
                $row,
                $headerIndex
            ): string {
                $index = $headerIndex[$header];

                return trim((string) ($row[$index] ?? ''));
            };

            $companyCode = strtoupper($getValue('company code'));
            $compositeSku = strtoupper($getValue('composite sku'));
            $compositeName = strtoupper($getValue('composite name'));
            $normalSku = strtoupper($getValue('normal sku'));
            $quantity = (int) $getValue('qty');

            if (
                $companyCode === '' ||
                $compositeSku === '' ||
                $normalSku === ''
            ) {
                continue;
            }

            if ($quantity <= 0) {
                throw new RuntimeException(
                    'Invalid quantity on Excel row ' .
                    ($excelRowNumber + 2)
                );
            }

            $groupKey = $compositeSku;

            if (!isset($grouped[$groupKey])) {
                $status = array_key_exists('status', $headerIndex)
                    ? strtoupper($getValue('status'))
                    : null;

                $grouped[$groupKey] = [
                    'company_code' => 'ALL',
                    'item_code' => $compositeSku,
                    'item_description' => $compositeName,
                    'product_type' => 'composite',
                    'status' => $status ?: null,
                    'loose_items' => []
                ];
            }

            $grouped[$groupKey]['loose_items'][] = [
                'item_code' => $normalSku,
                'quantity' => $quantity
            ];
        }

        if (!$grouped) {
            throw new RuntimeException(
                'No valid composite records were found.'
            );
        }

        $componentSkus = [];

        foreach ($grouped as $composite) {
            foreach ($composite['loose_items'] as $component) {
                $componentSkus[] = $component['item_code'];
            }
        }

        $componentSkus = array_values(array_unique($componentSkus));

        $placeholders = implode(
            ',',
            array_fill(0, count($componentSkus), '?')
        );

        $priceStatement = $pdo->prepare("
            SELECT UPPER(product_sku) AS product_sku
            FROM price_code
            WHERE UPPER(product_sku) IN ($placeholders)
        ");

        $priceStatement->execute($componentSkus);

        $knownSkus = array_flip(
            $priceStatement->fetchAll(PDO::FETCH_COLUMN)
        );

        $missingSkus = [];

        foreach ($grouped as $composite) {
            foreach ($composite['loose_items'] as $component) {
                if (!isset($knownSkus[$component['item_code']])) {
                    $missingSkus[] = $component['item_code'];
                }
            }
        }

        $missingSkus = array_values(array_unique($missingSkus));

        $missingWarning = $missingSkus
            ? ' Warning: components not found in price_code were retained: ' . implode(', ', $missingSkus)
            : '';

        $pdo->beginTransaction();

        $saveStatement = $pdo->prepare("
            INSERT INTO composite_items
            (
                company_code,
                product_type,
                item_code,
                item_description,
                status,
                loose_items
            )
            VALUES
            (
                :company_code,
                :product_type,
                :item_code,
                :item_description,
                :status,
                :loose_items
            )
            ON DUPLICATE KEY UPDATE
                item_description = VALUES(item_description),
                status = VALUES(status),
                loose_items = VALUES(loose_items),
                updated_at = CURRENT_TIMESTAMP
        ");

        $removeExisting = $pdo->prepare(
            'DELETE FROM composite_items WHERE UPPER(TRIM(item_code)) = :item_code'
        );

        $savedCount = 0;

        foreach ($grouped as $composite) {
            $uniqueItems = [];
            foreach ($composite['loose_items'] as $component) {
                $uniqueItems[$component['item_code']] = $component;
            }
            $composite['loose_items'] = array_values($uniqueItems);

            // A composite SKU is global. Remove older company-specific rows
            // before inserting the single consolidated mapping.
            $removeExisting->execute([':item_code' => $composite['item_code']]);

            $saveStatement->execute([
                ':company_code' => $composite['company_code'],
                ':product_type' => $composite['product_type'],
                ':item_code' => $composite['item_code'],
                ':item_description' => $composite['item_description'],
                ':status' => $composite['status'],
                ':loose_items' => json_encode(
                    $composite['loose_items'],
                    JSON_THROW_ON_ERROR
                )
            ]);

            $savedCount++;
        }

        $pdo->commit();

        $importMessage = "{$savedCount} composite items imported successfully." . $missingWarning;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $importError = $exception->getMessage();
    }
}

// AJAX endpoint used by the loose-SKU edit modal.
if (($_GET['ajax'] ?? '') === 'normal_sku') {
    header('Content-Type: application/json; charset=utf-8');
    $sku = strtoupper(trim((string) ($_GET['sku'] ?? '')));
    if ($sku === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'SKU is required.']);
        exit;
    }
    $ajaxStatement = $pdo->prepare(
        'SELECT product_sku, product_name, brand, wmdp, sbhdp, emdp, sgdp
         FROM price_code WHERE product_sku = :sku LIMIT 1'
    );
    $ajaxStatement->execute([':sku' => $sku]);
    $skuRow = $ajaxStatement->fetch();
    if (!$skuRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'SKU was not found.']);
        exit;
    }
    echo json_encode(['ok' => true, 'sku' => $skuRow]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ajax_update_normal'
) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!is_string($_POST['csrf'] ?? null)
            || !hash_equals($_SESSION['composite_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('Your form expired. Reload the page.');
        }
        $sku = strtoupper(trim((string) ($_POST['product_sku'] ?? '')));
        $name = strtoupper(trim((string) ($_POST['product_name'] ?? '')));
        $brand = strtoupper(trim((string) ($_POST['brand'] ?? '')));
        if ($sku === '' || $name === '') {
            throw new InvalidArgumentException('SKU and product name are required.');
        }
        $params = [':sku'=>$sku, ':name'=>$name, ':brand'=>$brand ?: null];
        foreach (['wmdp', 'sbhdp', 'emdp', 'sgdp'] as $field) {
            $value = $_POST[$field] ?? '';
            $number = $value === '' ? null : (float) $value;
            if ($number !== null && $number < 0) throw new InvalidArgumentException('Prices cannot be negative.');
            $params[':' . $field] = $number;
        }
        $ajaxSave = $pdo->prepare(
            'UPDATE price_code SET product_name=:name, brand=:brand, wmdp=:wmdp,
             sbhdp=:sbhdp, emdp=:emdp, sgdp=:sgdp WHERE product_sku=:sku'
        );
        $ajaxSave->execute($params);
        echo json_encode(['ok' => true, 'message' => "Loose SKU {$sku} was updated successfully."]);
    } catch (Throwable $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'composite_sku') {
    header('Content-Type: application/json; charset=utf-8');
    $code = strtoupper(trim((string) ($_GET['sku'] ?? '')));
    $q = $pdo->prepare('SELECT item_code,item_description,loose_items FROM composite_items WHERE UPPER(TRIM(item_code))=:code LIMIT 1');
    $q->execute([':code'=>$code]); $row=$q->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Composite SKU was not found.']); exit; }
    $row['loose_items']=json_decode($row['loose_items'] ?? '[]',true) ?: [];
    echo json_encode(['ok'=>true,'composite'=>$row]); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='ajax_update_composite') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals($_SESSION['composite_csrf'], (string)($_POST['csrf']??''))) throw new InvalidArgumentException('Your form expired. Reload the page.');
        $code = strtoupper(trim((string) ($_POST['original_code'] ?? '')));
        if ($code === '') {
            throw new InvalidArgumentException('Composite SKU is required.');
        }

        // This AJAX endpoint runs before the normal page data is loaded.
        // Build the validation maps locally instead of using uninitialised globals.
        $ajaxProducts = [];
        $productQuery = $pdo->query(
            'SELECT product_sku, product_name
             FROM price_code'
        );
        foreach ($productQuery as $product) {
            $ajaxProducts[strtoupper(trim($product['product_sku']))] = $product;
        }

        $name = mb_strtoupper(trim((string) ($_POST['item_description'] ?? '')), 'UTF-8');
        $codes = is_array($_POST['loose_code'] ?? null) ? $_POST['loose_code'] : [];
        $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
        if ($name === '' || count($codes) === 0 || count($codes) !== count($quantities)) {
            throw new InvalidArgumentException('Composite name and loose items are required.');
        }
        $items = [];
        foreach ($codes as $index => $rawSku) {
            $sku = strtoupper(trim((string) $rawSku));
            $qty = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($sku === '' || !isset($ajaxProducts[$sku]) || $qty === false) {
                throw new InvalidArgumentException('Every loose SKU must exist and have a valid quantity.');
            }
            if (isset($items[$sku])) {
                throw new InvalidArgumentException("{$sku} appears twice.");
            }
            $items[$sku] = ['item_code'=>$sku, 'quantity'=>(int) $qty];
        }
        $q=$pdo->prepare("UPDATE composite_items SET item_description=:name, loose_items=:items WHERE UPPER(TRIM(item_code))=:code");
        $q->execute([':name'=>$name,':items'=>json_encode(array_values($items), JSON_THROW_ON_ERROR),':code'=>$code]);
        echo json_encode(['ok'=>true]);
    } catch(Throwable $e) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); }
    exit;
}

header('Cache-Control: private, no-store');

$activeNav = 'sku_setup';
$navBasePath = '../';
$pageTitle = 'Composite Setup';
$adminUsername = $_SESSION['admin_username'] ?? '';

function compositeEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
    );
}

function validateComposite(
    array $input,
    array $products,
    array $registeredComposites,
    ?string $originalCode = null
): array {
    if (
        !is_string($input['item_code'] ?? null) ||
        !is_string($input['item_description'] ?? null)
    ) {
        throw new InvalidArgumentException(
            'Enter a composite SKU and name.'
        );
    }

    $code = strtoupper(trim($input['item_code']));
    $name = mb_strtoupper(
        trim($input['item_description']),
        'UTF-8'
    );

    $originalCode = $originalCode !== null
        ? strtoupper(trim($originalCode))
        : null;

    if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,49}$/D', $code)) {
        throw new InvalidArgumentException(
            'Composite SKU must be 1-50 letters, numbers, dots, ' .
            'underscores, slashes or hyphens.'
        );
    }

    if ($name === '' || mb_strlen($name) > 255) {
        throw new InvalidArgumentException(
            'Composite name is required and must not exceed 255 characters.'
        );
    }

    if (isset($registeredComposites[$code]) && $code !== $originalCode)
    {
        throw new InvalidArgumentException(
            'This composite SKU already exists.'
        );
    }

    $codes = $input['loose_code'] ?? null;
    $quantities = $input['quantity'] ?? null;

    if (
        !is_array($codes) ||
        !is_array($quantities) ||
        count($codes) < 1 ||
        count($codes) > 100 ||
        array_keys($codes) !== array_keys($quantities)
    ) {
        throw new InvalidArgumentException(
            'Add between 1 and 100 loose items with matching quantities.'
        );
    }

    $items = [];
    $seen = [];

    foreach ($codes as $index => $sku) {
        if (
            !is_string($sku) ||
            !is_string($quantities[$index])
        ) {
            throw new InvalidArgumentException(
                'Invalid loose-item input.'
            );
        }

        $sku = strtoupper(trim($sku));

        if (!isset($products[$sku])) {
            throw new InvalidArgumentException(
                'Select every loose item from the product list.'
            );
        }

        if ($sku === $code || isset($registeredComposites[$sku])) {
            throw new InvalidArgumentException(
                'A composite cannot contain itself or another registered composite.'
            );
        }

        if (isset($seen[$sku])) {
            throw new InvalidArgumentException(
                "{$sku} appears twice. Use one row and increase its quantity."
            );
        }

        $quantity = filter_var(
            $quantities[$index],
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 1000000,
                ],
            ]
        );

        if ($quantity === false) {
            throw new InvalidArgumentException(
                "{$sku}: quantity must be a whole number from 1 to 1,000,000."
            );
        }

        $items[] = [
            'item_code' => $products[$sku]['product_sku'],
            'quantity' => $quantity,
        ];

        $seen[$sku] = true;
    }

    return [
        'item_code' => $code,
        'item_description' => $name,
        'loose_items' => json_encode($items, JSON_THROW_ON_ERROR),
    ];
}

$normalEditSku = strtoupper(trim((string) ($_GET['edit_normal'] ?? '')));
$normalEditing = null;
$normalSearch = trim((string) ($_GET['q'] ?? ''));
$normalPage = max(1, (int) ($_GET['page'] ?? 1));
$normalPerPage = 25;
$normalTotal = 0;
$normalProducts = [];
$normalPageCount = 1;
$compositeSearch = trim((string) ($_GET['composite_q'] ?? ''));
$compositePage = max(1, (int) ($_GET['composite_page'] ?? 1));
$compositePerPage = 25;
$compositeTotal = 0;
$compositePageCount = 1;
$displayComposites = [];

$error = null;
$success = $_SESSION['composite_success'] ?? null;

unset($_SESSION['composite_success']);

$products = [];
$composites = [];
$registeredComposites = [];

$productQuery = $pdo->query(
    'SELECT product_sku, product_name, brand, wmdp, sbhdp, emdp, sgdp
     FROM price_code
     ORDER BY product_sku'
);

foreach ($productQuery as $row) {
    $products[strtoupper(trim($row['product_sku']))] = $row;
}

$where = '';
$searchParams = [];
if ($normalSearch !== '') {
    $where = 'WHERE product_sku LIKE :search_sku OR product_name LIKE :search_name OR brand LIKE :search_brand';
    $searchParams = [
        ':search_sku' => '%' . $normalSearch . '%',
        ':search_name' => '%' . $normalSearch . '%',
        ':search_brand' => '%' . $normalSearch . '%',
    ];
}
$countStatement = $pdo->prepare("SELECT COUNT(*) FROM price_code {$where}");
$countStatement->execute($searchParams);
$normalTotal = (int) $countStatement->fetchColumn();
$normalPageCount = max(1, (int) ceil($normalTotal / $normalPerPage));
$normalPage = min($normalPage, $normalPageCount);
$normalOffset = ($normalPage - 1) * $normalPerPage;
$normalStatement = $pdo->prepare(
    "SELECT product_sku, product_name, brand, wmdp, sbhdp, emdp, sgdp
     FROM price_code {$where} ORDER BY product_sku LIMIT :limit OFFSET :offset"
);
foreach ($searchParams as $key => $value) {
    $normalStatement->bindValue($key, $value, PDO::PARAM_STR);
}
$normalStatement->bindValue(':limit', $normalPerPage, PDO::PARAM_INT);
$normalStatement->bindValue(':offset', $normalOffset, PDO::PARAM_INT);
$normalStatement->execute();
$normalProducts = $normalStatement->fetchAll();

$composites = $pdo->query(
    'SELECT item_code, item_description, loose_items
     FROM composite_items
     ORDER BY item_code'
)->fetchAll();

$compositeWhere = '';
$compositeParams = [];
if ($compositeSearch !== '') {
    $compositeWhere = 'WHERE item_code LIKE :composite_code OR item_description LIKE :composite_name';
    $compositeParams = [
        ':composite_code' => '%' . $compositeSearch . '%',
        ':composite_name' => '%' . $compositeSearch . '%',
    ];
}
$compositeCountStatement = $pdo->prepare("SELECT COUNT(*) FROM composite_items {$compositeWhere}");
$compositeCountStatement->execute($compositeParams);
$compositeTotal = (int) $compositeCountStatement->fetchColumn();
$compositePageCount = max(1, (int) ceil($compositeTotal / $compositePerPage));
$compositePage = min($compositePage, $compositePageCount);
$compositeOffset = ($compositePage - 1) * $compositePerPage;
$displayStatement = $pdo->prepare(
    "SELECT item_code, item_description, loose_items
     FROM composite_items {$compositeWhere} ORDER BY item_code LIMIT :limit OFFSET :offset"
);
foreach ($compositeParams as $key => $value) $displayStatement->bindValue($key, $value, PDO::PARAM_STR);
$displayStatement->bindValue(':limit', $compositePerPage, PDO::PARAM_INT);
$displayStatement->bindValue(':offset', $compositeOffset, PDO::PARAM_INT);
$displayStatement->execute();
$displayComposites = $displayStatement->fetchAll();

foreach ($composites as $composite) {
    $registeredComposites[
        strtoupper(trim($composite['item_code']))
    ] = true;
}

// Import loose/normal SKUs from the two-header Excel template.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo !== null
    && ($_POST['action'] ?? '') === 'import_normal_excel'
) {
    try {
        if (!is_string($_POST['csrf'] ?? null)
            || !hash_equals($_SESSION['composite_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('Your form expired. Reload this page and try again.');
        }
        if (!isset($_FILES['normal_excel']) || $_FILES['normal_excel']['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Please select an Excel file.');
        }
        $extension = strtolower(pathinfo($_FILES['normal_excel']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new InvalidArgumentException('Only XLSX, XLS, and CSV files are supported.');
        }

        $sheet = IOFactory::load($_FILES['normal_excel']['tmp_name'])->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 3) {
            throw new InvalidArgumentException('The file must contain two header rows and at least one data row.');
        }

        $top = array_map(static fn ($v) => strtoupper(trim((string) $v)), array_shift($rows));
        $sub = array_map(static fn ($v) => strtoupper(trim((string) $v)), array_shift($rows));

        // Excel uses merged cells for WMDP, SBHDP, EMDP, and SG. PhpSpreadsheet returns blank values for the merged columns, so carry the last group header across the columns before locating Unit Price.
        $activeGroup = '';
        foreach ($top as $index => $value) {
            if ($value !== '') {
                $activeGroup = $value;
            } elseif ($activeGroup !== '') {
                $top[$index] = $activeGroup;
            }
        }

        $findColumn = static function (string $group, string $label) use ($top, $sub): ?int {
            foreach ($top as $index => $value) {
                if (($group === '' || $value === $group) && $sub[$index] === $label) return $index;
            }
            return null;
        };
        $findTop = static function (string $label) use ($top): ?int {
            foreach ($top as $index => $value) if ($value === $label) return $index;
            return null;
        };
        $skuCol = $findTop('PRODUCT SKU');
        $nameCol = $findTop('PRODUCT NAME');
        $brandCol = $findTop('BRAND');
        $priceCols = ['wmdp' => $findColumn('WMDP', 'UNIT PRICE'), 'sbhdp' => $findColumn('SBHDP', 'UNIT PRICE'), 'emdp' => $findColumn('EMDP', 'UNIT PRICE'), 'sgdp' => $findColumn('SG', 'UNIT PRICE'),];
        if ($skuCol === null || $nameCol === null || $brandCol === null) {
            throw new InvalidArgumentException('Required columns are Product SKU, Product Name, and Brand.');
        }
        $missingPriceGroups = array_keys(array_filter($priceCols, static fn ($column) => $column === null));
        if ($missingPriceGroups) {
            throw new InvalidArgumentException(
                'Could not find Unit Price columns for: ' . implode(', ', $missingPriceGroups)
            );
        }

        $pdo->beginTransaction();
        $save = $pdo->prepare(
            'INSERT INTO price_code (product_sku, product_name, brand, wmdp, sbhdp, emdp, sgdp)
             VALUES (:sku,:name,:brand,:wmdp,:sbhdp,:emdp,:sgdp)
             ON DUPLICATE KEY UPDATE product_name=VALUES(product_name), brand=VALUES(brand),
             wmdp=VALUES(wmdp), sbhdp=VALUES(sbhdp), emdp=VALUES(emdp), sgdp=VALUES(sgdp)'
        );
        $saved = 0;
        foreach ($rows as $rowNumber => $row) {
            $sku = strtoupper(trim((string) ($row[$skuCol] ?? '')));
            if ($sku === '') continue;
            $params = [
                ':sku' => $sku,
                ':name' => strtoupper(trim((string) ($row[$nameCol] ?? ''))),
                ':brand' => strtoupper(trim((string) ($row[$brandCol] ?? ''))) ?: null,
            ];
            foreach ($priceCols as $field => $column) {
                $raw = $column === null ? '' : trim((string) ($row[$column] ?? ''));
                $params[":" . $field] = $raw === '' ? null : (float) str_replace(',', '', $raw);
            }
            if ($params[':name'] === '') throw new InvalidArgumentException('Missing product name on Excel row ' . ($rowNumber + 3) . '.');
            $save->execute($params);
            $saved++;
        }
        $pdo->commit();
        $_SESSION['composite_success'] = "{$saved} loose/normal SKUs imported successfully.";
        $_SESSION['sku_mode'] = 'normal';
        header('Location: sku_setup.php');
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getMessage();
    }
}

// Create, update, or delete a loose/normal SKU in price_code.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo !== null
    && in_array($_POST['action'] ?? '', ['create_normal', 'update_normal', 'delete_normal'], true)
) {
    try {
        if (!is_string($_POST['csrf'] ?? null)
            || !hash_equals($_SESSION['composite_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('Your form expired. Reload this page and try again.');
        }

        $action = $_POST['action'];
        $sku = strtoupper(trim((string) ($_POST['product_sku'] ?? $_POST['original_sku'] ?? '')));
        $originalSku = strtoupper(trim((string) ($_POST['original_sku'] ?? $sku)));

        if ($sku === '' || !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,49}$/', $sku)) {
            throw new InvalidArgumentException('Enter a valid SKU.');
        }

        if ($action === 'delete_normal') {
            $stmt = $pdo->prepare('DELETE FROM price_code WHERE product_sku = :sku');
            $stmt->execute([':sku' => $originalSku]);
            $_SESSION['composite_success'] = "Loose SKU {$originalSku} was deleted successfully.";
        } else {
            $name = strtoupper(trim((string) ($_POST['product_name'] ?? '')));
            $brand = strtoupper(trim((string) ($_POST['brand'] ?? '')));
            $prices = [];
            foreach (['wmdp', 'sbhdp', 'emdp', 'sgdp'] as $field) {
                $value = $_POST[$field] ?? '';
                $prices[$field] = $value === '' ? null : (float) $value;
                if ($prices[$field] !== null && $prices[$field] < 0) {
                    throw new InvalidArgumentException('Prices cannot be negative.');
                }
            }
            if ($name === '') {
                throw new InvalidArgumentException('Enter the loose/normal SKU name.');
            }

            if ($action === 'update_normal') {
                $stmt = $pdo->prepare(
                    'UPDATE price_code SET product_sku=:sku, product_name=:name, brand=:brand,
                     wmdp=:wmdp, sbhdp=:sbhdp, emdp=:emdp, sgdp=:sgdp
                     WHERE product_sku=:original_sku'
                );
                $params = [':sku'=>$sku, ':name'=>$name, ':brand'=>$brand ?: null,
                    ':wmdp'=>$prices['wmdp'], ':sbhdp'=>$prices['sbhdp'],
                    ':emdp'=>$prices['emdp'], ':sgdp'=>$prices['sgdp'],
                    ':original_sku'=>$originalSku];
                $stmt->execute($params);
                $_SESSION['composite_success'] = "Loose SKU {$sku} was updated successfully.";
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO price_code (product_sku, product_name, brand, wmdp, sbhdp, emdp, sgdp)
                     VALUES (:sku,:name,:brand,:wmdp,:sbhdp,:emdp,:sgdp)'
                );
                $stmt->execute([':sku'=>$sku, ':name'=>$name, ':brand'=>$brand ?: null,
                    ':wmdp'=>$prices['wmdp'], ':sbhdp'=>$prices['sbhdp'],
                    ':emdp'=>$prices['emdp'], ':sgdp'=>$prices['sgdp']]);
                $_SESSION['composite_success'] = "Loose SKU {$sku} was added successfully.";
            }
        }
        $_SESSION['sku_mode'] = 'normal';
        header('Location: sku_setup.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

// Create, update or delete a composite.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo !== null
    && !in_array($_POST['action'] ?? '', ['create_normal', 'update_normal', 'delete_normal', 'import_normal_excel', 'import_excel'], true)
) {
    try {
        if (
            !is_string($_POST['csrf'] ?? null) ||
            !hash_equals($_SESSION['composite_csrf'], $_POST['csrf'])
        ) {
            throw new InvalidArgumentException(
                'Your form expired. Reload this page and try again.'
            );
        }

        $action = is_string($_POST['action'] ?? null)
            ? $_POST['action']
            : 'create';

        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            throw new InvalidArgumentException('Invalid composite action.');
        }

        // Delete composite function
        if ($action === 'delete') {
            $deleteCode = is_string($_POST['original_code'] ?? null)
                ? strtoupper(trim($_POST['original_code']))
                : '';

            if (
                $deleteCode === '' ||
                !isset($registeredComposites[$deleteCode])
            ) {
                throw new InvalidArgumentException(
                    'The selected composite could not be found.'
                );
            }

            $statement = $pdo->prepare(
                'DELETE FROM composite_items
                 WHERE item_code = :item_code'
            );

            $statement->execute([
                'item_code' => $deleteCode,
            ]);

            if ($statement->rowCount() < 1) {
                throw new InvalidArgumentException(
                    'The selected composite no longer exists.'
                );
            }

            $_SESSION['composite_success'] =
                'Composite ' . $deleteCode . ' was deleted successfully.';
            $_SESSION['sku_mode'] = 'composite';

            header('Location: sku_setup.php');
            exit;
        }

        // Create and update composite
        $originalCode = null;

        if ($action === 'update') {
            $originalCode = is_string($_POST['original_code'] ?? null)
                ? strtoupper(trim($_POST['original_code']))
                : '';

            if (
                $originalCode === '' ||
                !isset($registeredComposites[$originalCode])
            ) {
                throw new InvalidArgumentException(
                    'The composite being edited could not be found.'
                );
            }

            $_POST['item_code'] = $originalCode;
        }

        $data = validateComposite($_POST, $products, $registeredComposites, $originalCode);

        if ($action === 'update') {
            $statement = $pdo->prepare(
                "UPDATE composite_items
                 SET
                    product_type = 'composite',
                    item_code = :item_code,
                    item_description = :item_description,
                    loose_items = :loose_items
                 WHERE item_code = :original_code"
            );

            $statement->execute([
                'item_code' => $data['item_code'],
                'item_description' => $data['item_description'],
                'loose_items' => $data['loose_items'],
                'original_code' => $originalCode,
            ]);

            $_SESSION['composite_success'] =
                'Composite ' . $data['item_code'] . ' updated successfully.';
        } else {
            $statement = $pdo->prepare(
                "INSERT INTO composite_items
                    (
                        product_type,
                        item_code,
                        item_description,
                        loose_items
                    )
                 VALUES
                    (
                        'composite',
                        :item_code,
                        :item_description,
                        :loose_items
                    )"
            );

            $statement->execute($data);

            $_SESSION['composite_success'] =
                'Composite ' . $data['item_code'] . ' added successfully.';
        }

        $_SESSION['sku_mode'] = 'composite';
        header('Location: sku_setup.php');
        exit;
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (JsonException $exception) {
        error_log(
            'Composite Setup JSON error: ' . $exception->getMessage()
        );

        $error = 'Unable to prepare the loose-item information.';
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            $error = 'That composite SKU already exists.';
        } else {
            error_log(
                'Composite Setup database error: ' .
                $exception->getMessage()
            );

            $error = 'Unable to save the composite. Please try again.';
        }
    }
}

// Determine whether an existing composite is being edited.
$requestedEditCode = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update' &&
    is_string($_POST['original_code'] ?? null)
) {
    $requestedEditCode = strtoupper(
        trim($_POST['original_code'])
    );
} elseif (is_string($_GET['edit'] ?? null)) {
    $requestedEditCode = strtoupper(
        trim($_GET['edit'])
    );
}

$editingComposite = null;

if ($requestedEditCode !== '') {
    foreach ($composites as $composite) {
        $currentCode = strtoupper(
            trim((string) $composite['item_code'])
        );

        if ($currentCode === $requestedEditCode) {
            $editingComposite = $composite;
            break;
        }
    }

    if ($editingComposite === null && $error === null) {
        $error = 'The selected composite could not be found.';
    }
}

$isEditing = $editingComposite !== null;

$originalCode = $isEditing
    ? strtoupper(trim((string) $editingComposite['item_code']))
    : '';

// Preserve submitted values after a validation error.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formCode = is_string($_POST['item_code'] ?? null)
        ? strtoupper(trim($_POST['item_code']))
        : '';

    $formName = is_string($_POST['item_description'] ?? null)
        ? mb_strtoupper(
            trim($_POST['item_description']),
            'UTF-8'
        )
        : '';

    $postedCodes = is_array($_POST['loose_code'] ?? null)
        ? $_POST['loose_code']
        : [];

    $postedQty = is_array($_POST['quantity'] ?? null)
        ? $_POST['quantity']
        : [];
} elseif ($isEditing) {
    $formCode = $originalCode;

    $formName = mb_strtoupper(
        trim((string) $editingComposite['item_description']),
        'UTF-8'
    );

    $savedItems = json_decode(
        $editingComposite['loose_items'] ?? '[]',
        true
    );

    $postedCodes = [];
    $postedQty = [];

    if (is_array($savedItems)) {
        foreach ($savedItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemCode = strtoupper(
                trim((string) ($item['item_code'] ?? ''))
            );

            $quantity = (int) ($item['quantity'] ?? 1);

            if ($itemCode === '') {
                continue;
            }

            $postedCodes[] = $itemCode;
            $postedQty[] = (string) max(1, $quantity);
        }
    }
} else {
    $formCode = '';
    $formName = '';
    $postedCodes = [];
    $postedQty = [];
}

$formRows = [];

foreach (
    array_slice($postedCodes, 0, 100, true)
    as $key => $value
) {
    $formRows[] = [
        'code' => is_string($value)
            ? strtoupper(trim($value))
            : '',
        'quantity' => isset($postedQty[$key])
            ? (string) $postedQty[$key]
            : '1',
    ];
}

if (!$formRows) {
    $formRows[] = ['code' => '', 'quantity' => '1',];
}

function renderCompositeComponent(
    array $products,
    array $registeredComposites,
    array $values
): void {
    $currentCode = strtoupper(
        trim((string) ($values['code'] ?? ''))
    );

    $currentQuantity = (string) ($values['quantity'] ?? '1');
    ?>
    <div class="component-row">
        <div class="form-group">
            <label>
                <span class="form-label">Loose item <span class="required">*</span></span>
                <select class="form-control" name="loose_code[]" required>
                    <option value="">Select a product</option>

                    <?php if ($currentCode !== '' && !isset($products[$currentCode])): ?>
                        <option value="<?= compositeEscape($currentCode) ?>" selected>
                            <?= compositeEscape($currentCode . ' - SAVED ITEM') ?>
                        </option>
                    <?php endif; ?>

                    <?php foreach ($products as $code => $product): ?>
                        <?php if (isset($registeredComposites[$code])) continue; ?>
                        <option value="<?= compositeEscape($code) ?>" <?= $currentCode === (string) $code ? 'selected' : '' ?>>
                            <?= compositeEscape($code . ' - ' . ($product['product_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="form-group">
            <label>
                <span class="form-label">Quantity per pack <span class="required">*</span></span>
                <input class="form-control" name="quantity[]" type="number" min="1" max="1000000" step="1" value="<?= compositeEscape($currentQuantity) ?>" required>
            </label>
        </div>
        <button type="button" class="btn btn-danger remove-item" aria-label="Remove loose item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6"/>
            </svg>
            Remove
        </button>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SKU Setup - S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="icon" href="../images/icon-sasia.png">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* MAIN */
:root{
  --red:#E0202E;--red-dark:#8E1620;--red-darker:#3B0B0F;
  --teal:#00B4B4;--teal-dark:#008A8A;
  --ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;
  --gray-50:#FAFAFB;--gray-300:#D8D8DE;--gray-100:#F2F2F4;
  --bg:#F5F5F7;--white:#FFFFFF;
  --gold:#F5A623;--green:#10B981;
  --radius-lg:18px;--radius-md:12px;--radius-sm:8px;
  --topbar-h:64px;--sidebar-w:256px;--sidebar-w-collapsed:76px;
  --shadow:0 1px 2px rgba(20,20,30,.04),0 8px 24px -12px rgba(20,20,30,.10);
  --shadow-card:0 2px 8px rgba(20,20,30,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;}
a{text-decoration:none;color:inherit;}
button{font-family:inherit;cursor:pointer;border:none;background:none;}
svg{display:block;}

@media(max-width:900px){
  .main{margin-left:0 !important;}
}

/* LAYOUT */
.layout{display:flex;margin-top:var(--topbar-h);}
.main{margin-left:var(--sidebar-w);flex:1;padding:28px 32px 48px;max-width:1200px;min-width:0;transition:margin-left .25s ease,opacity .15s ease;}
body.sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed);}
@media(max-width:900px){.main{margin-left:0;padding:20px 16px 40px;}
body.sidebar-collapsed .main{margin-left:0 !important;}}

/* PAGE HEADER */
.page-header{margin-bottom:32px;}
.page-header h1{font-size:28px;font-weight:800;margin-bottom:4px;color:var(--ink);}
.page-header p{font-size:14px;color:var(--gray-500);}

/* CARD */
.card{background:var(--white);border-radius:var(--radius-lg);padding:28px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);margin-bottom:24px;}
.card-title{font-size:16px;font-weight:800;margin-bottom:20px;color:var(--ink);}

/* FORM SECTION */
.form-group{margin-bottom:20px;}
.form-label{display:block;font-size:13px;font-weight:700;margin-bottom:6px;color:var(--gray-700);}
.form-label .required{color:var(--red);margin-left:2px;}
.form-hint{display:block;font-size:12px;color:var(--gray-500);margin-top:4px;}
.form-control{width:100%;padding:10px 14px;border:1.5px solid var(--gray-300);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;background:var(--white);transition:border-color .15s,box-shadow .15s;}
.form-control:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.1);}
.form-control:disabled{background:var(--gray-100);cursor:not-allowed;}
select.form-control{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%238A8A93'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:36px;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;}
@media(max-width:768px){.grid-2,.grid-3{grid-template-columns:1fr;}}

/* BUTTON */
.button-group{display:flex;gap:12px;margin-top:24px;flex-wrap:wrap;}
.btn{padding:12px 24px;border-radius:var(--radius-md);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;border:none;cursor:pointer;transition:all .15s;}
.btn-primary{background:var(--red);color:white;box-shadow:0 4px 12px rgba(224,32,46,.3);}
.btn-primary:hover{background:var(--red-dark);}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;}
.btn-secondary{background:transparent;border:1.5px solid var(--gray-300);color:var(--ink);}
.btn-secondary:hover{background:var(--gray-100);}
.btn-success{background:var(--green);color:white;box-shadow:0 4px 12px rgba(16,185,129,.3);}
.btn-success:hover{background:#059669;}
.btn-danger{background:var(--red);color:white;box-shadow:0 4px 12px rgba(224,32,46,.3);}
.btn-danger:hover{background:var(--red-dark);}
.btn svg{width:16px;height:16px;}

/* ALERT SECTION */
.alert{padding:14px 16px;border-radius:var(--radius-md);margin-bottom:16px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;}
.alert-success{background:#d1fae5;border:1px solid #6ee7b7;color:#047857;}
.alert-error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.alert-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;}
.alert-warning{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}

/* TABLE SECTION */
.table-wrap{overflow-x:auto;margin-top:16px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
table th{text-align:left;padding:10px 12px;background:var(--gray-100);font-weight:700;color:var(--gray-700);border-bottom:2px solid var(--gray-300);}
table td{padding:10px 12px;border-bottom:1px solid var(--gray-100);vertical-align:middle;}
table tr:hover{background:var(--gray-50);}
.badge{padding:3px 10px;border-radius:30px;font-size:11px;font-weight:700;display:inline-block;}
.badge-myr{background:#dbeafe;color:#1e40af;}
.badge-sgd{background:#fef3c7;color:#92400e;}
.badge-ca{background:#fce4ec;color:#c62828;}
.badge-nf{background:#e8f5e9;color:#2e7d32;}
.badge-zk{background:#f3e5f5;color:#6a1b9a;}
.text-center{text-align:center;}
.text-muted{color:var(--gray-500);}
.text-right{text-align:right;}
.w-100{width:100%;}

/* FILE UPLOADER SECTION */
.uppercase-input {text-transform: uppercase;}
.sku-mode-toggle{display:flex;gap:10px;margin:18px 0 24px;flex-wrap:wrap;}
.sku-search-form,.sku-import-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 16px;}
.sku-uploader{margin:20px 0 24px;padding:18px 20px;border:1px solid var(--gray-200);border-radius:12px;background:linear-gradient(135deg,#fff,#f8f9fb);box-shadow:0 5px 18px rgba(20,20,30,.05);}
.uploader{display:flex;flex-direction:column;gap:4px;margin-bottom:13px;}
.uploader strong{font-size:14px;color:var(--gray-800);}
.uploader span{font-size:12px;color:var(--gray-500);}
.sku-uploader .sku-import-form{margin:0;}
.file-picker{display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px dashed var(--gray-300);border-radius:8px;background:#fff;color:var(--gray-700);font-size:13px;cursor:pointer;min-height:40px;}
.file-picker span{font-weight:600;}
.file-picker input[type=file]{max-width:220px;font-size:12px;color:var(--gray-500);}
.file-picker input[type=file]::file-selector-button{border:0;border-radius:6px;background:var(--gray-100);padding:7px 10px;margin-right:8px;color:var(--gray-700);cursor:pointer;}
@media(max-width:600px){.file-picker{align-items:flex-start;flex-direction:column;}.file-picker input[type=file]{max-width:100%;width:100%;}.sku-uploader .btn{width:100%;justify-content:center;}}
.sku-search-form .form-control{max-width:420px;}
.pagination{display:flex;gap:6px;margin-top:18px;flex-wrap:wrap;}
.pagination a{padding:7px 11px;border:1px solid var(--gray-300);border-radius:6px;text-decoration:none;color:inherit;}
.pagination a.active{background:var(--red);color:#fff;border-color:var(--red);}
.normal-sku-actions{display:flex;gap:8px;align-items:center;}
.composite-actions {display: flex;align-items: center;justify-content: center;gap: 8px;white-space: nowrap;}
.delete-composite-form {display: inline;margin: 0;}
.btn-small {min-height: 34px;padding: 7px 12px;justify-content: center;font-size: 12px;}
@media (max-width: 600px) {.composite-actions {align-items: stretch;flex-direction: column;}
.composite-actions .btn {width: 100%;}}

/* MODAL */
[hidden] {display: none !important;}
body.modal-open {overflow: hidden;}
.modal-backdrop {position: fixed;inset: 0;z-index: 1000;display: flex;align-items: center;justify-content: center;padding: 20px;background: rgba(27, 27, 31, 0.5);backdrop-filter: blur(4px);}
.modal-card {position: relative;width: min(100%, 520px);max-height: calc(100vh - 40px);overflow-y: auto;padding: 30px;margin: 0;background: var(--white);border-radius: var(--radius-lg);box-shadow: 0 24px 70px rgba(20, 20, 30, 0.25);animation: compositeModalOpen 0.2s ease-out;}
.composite-edit-card {width: min(100%, 950px);}
.delete-modal-card,
.success-modal-card {width: min(100%, 430px);text-align: center;}
.modal-dismiss {position: absolute;top: 12px;right: 12px;display: inline-flex;align-items: center;justify-content: center;width: 34px;height: 34px;padding: 0;border: 0;border-radius: 50%;background: var(--gray-100);color: var(--gray-700);line-height: 0;cursor: pointer;}
.modal-dismiss svg {display: block;width: 16px;height: 16px;flex: 0 0 16px;}
.modal-dismiss:hover {background: var(--gray-300);color: var(--ink);}
.modal-icon {display: grid;place-items: center;width: 58px;height: 58px;margin: 0 auto 16px;border-radius: 50%;}
.modal-icon svg {width: 28px;height: 28px;}
.modal-icon-danger {background: #fee2e2;color: #dc2626;}
.modal-icon-success {background: #d1fae5;color: #047857;}
.modal-title {margin: 0 0 8px;color: var(--ink);font-size: 20px;font-weight: 800;}
.modal-message {margin: 0;color: var(--gray-700);font-size: 13px;line-height: 1.6;}
.modal-actions {display: flex;justify-content: center;gap: 12px;margin-top: 24px;}
.modal-confirm-button {justify-content: center;width: 100%;margin-top: 24px;}
@keyframes compositeModalOpen {from {opacity: 0;transform: translateY(8px) scale(0.97);}
to {opacity: 1;transform: translateY(0) scale(1);}}
@media (max-width: 600px) {.modal-backdrop {padding: 12px;}.modal-card {padding: 26px 20px 20px;}.modal-actions {flex-direction: column-reverse;}.modal-actions .btn {justify-content: center;width: 100%;}}
.readonly-control {color: var(--gray-700);background: var(--gray-100);cursor: not-allowed;}

/* SKU EDIT MODAL (LOOSE & COMPOSITE) */
.sku-edit-modal {position: fixed;inset: 0;z-index: 9999;background: rgba(0, 0, 0, 0.50);display: flex;align-items: center;justify-content: center;padding: 20px;}
.sku-edit-modal[hidden] {display: none;}
.sku-edit-content {position: relative;background: #ffffff;width: 100%;max-width: 950px;max-height: 90vh;overflow-y: auto;border-radius: 18px;padding: 30px;box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);}
.sku-edit-close {position: absolute;top: 14px;right: 14px;width: 36px;height: 36px;border: none;border-radius: 50%;background: #f3f4f6;color: #555;font-size: 24px;cursor: pointer;}
.sku-edit-title {font-size: 18px;font-weight: 700;color: #18181b;margin: 0 0 10px;}
.sku-edit-subtitle {font-size: 13px;color: #92929e;margin: 0 0 26px;}
.sku-edit-fields {min-width: 0;margin: 0;padding: 0;border: 0;}
.sku-edit-fields:disabled {opacity: .6;}
.sku-edit-actions button:disabled {opacity: .5;cursor: not-allowed;}
.sku-edit-details {display: grid;grid-template-columns: 1fr 1fr;gap: 20px;margin-bottom: 22px;}
.sku-edit-field {display: flex;flex-direction: column;gap: 6px;min-width: 0;}
.sku-edit-field label,.sku-edit-loose-group label,.sku-edit-quantity-group label {font-size: 13px;font-weight: 600;color: #52525b;}
.sku-edit-field label span,
.sku-edit-loose-group label span,
.sku-edit-quantity-group label span {color: #e11d48;}
.sku-edit-field input,.sku-edit-loose-input,.sku-edit-quantity-input {width: 100%;height: 42px;box-sizing: border-box;padding: 0 14px;border: 1px solid #d4d4dd;border-radius: 9px;background: #fff;color: #27272a;font-size: 14px;font-family: inherit;}
.sku-edit-field input:focus,.sku-edit-loose-input:focus,.sku-edit-quantity-input:focus {outline: none;border-color: #10b981;box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.10);}
.sku-edit-field input[readonly] {background: #f1f1f3;color: #666670;cursor: not-allowed;}
.sku-edit-field small {font-size: 12px;color: #9696a2;}
.sku-edit-section {margin-bottom: 16px;}
.sku-edit-section h3 {font-size: 14px;font-weight: 700;margin: 0 0 6px;color: #18181b;}
.sku-edit-section p {font-size: 13px;color: #92929e;margin: 0;}
.sku-edit-rows {display: flex;flex-direction: column;gap: 14px;}
.sku-edit-row {display: grid;grid-template-columns: minmax(0, 1fr) 170px 108px;align-items: end;gap: 16px;}
.sku-edit-loose-group,.sku-edit-quantity-group {display: flex;flex-direction: column;gap: 6px;min-width: 0;}
.sku-edit-loose-input {cursor: pointer;background-color: #ffffff;}
.sku-edit-btn-remove {height: 42px;background: #e91632;color: #fff;border: none;border-radius: 11px;padding: 0 14px;font-size: 14px;font-weight: 600;cursor: pointer;box-shadow: 0 5px 12px rgba(233, 22, 50, 0.22);}
.sku-edit-btn-remove:hover {background: #c8102e;}
.sku-edit-actions {display: flex;flex-wrap: wrap;align-items: center;gap: 12px;margin-top: 24px;}
.sku-edit-btn-add,.sku-edit-btn-save,.sku-edit-btn-cancel {height: 44px;border-radius: 11px;padding: 0 24px;font-size: 14px;font-weight: 600;font-family: inherit;cursor: pointer;}
.sku-edit-btn-add {background: #ffffff;color: #27272a;border: 1px solid #d4d4dd;}
.sku-edit-btn-save {background: #10b981;color: #ffffff;border: none;box-shadow: 0 5px 12px rgba(16, 185, 129, 0.20);}
.sku-edit-btn-save:hover {background: #059669;}
.sku-edit-btn-cancel {background: #ffffff;color: #27272a;border: 1px solid #d4d4dd;}
.sku-edit-status {font-size: 12px;color: #777;margin: 12px 0 0;}
.sku-edit-status.error {color: var(--red);}
@media (max-width: 700px) {.sku-edit-content {padding: 22px;}.sku-edit-details {grid-template-columns: 1fr;}.sku-edit-row {grid-template-columns: 1fr 1fr;}.sku-edit-loose-group {grid-column: 1 / -1;}.sku-edit-btn-remove {width: 100%;}.sku-edit-actions {flex-direction: column;}.sku-edit-actions button {width: 100%;}}

/* SECTION */
.section-description{font-size:13px;line-height:1.6;color:var(--gray-500);margin:-8px 0 24px;}
.component-heading{font-size:14px;font-weight:800;margin-bottom:6px;}
.component-help{margin-bottom:16px;}
.component-row{display:grid;grid-template-columns:minmax(0,1fr) 170px auto;gap:16px;align-items:end;margin-bottom:16px;}
.component-row .form-group{margin-bottom:0;min-width:0;}
.component-row .form-control{min-height:42px;min-width:0;}
.component-row .remove-item{min-height:42px;padding:10px 14px;justify-content:center;}
.btn:focus-visible{outline:3px solid var(--teal);outline-offset:3px;}
.components{list-style:none;display:flex;flex-direction:column;gap:6px;}
.components li{font-size:12px;line-height:1.5;white-space:nowrap;}
.table-wrap table{min-width:540px;}
.composite-code{font-weight:700;white-space:nowrap;}
.empty-state{padding:24px 12px;text-align:center;color:var(--gray-500);}
@media(max-width:768px){.card{padding:20px;}.component-row{grid-template-columns:minmax(0,1fr) 140px;}.component-row .remove-item{grid-column:2;justify-self:end;}}
@media(max-width:600px){.topbar{padding:0 12px;gap:10px;}.topbar .sidebar-toggle-btn,.topbar .topbar-brand-text,.topbar .topbar-divider,.topbar .topbar-page-title,.topbar .admin-name,.topbar .admin-role,.topbar .admin-chevron{display:none;}.topbar .topbar-logo{width:auto;}.topbar .topbar-logo img{height:28px;}.topbar .admin-chip{padding:4px;gap:0;}}
@media(max-width:480px){.component-row{grid-template-columns:minmax(0,1fr);}.component-row .remove-item{grid-column:1;}.button-group .btn{justify-content:center;width:100%;}}
</style>
</head>
<body>
<script>
(function () {
    try {
        if (window.innerWidth >= 900 && localStorage.getItem('adminSidebarCollapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (error) {}
})();
</script>

<!-- SIDEBAR & TOPNAV -->
<?php require __DIR__ . '/../includes/topnav.php'; ?>
<?php require __DIR__ . '/../includes/sidebar.php'; ?>

<!-- LAYOUT -->
<div class="layout">
<main class="main">
    <div class="page-header">
        <h1>SKU Setup</h1>
        <p>Manage composite SKUs and loose/normal SKUs used by your sales data.</p>
        <nav class="sku-mode-toggle" aria-label="SKU setup type">
            <a class="btn <?= $mode === 'composite' ? 'btn-primary' : 'btn-secondary' ?>" href="sku_setup.php?mode=composite">Composite SKU</a>
            <a class="btn <?= $mode === 'normal' ? 'btn-primary' : 'btn-secondary' ?>" href="sku_setup.php?mode=normal">Loose SKU</a>
        </nav>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= compositeEscape($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="modal-backdrop" id="successModal" role="dialog" aria-modal="true" aria-labelledby="successModalTitle">
        <div class="modal-card success-modal-card">
            <button type="button" class="modal-dismiss" id="closeSuccessModal" aria-label="Close success popup">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12"/>
                    <path d="M18 6L6 18"/>
                </svg>
            </button>

            <div class="modal-icon modal-icon-success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path d="m5 12 4 4L19 6"/>
                </svg>
            </div>

            <h2 class="modal-title" id="successModalTitle">Successful</h2>
            <p class="modal-message"><?= compositeEscape($success) ?></p>
            <button type="button" class="btn btn-success modal-confirm-button" id="confirmSuccessModal">Okay</button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($mode === 'normal' && $pdo !== null): ?>
        <section class="card" aria-labelledby="normalSkuTitle">
            <h2 class="card-title" id="normalSkuTitle"><?= $normalEditing ? 'Edit Loose SKU' : 'Add Loose SKU' ?></h2>
            <form method="post" action="sku_setup.php">
                <input type="hidden" name="csrf" value="<?= compositeEscape($_SESSION['composite_csrf']) ?>">
                <input type="hidden" name="action" value="<?= $normalEditing ? 'update_normal' : 'create_normal' ?>">
                <input type="hidden" name="original_sku" value="<?= compositeEscape($normalEditing['product_sku'] ?? '') ?>">
                <div class="grid-2">
                    <div class="form-group"><label class="form-label">SKU <span class="required">*</span></label><input class="form-control uppercase-input" name="product_sku" placeholder="e.g. AC-010" value="<?= compositeEscape($normalEditing['product_sku'] ?? '') ?>" <?= $normalEditing ? 'readonly' : '' ?> required></div>
                    <div class="form-group"><label class="form-label">Product name <span class="required">*</span></label><input class="form-control uppercase-input" name="product_name" placeholder="e.g. DARK CHOCOLATE COOKIES" value="<?= compositeEscape($normalEditing['product_name'] ?? '') ?>" required></div>
                    <div class="form-group"><label class="form-label">Brand</label><input class="form-control uppercase-input" name="brand" placeholder="e.g. CHOCO ALBAB" value="<?= compositeEscape($normalEditing['brand'] ?? '') ?>"></div>
                    <?php foreach (['wmdp'=>'WMDP','sbhdp'=>'SBHDP','emdp'=>'EMDP','sgdp'=>'SGDP'] as $field=>$label): ?>
                        <div class="form-group"><label class="form-label"><?= $label ?></label><input class="form-control" type="number" min="0" step="0.01" name="<?= $field ?>" placeholder="e.g. 15.00" value="<?= compositeEscape($normalEditing[$field] ?? '') ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="button-group"><button class="btn btn-success" type="submit"><?= $normalEditing ? 'Update SKU' : 'Save SKU' ?></button><?php if ($normalEditing): ?><a class="btn btn-secondary" href="sku_setup.php?mode=normal">Cancel</a><?php endif; ?></div>
            </form>
        </section>
        <section class="card" aria-labelledby="normalSkuListTitle">
            <h2 class="card-title" id="normalSkuListTitle">Loose SKUs</h2>
            <form method="get" action="sku_setup.php" class="sku-search-form"><input type="hidden" name="mode" value="normal"><input class="form-control" type="search" name="q" value="<?= compositeEscape($normalSearch) ?>" placeholder="Search SKU, product name, or brand"><button class="btn btn-primary" type="submit">Search</button><a class="btn btn-secondary" href="sku_setup.php?mode=normal">Clear</a></form>
            <div class="uploader"><strong>Import loose SKUs</strong></div>
            <form method="post" action="sku_setup.php" enctype="multipart/form-data" class="sku-import-form"><input type="hidden" name="csrf" value="<?= compositeEscape($_SESSION['composite_csrf']) ?>"><input type="hidden" name="action" value="import_normal_excel"><label class="file-picker"><span>Choose Excel file</span><input type="file" name="normal_excel" accept=".xlsx,.xls,.csv" required></label><button class="btn btn-primary" type="submit">Import Excel</button><a class="btn btn-secondary" href="sku_setup.php?download=normal_template" download>Download Template</a></form>
            <p class="form-hint">Excel format: two header rows with Product SKU, Product Name, Brand, and Unit Price under WMDP, SBHDP, EMDP, and SG.</p><div class="table-wrap"><table><thead><tr><th>SKU</th><th>Name</th><th>Brand</th><th>WMDP</th><th>SBHDP</th><th>EMDP</th><th>SGDP</th><th>Action</th></tr></thead><tbody><?php foreach ($normalProducts as $product): ?><tr><td class="composite-code"><?= compositeEscape($product['product_sku']) ?></td><td><?= compositeEscape($product['product_name'] ?? '') ?></td><td><?= compositeEscape($product['brand'] ?? '') ?></td><td><?= compositeEscape($product['wmdp'] ?? '') ?></td><td><?= compositeEscape($product['sbhdp'] ?? '') ?></td><td><?= compositeEscape($product['emdp'] ?? '') ?></td><td><?= compositeEscape($product['sgdp'] ?? '') ?></td><td class="normal-sku-actions"><button type="button" class="btn btn-secondary btn-small ajax-edit-normal" data-sku="<?= compositeEscape($product['product_sku']) ?>">Edit</button><button type="button" class="btn btn-danger btn-small open-normal-delete" data-normal-sku="<?= compositeEscape($product['product_sku']) ?>" data-normal-name="<?= compositeEscape($product['product_name'] ?? "") ?>">Delete</button></td></tr><?php endforeach; ?></tbody></table></div>
            <?php if ($normalPageCount > 1): ?><nav class="pagination" aria-label="Normal SKU pages"><?php for ($page = 1; $page <= $normalPageCount; $page++): ?><a class="<?= $page === $normalPage ? 'active' : '' ?>" href="sku_setup.php?mode=normal&amp;q=<?= rawurlencode($normalSearch) ?>&amp;page=<?= $page ?>"><?= $page ?></a><?php endfor; ?></nav><?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="sku-edit-modal" id="normalEditModal" hidden role="dialog" aria-modal="true" aria-labelledby="normalEditTitle">
        <div class="sku-edit-content">
            <button type="button" class="sku-edit-close" id="closeNormalEdit" aria-label="Close edit popup"><span aria-hidden="true">&times;</span></button>
            <h2 class="sku-edit-title" id="normalEditTitle">Edit Loose SKU</h2>
            <p class="sku-edit-subtitle">Update the product details and unit prices for this loose SKU.</p>
            <form id="normalEditForm">
                <input type="hidden" name="csrf" value="<?= compositeEscape($_SESSION['composite_csrf']) ?>">
                <input type="hidden" name="action" value="ajax_update_normal">
                <fieldset class="sku-edit-fields" disabled>
                    <div class="sku-edit-details">
                        <div class="sku-edit-field"><label for="normalEditSku">SKU <span>*</span></label><input id="normalEditSku" name="product_sku" readonly required><small>Loose SKU cannot be changed.</small></div>
                        <div class="sku-edit-field"><label for="normalEditName">Product name <span>*</span></label><input id="normalEditName" class="uppercase-input" name="product_name" required></div>
                        <div class="sku-edit-field"><label for="normalEditBrand">Brand</label><input id="normalEditBrand" class="uppercase-input" name="brand"></div>
                        <?php foreach (['wmdp'=>'WMDP','sbhdp'=>'SBHDP','emdp'=>'EMDP','sgdp'=>'SGDP'] as $field=>$label): ?><div class="sku-edit-field"><label for="normalEdit<?= $label ?>"><?= $label ?></label><input id="normalEdit<?= $label ?>" type="number" min="0" step="0.01" name="<?= $field ?>"></div><?php endforeach; ?>
                    </div>
                </fieldset>
                <p class="sku-edit-status" id="normalEditStatus" role="status" aria-live="polite"></p>
                <div class="sku-edit-actions"><button type="submit" class="sku-edit-btn-save" disabled>Save changes</button><button type="button" class="sku-edit-btn-cancel" id="cancelNormalEdit">Cancel edit</button></div>
            </form>
        </div>
    </div>

    <div id="compositeAjaxModal" class="sku-edit-modal" hidden role="dialog" aria-modal="true" aria-labelledby="compositeAjaxTitle">
        <div class="sku-edit-content">
            <button type="button" id="closeCompositeAjax" class="sku-edit-close" aria-label="Close edit popup"><span aria-hidden="true">&times;</span></button>
            <h2 class="sku-edit-title" id="compositeAjaxTitle">Edit Composite</h2>
            <p class="sku-edit-subtitle">Use the composite SKU exactly as it appears in your Tax Invoice.</p>

            <form id="compositeAjaxForm">
                <input type="hidden" name="csrf" value="<?= compositeEscape($_SESSION['composite_csrf']) ?>">
                <input type="hidden" name="action" value="ajax_update_composite">
                <input type="hidden" name="original_code">
                <fieldset class="sku-edit-fields" disabled>
                    <div class="sku-edit-details">
                        <div class="sku-edit-field">
                            <label for="compositeAjaxSku">Composite SKU <span>*</span></label>
                            <input id="compositeAjaxSku" type="text" name="item_code" readonly required>
                            <small>Composite SKU cannot be changed.</small>
                        </div>
                        <div class="sku-edit-field">
                            <label for="compositeAjaxName">Composite name <span>*</span></label>
                            <input class="uppercase-input" id="compositeAjaxName" type="text" name="item_description" required>
                        </div>
                    </div>
                    <div class="sku-edit-section">
                        <h3>Loose items</h3>
                        <p>Choose each product once and enter its quantity for one pack.</p>
                    </div>
                    <div id="compositeAjaxRows" class="sku-edit-rows"></div>
                </fieldset>
                <p id="compositeAjaxStatus" class="sku-edit-status" role="status" aria-live="polite"></p>
                <div class="sku-edit-actions">
                    <button type="button" id="addCompositeAjaxItem" class="sku-edit-btn-add" disabled>+ &nbsp; Add loose item</button>
                    <button type="submit" class="sku-edit-btn-save" disabled>Save changes</button>
                    <button type="button" id="cancelCompositeAjax" class="sku-edit-btn-cancel">Cancel edit</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($mode === 'composite'): ?>
    <?php if ($pdo !== null && $products): ?>

        <?php if ($isEditing): ?>
            <div class="modal-backdrop" id="editCompositeModal" role="dialog" aria-modal="true" aria-labelledby="addCompositeTitle">
        <?php endif; ?>

        <section class="card <?= $isEditing ? 'modal-card composite-edit-card' : '' ?>" id="compositeForm" aria-labelledby="addCompositeTitle">
            <?php if ($isEditing): ?>
                <a href="sku_setup.php" class="modal-dismiss" aria-label="Close edit popup">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12"/>
                        <path d="M18 6L6 18"/>
                    </svg>
                </a>
            <?php endif; ?>

            <h2 class="card-title" id="addCompositeTitle"><?= $isEditing ? 'Edit Composite' : 'Add a Composite' ?></h2>
            <p class="section-description">Use the composite SKU exactly as it appears in your Tax Invoice.</p>
            <form method="post" action="sku_setup.php">
                <input type="hidden" name="csrf" value="<?= compositeEscape($_SESSION['composite_csrf']) ?>">
                <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                <input type="hidden" name="original_code" value="<?= compositeEscape($originalCode) ?>">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="compositeSku">Composite SKU <span class="required">*</span></label>
                        <?php if ($isEditing): ?>
                            <input class="form-control readonly-control" id="compositeSku" type="text" value="<?= compositeEscape($originalCode) ?>" readonly aria-describedby="compositeSkuHint">
                            <input type="hidden" name="item_code" value="<?= compositeEscape($originalCode) ?>">
                            <span class="form-hint" id="compositeSkuHint">Composite SKU cannot be changed.</span>
                        <?php else: ?>
                            <input class="form-control uppercase-input" id="compositeSku" name="item_code" maxlength="50" value="<?= compositeEscape($formCode) ?>" placeholder="E.g. DRINKS4" autocomplete="off" required>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="compositeName">Composite name <span class="required">*</span></label>
                        <input class="form-control uppercase-input" id="compositeName" name="item_description" maxlength="255" value="<?= compositeEscape($formName) ?>" placeholder="E.g. COMBO DRINKS x4" autocomplete="off" required>
                    </div>
                </div>
                <h3 class="component-heading">Loose items</h3>
                <p class="form-hint component-help">Choose each product once and enter its quantity for one pack.</p>
                <div id="componentRows">
                    <?php foreach ($formRows as $values) {renderCompositeComponent($products, $registeredComposites, $values);}?>
                </div>
                <div class="button-group">
                    <button type="button" id="addItem" class="btn btn-secondary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                        Add loose item
                    </button>
                    <button type="submit" class="btn btn-success">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2Z"/>
                            <path d="M17 21v-8H7v8M7 3v5h8"/>
                        </svg>
                        <?= $isEditing ? 'Update composite' : 'Save composite' ?>
                    </button>
                    <?php if ($isEditing): ?>
                        <a href="sku_setup.php" class="btn btn-secondary">
                            Cancel edit
                        </a>
                    <?php endif; ?>
                </div>
            </form>
            <template id="componentTemplate">
                <?php renderCompositeComponent($products, $registeredComposites, ['code' => '', 'quantity' => '1']); ?>
            </template>
        </section>
        <?php if ($isEditing): ?></div><?php endif; ?>
        <?php elseif ($pdo !== null): ?>
        <div class="alert alert-warning" role="status">
            Add products to the price list before setting up a composite.
        </div>
    <?php endif; ?>

    <?php if ($pdo !== null): ?>
        <section class="card" aria-labelledby="savedCompositesTitle">
            <h2 class="card-title" id="savedCompositesTitle">Saved Composites</h2>
            <form method="get" action="sku_setup.php" class="sku-search-form"><input type="hidden" name="mode" value="composite"><input class="form-control" type="search" name="composite_q" value="<?= compositeEscape($compositeSearch) ?>" placeholder="Search composite SKU or name"><button class="btn btn-primary" type="submit">Search</button><a class="btn btn-secondary" href="sku_setup.php?mode=composite">Clear</a></form>
            <div class="uploader"><strong>Import composite SKU</strong></div>
            <form method="post" action="sku_setup.php" enctype="multipart/form-data" class="sku-import-form">
                <input type="hidden" name="csrf" value="<?= compositeEscape($_SESSION['composite_csrf']) ?>">
                <input type="hidden" name="action" value="import_excel">
                <label class="file-picker"><span>Choose Excel file</span><input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls,.csv" required></label>
                <button type="submit" class="btn btn-primary">Import Excel</button>
                <a class="btn btn-secondary" href="sku_setup.php?download=composite_template" download>Download Template</a>
            </form>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Saved composites">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Composite SKU</th>
                            <th scope="col">Composite name</th>
                            <th scope="col">Loose items per pack</th>
                            <th scope="col" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($displayComposites as $composite): ?>
                            <tr>
                                <td class="composite-code"><?= compositeEscape($composite['item_code']) ?></td>
                                <td><?= compositeEscape($composite['item_description']) ?></td>
                                <td>
                                    <?php $items = json_decode($composite['loose_items'] ?? '[]', true); ?>
                                    <?php if (is_array($items) && $items): ?>
                                        <ul class="components">
                                            <?php foreach ($items as $item): ?>
                                                <?php if (!is_array($item)) continue; ?>
                                                <li>
                                                    <?= compositeEscape($item['item_code'] ?? '') ?>
                                                    &times; <?= (int) ($item['quantity'] ?? 0) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?><span class="text-muted">Not configured</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="composite-actions">
                                        <button type="button" class="btn btn-secondary btn-small ajax-edit-composite" data-sku="<?= compositeEscape($composite['item_code']) ?>">Edit</button>
                                        <button type="button" class="btn btn-danger btn-small open-delete-modal" data-composite-code="<?= compositeEscape($composite['item_code']) ?>" data-composite-name="<?= compositeEscape($composite['item_description']) ?>">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$displayComposites): ?><tr><td colspan="4" class="empty-state">No composites available.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($compositePageCount > 1): ?><nav class="pagination" aria-label="Composite SKU pages"><?php for ($page = 1; $page <= $compositePageCount; $page++): ?><a class="<?= $page === $compositePage ? 'active' : '' ?>" href="sku_setup.php?mode=composite&amp;composite_q=<?= rawurlencode($compositeSearch) ?>&amp;composite_page=<?= $page ?>"><?= $page ?></a><?php endfor; ?></nav><?php endif; ?>
        </section>
        <?php if (!empty($importMessage)): ?><div class="alert alert-success"><?= compositeEscape($importMessage) ?></div><?php endif; ?>
        <?php if (!empty($importError)): ?><div class="alert alert-error"><?= compositeEscape($importError) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

    <div class="modal-backdrop" id="deleteNormalModal" role="dialog" aria-modal="true" aria-labelledby="deleteNormalTitle" hidden>
        <div class="modal-card delete-modal-card">
            <button type="button" class="modal-dismiss" id="closeNormalDelete" aria-label="Close delete popup"><span aria-hidden="true">&times;</span></button>
            <div class="modal-icon modal-icon-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg></div>
            <h2 class="modal-title" id="deleteNormalTitle">Delete loose SKU?</h2>
            <p class="modal-message">You are about to delete <strong id="deleteNormalDescription"></strong>.</p>
            <form method="post" action="sku_setup.php"><input type="hidden" name="csrf" value="<?= compositeEscape($_SESSION['composite_csrf']) ?>"><input type="hidden" name="action" value="delete_normal"><input type="hidden" name="original_sku" id="deleteNormalSku"><div class="modal-actions"><button type="button" class="btn btn-secondary" id="cancelNormalDelete">Cancel</button><button type="submit" class="btn btn-danger">Delete SKU</button></div></form>
        </div>
    </div>

    <?php if ($mode === 'composite'): ?>
    <div class="modal-backdrop" id="deleteCompositeModal" role="dialog" aria-modal="true" aria-labelledby="deleteCompositeTitle" hidden>
        <div class="modal-card delete-modal-card">
            <button type="button" class="modal-dismiss" id="closeDeleteModal" aria-label="Close delete popup">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12"/>
                    <path d="M18 6L6 18"/>
                </svg>
            </button>

            <div class="modal-icon modal-icon-danger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M3 6h18"/>
                    <path d="M8 6V4h8v2"/>
                    <path d="M19 6l-1 14H6L5 6"/>
                    <path d="M10 11v5M14 11v5"/>
                </svg>
            </div>

            <h2 class="modal-title" id="deleteCompositeTitle">Delete composite?</h2>
            <p class="modal-message">
                You are about to delete <strong id="deleteCompositeDescription"></strong>. Its loose-item configuration will be removed.
            </p>

            <form method="post" action="sku_setup.php">
                <input type="hidden" name="csrf" value="<?= compositeEscape($_SESSION['composite_csrf']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="original_code" id="deleteCompositeCode" value="">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="cancelDeleteComposite">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete composite</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</main>
</div>

<script>
const PAGE = 'sku_setup.php';
let currentUrl = PAGE + location.search;   // internal state only (not shown in the address bar)
let navController = null;

// Elements that are replaced every time the content reloads
let rows, addButton, editModal, successModal, deleteModal, normalDeleteModal;
let normalEditController = null, compositeEditController = null;
let looseItemTemplate = null, compositeAjaxRows = null, compositeAjaxStatus = null;
let compositeRowId = 0;

// Keep the address bar clean. The active mode is remembered in the PHP session.
if (location.search) history.replaceState(null, '', location.pathname);

/* ============ AJAX NAVIGATION (URL never changes) ============ */
async function navigate(url, options = {}) {
    navController?.abort();
    navController = new AbortController();
    const main = document.querySelector('main.main');
    main.style.opacity = '.55';
    main.style.pointerEvents = 'none';

    try {
        const response = await fetch(url, {
            ...options,
            signal: navController.signal,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'fetch'}
        });
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newMain = doc.querySelector('main.main');

        // Session expired / redirected to login
        if (!newMain) {
            window.location.href = response.url;
            return;
        }

        currentUrl = response.url;
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;
        main.innerHTML = newMain.innerHTML;
        initPage();
        window.scrollTo(scrollX, scrollY);   // stay where the user was
    } catch (error) {
        if (error.name !== 'AbortError') alert('Unable to load the page. Please try again.');
    } finally {
        main.style.opacity = '';
        main.style.pointerEvents = '';
    }
}

// Every link inside <main> that points to sku_setup.php
document.addEventListener('click', event => {
    const link = event.target.closest('main.main a[href]');
    if (!link || link.hasAttribute('download') || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || link.target === '_blank') return;

    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || !url.pathname.endsWith('/' + PAGE)) return;

    event.preventDefault();
    navigate(url.href);
});

// Every form inside <main> (search, import, save, delete)
document.addEventListener('submit', event => {
    const form = event.target;
    // The edit modals handle their own submit and call preventDefault()
    if (event.defaultPrevented || !form.closest('main.main')) return;

    event.preventDefault();
    const method = (form.getAttribute('method') || 'get').toLowerCase();
    const action = new URL(form.getAttribute('action') || PAGE, location.href);

    if (method === 'get') {
        action.search = new URLSearchParams(new FormData(form)).toString();
        navigate(action.href);
    } else {
        navigate(action.href, {method: 'POST', body: new FormData(form)});
    }
});

/* ============ EDIT MODAL (AJAX) ============ */
function setupSkuEditModal({modalId, formId, statusId, closeId, cancelId, triggerSelector, endpoint, populate, clear = () => {}}) {
    const modal = document.getElementById(modalId);
    const form = document.getElementById(formId);
    const status = document.getElementById(statusId);
    if (!modal || !form || !status) return null;

    const fields = form.querySelector('.sku-edit-fields');
    const editButtons = form.querySelectorAll('[type="submit"], .sku-edit-btn-add');
    let ready = false;
    let saving = false;
    let loadRequest = null;
    let requestVersion = 0;
    let trigger = null;

    function setEnabled(enabled) {
        fields.disabled = !enabled;
        editButtons.forEach(button => { button.disabled = !enabled; });
    }

    function setStatus(message, error = false) {
        status.textContent = message;
        status.classList.toggle('error', error);
    }

    async function request(url, options, fallback) {
        const response = await fetch(url, {
            ...options,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        });
        let result;
        try {
            result = await response.json();
        } catch (error) {
            throw new Error(`${fallback} Reload the page and try again.`);
        }
        if (!response.ok || !result.ok) throw new Error(result.message || fallback);
        return result;
    }

    function close() {
        loadRequest?.abort();
        requestVersion++;
        modal.hidden = true;
        document.body.classList.toggle('modal-open', !!document.querySelector('.modal-backdrop:not([hidden]), .sku-edit-modal:not([hidden])'));
        trigger?.focus();
    }

    async function open(button) {
        if (saving) return;
        loadRequest?.abort();
        loadRequest = new AbortController();
        const version = ++requestVersion;
        trigger = button;
        ready = false;
        form.reset();
        clear();
        setEnabled(false);
        setStatus('Loading SKU...');
        modal.hidden = false;
        document.body.classList.add('modal-open');
        document.getElementById(closeId)?.focus();

        try {
            const result = await request(
                `${PAGE}?ajax=${endpoint}&sku=${encodeURIComponent(button.dataset.sku || '')}`,
                {signal: loadRequest.signal},
                'Unable to load SKU.'
            );
            if (version !== requestVersion) return;
            populate(result, form);
            ready = true;
            setEnabled(true);
            setStatus('Update the SKU details and save your changes.');
        } catch (error) {
            if (version !== requestVersion || error.name === 'AbortError') return;
            setStatus(error.message, true);
        }
    }

    document.querySelectorAll(triggerSelector).forEach(button => button.addEventListener('click', () => open(button)));
    document.getElementById(closeId)?.addEventListener('click', close);
    document.getElementById(cancelId)?.addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });

    form.addEventListener('submit', async event => {
        event.preventDefault();               // stops the global submit handler from taking over
        if (!ready || saving) return;
        const body = new FormData(form);
        const version = requestVersion;
        saving = true;
        setEnabled(false);
        setStatus('Saving...');
        try {
            await request(PAGE, {method: 'POST', body}, 'Unable to save SKU.');
            await navigate(currentUrl);       // replaces window.location.reload()
        } catch (error) {
            if (version === requestVersion) setStatus(error.message, true);
        } finally {
            saving = false;
            setEnabled(ready);
        }
    });

    return {modal, close};
}

/* ============ COMPOSITE AJAX ROWS ============ */
function addCompositeAjaxRow(item = {}) {
    const row = document.createElement('div');
    const rowId = ++compositeRowId;
    row.className = 'sku-edit-row';
    row.innerHTML = `
        <div class="sku-edit-loose-group">
            <label for="compositeLoose${rowId}">Loose item <span>*</span></label>
            <select id="compositeLoose${rowId}" class="sku-edit-loose-input" name="loose_code[]" required>
                <option value="">Select loose item</option>
            </select>
        </div>
        <div class="sku-edit-quantity-group">
            <label for="compositeQuantity${rowId}">Quantity per pack <span>*</span></label>
            <input id="compositeQuantity${rowId}" class="sku-edit-quantity-input"
                type="number" name="quantity[]" min="1" step="1" required>
        </div>
        <button type="button" class="sku-edit-btn-remove">Remove</button>
    `;

    const select = row.querySelector('select');
    const quantity = row.querySelector('[name="quantity[]"]');
    if (looseItemTemplate) select.innerHTML = looseItemTemplate.innerHTML;
    select.value = item.item_code ?? '';
    quantity.value = item.quantity ?? 1;

    row.querySelector('.sku-edit-btn-remove').addEventListener('click', () => {
        if (compositeAjaxRows.children.length <= 1) {
            compositeAjaxStatus.textContent = 'Keep at least one loose item.';
            compositeAjaxStatus.classList.add('error');
            return;
        }
        row.remove();
    });
    compositeAjaxRows.append(row);
}

/* ============ DELETE / SUCCESS MODALS ============ */
function closeNormalDelete() {
    if (!normalDeleteModal) return;
    normalDeleteModal.hidden = true;
    document.body.classList.remove('modal-open');
}

function closeDeleteModal() {
    if (!deleteModal) return;
    deleteModal.hidden = true;
    const codeInput = document.getElementById('deleteCompositeCode');
    if (codeInput) codeInput.value = '';
    document.body.classList.remove('modal-open');
}

function closeSuccessModal() {
    if (!successModal) return;
    successModal.remove();
    successModal = null;
    document.body.classList.remove('modal-open');
}

/* ============ INIT (runs after every content swap) ============ */
function initPage() {
    rows = document.getElementById('componentRows');
    addButton = document.getElementById('addItem');
    editModal = document.getElementById('editCompositeModal');
    successModal = document.getElementById('successModal');
    deleteModal = document.getElementById('deleteCompositeModal');
    normalDeleteModal = document.getElementById('deleteNormalModal');
    compositeAjaxRows = document.getElementById('compositeAjaxRows');
    compositeAjaxStatus = document.getElementById('compositeAjaxStatus');
    looseItemTemplate = document.getElementById('componentTemplate')
        ?.content.querySelector('select[name="loose_code[]"]') || null;

    document.body.classList.toggle('modal-open', !!(editModal || successModal));

    // Uppercase inputs
    document.querySelectorAll('.uppercase-input').forEach(input => {
        input.addEventListener('input', () => {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            input.value = input.value.toLocaleUpperCase();
            if (start !== null && end !== null) input.setSelectionRange(start, end);
        });
        input.value = input.value.toLocaleUpperCase();
    });

    // Loose SKU edit modal
    normalEditController = setupSkuEditModal({
        modalId: 'normalEditModal', formId: 'normalEditForm', statusId: 'normalEditStatus',
        closeId: 'closeNormalEdit', cancelId: 'cancelNormalEdit',
        triggerSelector: '.ajax-edit-normal', endpoint: 'normal_sku',
        populate(result, form) {
            ['product_sku', 'product_name', 'brand', 'wmdp', 'sbhdp', 'emdp', 'sgdp'].forEach(field => {
                form.elements[field].value = result.sku[field] ?? '';
            });
        }
    });

    // Composite edit modal
    compositeEditController = setupSkuEditModal({
        modalId: 'compositeAjaxModal', formId: 'compositeAjaxForm', statusId: 'compositeAjaxStatus',
        closeId: 'closeCompositeAjax', cancelId: 'cancelCompositeAjax',
        triggerSelector: '.ajax-edit-composite', endpoint: 'composite_sku',
        clear() { compositeAjaxRows.innerHTML = ''; },
        populate(result, form) {
            if (!looseItemTemplate) throw new Error('No loose SKUs are available. Add a loose SKU before editing this composite.');
            form.elements.original_code.value = result.composite.item_code;
            form.elements.item_code.value = result.composite.item_code;
            form.elements.item_description.value = result.composite.item_description || '';
            (result.composite.loose_items || []).forEach(item => addCompositeAjaxRow(item));
            if (compositeAjaxRows.children.length === 0) addCompositeAjaxRow();
        }
    });

    document.getElementById('addCompositeAjaxItem')?.addEventListener('click', () => {
        if (compositeAjaxRows.children.length >= 100) {
            compositeAjaxStatus.textContent = 'Maximum 100 loose items allowed.';
            compositeAjaxStatus.classList.add('error');
            return;
        }
        addCompositeAjaxRow();
    });

    // Delete loose SKU modal
    document.querySelectorAll('.open-normal-delete').forEach(button => button.addEventListener('click', () => {
        document.getElementById('deleteNormalSku').value = button.dataset.normalSku || '';
        document.getElementById('deleteNormalDescription').textContent =
            (button.dataset.normalSku || '') + (button.dataset.normalName ? ' - ' + button.dataset.normalName : '');
        normalDeleteModal.hidden = false;
        document.body.classList.add('modal-open');
    }));
    document.getElementById('closeNormalDelete')?.addEventListener('click', closeNormalDelete);
    document.getElementById('cancelNormalDelete')?.addEventListener('click', closeNormalDelete);
    normalDeleteModal?.addEventListener('click', e => { if (e.target === normalDeleteModal) closeNormalDelete(); });

    // Delete composite modal
    document.querySelectorAll('.open-delete-modal').forEach(button => button.addEventListener('click', () => {
        const code = button.dataset.compositeCode || '';
        const name = button.dataset.compositeName || '';
        document.getElementById('deleteCompositeCode').value = code;
        document.getElementById('deleteCompositeDescription').textContent = name ? `${code} - ${name}` : code;
        deleteModal.hidden = false;
        document.body.classList.add('modal-open');
        document.getElementById('cancelDeleteComposite')?.focus();
    }));
    document.getElementById('closeDeleteModal')?.addEventListener('click', closeDeleteModal);
    document.getElementById('cancelDeleteComposite')?.addEventListener('click', closeDeleteModal);
    deleteModal?.addEventListener('click', e => { if (e.target === deleteModal) closeDeleteModal(); });

    // Success modal
    document.getElementById('closeSuccessModal')?.addEventListener('click', closeSuccessModal);
    document.getElementById('confirmSuccessModal')?.addEventListener('click', closeSuccessModal);
    successModal?.addEventListener('click', e => { if (e.target === successModal) closeSuccessModal(); });

    // Add / remove rows (Add Composite form)
    if (rows && addButton) {
        addButton.addEventListener('click', () => {
            if (rows.children.length >= 100) {
                alert('A composite can contain up to 100 loose items.');
                return;
            }
            rows.append(document.getElementById('componentTemplate').content.cloneNode(true));
        });

        rows.addEventListener('click', event => {
            const button = event.target.closest('.remove-item');
            if (!button) return;
            if (rows.children.length === 1) {
                alert('Keep at least one loose item.');
                return;
            }
            button.closest('.component-row').remove();
        });
    }
}

/* ============ ESCAPE KEY (registered once) ============ */
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;

    for (const controller of [normalEditController, compositeEditController]) {
        if (controller && !controller.modal.hidden) { controller.close(); return; }
    }
    if (normalDeleteModal && !normalDeleteModal.hidden) { closeNormalDelete(); return; }
    if (deleteModal && !deleteModal.hidden) { closeDeleteModal(); return; }
    if (successModal) { closeSuccessModal(); return; }
    if (editModal) { navigate(PAGE); }
});

initPage();
</script>
</body>
</html>