<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

// Require an authenticated admin.
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
$_SESSION['promo_csrf'] ??= bin2hex(random_bytes(32));

header('Cache-Control: private, no-store');

$activeNav = 'promo_setup';
$navBasePath = '../';
$pageTitle = 'Promo Setup';
$adminUsername = $_SESSION['admin_username'] ?? '';

function promoEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
    );
}

function validatePromo(
    array $input,
    array $products,
    array $registeredPromos,
    ?string $originalCode = null
): array {
    if (
        !is_string($input['item_code'] ?? null) ||
        !is_string($input['item_description'] ?? null)
    ) {
        throw new InvalidArgumentException(
            'Enter a promo SKU and name.'
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
            'Promo SKU must be 1-50 letters, numbers, dots, ' .
            'underscores, slashes or hyphens.'
        );
    }

    if ($name === '' || mb_strlen($name) > 255) {
        throw new InvalidArgumentException(
            'Promo name is required and must not exceed 255 characters.'
        );
    }

    if (isset($registeredPromos[$code]) && $code !== $originalCode) 
    {
        throw new InvalidArgumentException(
            'This promo SKU already exists.'
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

        if ($sku === $code || isset($registeredPromos[$sku])) {
            throw new InvalidArgumentException(
                'A promo cannot contain itself or another registered promo.'
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

$error = null;
$success = $_SESSION['promo_success'] ?? null;

unset($_SESSION['promo_success']);

$products = [];
$promos = [];
$registeredPromos = [];
$pdo = null;

try {
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

    $productQuery = $pdo->query(
        'SELECT product_sku, product_name
         FROM price_code
         ORDER BY product_sku'
    );

    foreach ($productQuery as $row) {
        $products[strtoupper(trim($row['product_sku']))] = $row;
    }

    $promos = $pdo->query(
        'SELECT item_code, item_description, loose_items
         FROM composite_items
         ORDER BY item_code'
    )->fetchAll();

    foreach ($promos as $promo) {
        $registeredPromos[
            strtoupper(trim($promo['item_code']))
        ] = true;
    }
} catch (PDOException $exception) {
    error_log('Promo Setup load failed: ' . $exception->getMessage());

    $error = 'Unable to load promo data. Check the database connection and tables.';
    $pdo = null;
}

// Create, update or delete a promo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo !== null) {
    try {
        if (
            !is_string($_POST['csrf'] ?? null) ||
            !hash_equals($_SESSION['promo_csrf'], $_POST['csrf'])
        ) {
            throw new InvalidArgumentException(
                'Your form expired. Reload this page and try again.'
            );
        }

        $action = is_string($_POST['action'] ?? null)
            ? $_POST['action']
            : 'create';

        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            throw new InvalidArgumentException('Invalid promo action.');
        }

        // Delete promo function
        if ($action === 'delete') {
            $deleteCode = is_string($_POST['original_code'] ?? null)
                ? strtoupper(trim($_POST['original_code']))
                : '';

            if (
                $deleteCode === '' ||
                !isset($registeredPromos[$deleteCode])
            ) {
                throw new InvalidArgumentException(
                    'The selected promo could not be found.'
                );
            }

            $statement = $pdo->prepare(
                'DELETE FROM composite_items
                 WHERE item_code = :item_code'
            );

            $statement->execute([
                'item_code' => $deleteCode,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException(
                    'The selected promo no longer exists.'
                );
            }

            $_SESSION['promo_success'] =
                'Promo ' . $deleteCode . ' was deleted successfully.';

            header('Location: promo_setup.php');
            exit;
        }

        // Create and update promo
        $originalCode = null;

        if ($action === 'update') {
            $originalCode = is_string($_POST['original_code'] ?? null)
                ? strtoupper(trim($_POST['original_code']))
                : '';

            if (
                $originalCode === '' ||
                !isset($registeredPromos[$originalCode])
            ) {
                throw new InvalidArgumentException(
                    'The promo being edited could not be found.'
                );
            }

            // The database SKU is authoritative during an update.
            $_POST['item_code'] = $originalCode;
        }

        $data = validatePromo(
            $_POST,
            $products,
            $registeredPromos,
            $originalCode
        );

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

            $_SESSION['promo_success'] =
                'Promo ' . $data['item_code'] . ' updated successfully.';
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

            $_SESSION['promo_success'] =
                'Promo ' . $data['item_code'] . ' added successfully.';
        }

        header('Location: promo_setup.php');
        exit;
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (JsonException $exception) {
        error_log(
            'Promo Setup JSON error: ' . $exception->getMessage()
        );

        $error = 'Unable to prepare the loose-item information.';
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            $error = 'That promo SKU already exists.';
        } else {
            error_log(
                'Promo Setup database error: ' .
                $exception->getMessage()
            );

            $error = 'Unable to save the promo. Please try again.';
        }
    }
}

// Determine whether an existing promo is being edited.
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

$editingPromo = null;

if ($requestedEditCode !== '') {
    foreach ($promos as $promo) {
        $currentCode = strtoupper(
            trim((string) $promo['item_code'])
        );

        if ($currentCode === $requestedEditCode) {
            $editingPromo = $promo;
            break;
        }
    }

    if ($editingPromo === null && $error === null) {
        $error = 'The selected promo could not be found.';
    }
}

$isEditing = $editingPromo !== null;

$originalCode = $isEditing
    ? strtoupper(trim((string) $editingPromo['item_code']))
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
        trim((string) $editingPromo['item_description']),
        'UTF-8'
    );

    $savedItems = json_decode(
        $editingPromo['loose_items'] ?? '[]',
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
    $formRows[] = [
        'code' => '',
        'quantity' => '1',
    ];
}

function renderPromoComponent(
    array $products,
    array $registeredPromos,
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
                        <option value="<?= promoEscape($currentCode) ?>" selected>
                            <?= promoEscape($currentCode . ' - SAVED ITEM') ?>
                        </option>
                    <?php endif; ?>

                    <?php foreach ($products as $code => $product): ?>
                        <?php if (isset($registeredPromos[$code])) continue; ?>
                        <option
                            value="<?= promoEscape($code) ?>"
                            <?= $currentCode === (string) $code ? 'selected' : '' ?>
                        >
                            <?= promoEscape($code . ' - ' . ($product['product_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="form-group">
            <label>
                <span class="form-label">Quantity per pack <span class="required">*</span></span>
                <input class="form-control" name="quantity[]" type="number" min="1" max="1000000" step="1" value="<?= promoEscape($currentQuantity) ?>" required>
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
<title>Promo Setup - S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="icon" href="../images/icon-sasia.png">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── MAIN ── */
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

/* ── LAYOUT ── */
.layout{display:flex;margin-top:var(--topbar-h);}
.main{margin-left:var(--sidebar-w);flex:1;padding:28px 32px 48px;max-width:1200px;min-width:0;transition:margin-left .25s ease;}
body.sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed);}
@media(max-width:900px){.main{margin-left:0;padding:20px 16px 40px;}
body.sidebar-collapsed .main{margin-left:0 !important;}}

/* ── PAGE HEADER ── */
.page-header{margin-bottom:32px;}
.page-header h1{font-size:28px;font-weight:800;margin-bottom:4px;color:var(--ink);}
.page-header p{font-size:14px;color:var(--gray-500);}

/* ── CARD ── */
.card{background:var(--white);border-radius:var(--radius-lg);padding:28px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);margin-bottom:24px;}
.card-title{font-size:16px;font-weight:800;margin-bottom:20px;color:var(--ink);}

/* ── FORM ── */
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

/* ── BUTTONS ── */
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

/* ── ALERT ── */
.alert{padding:14px 16px;border-radius:var(--radius-md);margin-bottom:16px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;}
.alert-success{background:#d1fae5;border:1px solid #6ee7b7;color:#047857;}
.alert-error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.alert-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;}
.alert-warning{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}

/* ── TABLE ── */
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

/* ── UPDATE AND DELETE FUNCTION ── */
.uppercase-input {text-transform: uppercase;}
.promo-actions {display: flex;align-items: center;justify-content: center;gap: 8px;white-space: nowrap;}
.delete-promo-form {display: inline;margin: 0;}
.btn-small {min-height: 34px;padding: 7px 12px;justify-content: center;font-size: 12px;}
@media (max-width: 600px) {.promo-actions {align-items: stretch;flex-direction: column;}
.promo-actions .btn {width: 100%;}}

/* ── EDIT FUNCTION MODAL ── */
[hidden] {display: none !important;}
body.modal-open {overflow: hidden;}
.modal-backdrop {position: fixed;inset: 0;z-index: 1000;display: flex;align-items: center;justify-content: center;padding: 20px;background: rgba(27, 27, 31, 0.5);backdrop-filter: blur(4px);}
.modal-card {position: relative;width: min(100%, 520px);max-height: calc(100vh - 40px);overflow-y: auto;padding: 30px;margin: 0;background: var(--white);border-radius: var(--radius-lg);box-shadow: 0 24px 70px rgba(20, 20, 30, 0.25);animation: promoModalOpen 0.2s ease-out;}
.promo-edit-card {width: min(100%, 950px);}
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
@keyframes promoModalOpen {from {opacity: 0;transform: translateY(8px) scale(0.97);}
to {opacity: 1;transform: translateY(0) scale(1);}}
@media (max-width: 600px) {.modal-backdrop {padding: 12px;}.modal-card {padding: 26px 20px 20px;}.modal-actions {flex-direction: column-reverse;}.modal-actions .btn {justify-content: center;width: 100%;}}
.readonly-control {color: var(--gray-700);background: var(--gray-100);cursor: not-allowed;}

/* ── PROMO COMPONENT CONTROLS ── */
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
.promo-code{font-weight:700;white-space:nowrap;}
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
        <h1>Promo Setup</h1>
        <p>Create promos and define the loose items included in each pack.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= promoEscape($error) ?></div>
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

            <h2 class="modal-title" id="successModalTitle">
                Successful
            </h2>

            <p class="modal-message">
                <?= promoEscape($success) ?>
            </p>

            <button type="button" class="btn btn-success modal-confirm-button" id="confirmSuccessModal">
                Okay
            </button>
        </div>
    </div>
<?php endif; ?>
    
    <?php if ($pdo !== null && $products): ?>

        <?php if ($isEditing): ?>
            <div class="modal-backdrop" id="editPromoModal" role="dialog" aria-modal="true" aria-labelledby="addPromoTitle">
        <?php endif; ?>

        <section class="card <?= $isEditing ? 'modal-card promo-edit-card' : '' ?>" id="promoForm" aria-labelledby="addPromoTitle">
            <?php if ($isEditing): ?>
                <a href="promo_setup.php" class="modal-dismiss" aria-label="Close edit popup">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12"/>
                        <path d="M18 6L6 18"/>
                    </svg>
                </a>
            <?php endif; ?>

            <h2 class="card-title" id="addPromoTitle"><?= $isEditing ? 'Edit Promo' : 'Add a Promo' ?></h2>

            <p class="section-description">
                Use the promo SKU exactly as it appears in your Tax Invoice.
            </p>

            <form method="post" action="promo_setup.php">
                <input type="hidden" name="csrf" value="<?= promoEscape($_SESSION['promo_csrf']) ?>">

                <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">

                <input type="hidden" name="original_code" value="<?= promoEscape($originalCode) ?>">

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="promoSku">Promo SKU <span class="required">*</span></label>

                        <?php if ($isEditing): ?>
                            <input class="form-control readonly-control" id="promoSku" type="text" value="<?= promoEscape($originalCode) ?>" readonly aria-describedby="promoSkuHint">
                            <input type="hidden" name="item_code" value="<?= promoEscape($originalCode) ?>">
                            <span class="form-hint" id="promoSkuHint">Promo SKU cannot be changed.</span>
                        <?php else: ?>
                            <input class="form-control uppercase-input" id="promoSku" name="item_code" maxlength="50" value="<?= promoEscape($formCode) ?>" placeholder="Enter the promo SKU" autocomplete="off"required>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="promoName">Promo name <span class="required">*</span></label>
                        <input class="form-control uppercase-input" id="promoName" name="item_description" maxlength="255" value="<?= promoEscape($formName) ?>" placeholder="Enter the promo name" autocomplete="off" required>
                    </div>
                </div>

                <h3 class="component-heading">Loose items</h3>
                <p class="form-hint component-help">Choose each product once and enter its quantity for one pack.</p>

                <div id="componentRows">
                    <?php foreach ($formRows as $values) {renderPromoComponent($products, $registeredPromos, $values);}?>
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
                            <path
                                d="M19 21H5a2 2 0 0 1-2-2V5a2
                                2 0 0 1 2-2h12l4 4v12a2
                                2 0 0 1-2 2Z"
                            />
                            <path d="M17 21v-8H7v8M7 3v5h8"/>
                        </svg>

                        <?= $isEditing ? 'Update promo' : 'Save promo' ?>
                    </button>

                    <?php if ($isEditing): ?>
                        <a href="promo_setup.php" class="btn btn-secondary">
                            Cancel edit
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <template id="componentTemplate">
                <?php renderPromoComponent($products, $registeredPromos, ['code' => '', 'quantity' => '1']); ?>
            </template>
        </section>
        <?php if ($isEditing): ?></div><?php endif; ?>

        <?php elseif ($pdo !== null): ?>
        <div class="alert alert-warning" role="status">
            Add products to the price list before setting up a promo.
        </div>
    <?php endif; ?>

    <?php if ($pdo !== null): ?>
        <section class="card" aria-labelledby="savedPromosTitle">
            <h2 class="card-title" id="savedPromosTitle">Saved Promos</h2>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Saved promos">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Promo SKU</th>
                            <th scope="col">Promo name</th>
                            <th scope="col">Loose items per pack</th>
                            <th scope="col" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promos as $promo): ?>
                            <tr>
                                <td class="promo-code"><?= promoEscape($promo['item_code']) ?></td>
                                <td><?= promoEscape($promo['item_description']) ?></td>
                                <td>
                                    <?php $items = json_decode($promo['loose_items'] ?? '[]', true); ?>
                                    <?php if (is_array($items) && $items): ?>
                                        <ul class="components">
                                            <?php foreach ($items as $item): ?>
                                                <?php if (!is_array($item)) continue; ?>
                                                <li>
                                                    <?= promoEscape($item['item_code'] ?? '') ?>
                                                    &times; <?= (int) ($item['quantity'] ?? 0) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="text-muted">Not configured</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                <div class="promo-actions">
                                    <a class="btn btn-secondary btn-small" href="promo_setup.php?edit=<?= rawurlencode($promo['item_code']) ?>#promoForm">
                                        Edit
                                    </a>

                                    <button type="button" class="btn btn-danger btn-small open-delete-modal" data-promo-code="<?= promoEscape($promo['item_code']) ?>" data-promo-name="<?= promoEscape($promo['item_description']) ?>">
                                        Delete
                                    </button>
                                </div>
                            </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$promos): ?>
                            <tr><td colspan="4" class="empty-state">No promos available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
        <div class="modal-backdrop" id="deletePromoModal" role="dialog" aria-modal="true" aria-labelledby="deletePromoTitle" hidden>
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

            <h2 class="modal-title" id="deletePromoTitle">
                Delete promo?
            </h2>

            <p class="modal-message">
                You are about to delete
                <strong id="deletePromoDescription"></strong>.
                Its loose-item configuration will be removed.
            </p>

            <form method="post" action="promo_setup.php">
                <input type="hidden" name="csrf" value="<?= promoEscape($_SESSION['promo_csrf']) ?>">

                <input type="hidden" name="action" value="delete">

                <input type="hidden" name="original_code" id="deletePromoCode" value="">

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="cancelDeletePromo">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete promo</button>
                </div>
            </form>
        </div>
    </div>
</main>
</div>

<script>
const rows = document.getElementById('componentRows');
const addButton = document.getElementById('addItem');
const uppercaseInputs = document.querySelectorAll('.uppercase-input');
const editModal = document.getElementById('editPromoModal');

if (editModal) {
    document.body.classList.add('modal-open');
}

uppercaseInputs.forEach(input => {
    input.addEventListener('input', () => {
        const start = input.selectionStart;
        const end = input.selectionEnd;

        input.value = input.value.toLocaleUpperCase();

        if (
            start !== null &&
            end !== null
        ) {
            input.setSelectionRange(start, end);
        }
    });

    input.value = input.value.toLocaleUpperCase();
});

const deleteModal =
    document.getElementById('deletePromoModal');

const deleteCodeInput =
    document.getElementById('deletePromoCode');

const deleteDescription =
    document.getElementById('deletePromoDescription');

const closeDeleteButton =
    document.getElementById('closeDeleteModal');

const cancelDeleteButton =
    document.getElementById('cancelDeletePromo');

function openDeleteModal(button) {
    if (
        !deleteModal ||
        !deleteCodeInput ||
        !deleteDescription
    ) {
        return;
    }

    const code = button.dataset.promoCode || '';
    const name = button.dataset.promoName || '';

    deleteCodeInput.value = code;
    deleteDescription.textContent =
        name ? `${code} — ${name}` : code;

    deleteModal.hidden = false;
    document.body.classList.add('modal-open');

    cancelDeleteButton?.focus();
}

function closeDeleteModal() {
    if (!deleteModal) {
        return;
    }

    deleteModal.hidden = true;
    deleteCodeInput.value = '';
    document.body.classList.remove('modal-open');
}

document.querySelectorAll('.open-delete-modal')
    .forEach(button => {
        button.addEventListener('click', () => {
            openDeleteModal(button);
        });
    });

closeDeleteButton?.addEventListener(
    'click',
    closeDeleteModal
);

cancelDeleteButton?.addEventListener(
    'click',
    closeDeleteModal
);

deleteModal?.addEventListener('click', event => {
    if (event.target === deleteModal) {
        closeDeleteModal();
    }
});

const successModal =
    document.getElementById('successModal');

const closeSuccessButton =
    document.getElementById('closeSuccessModal');

const confirmSuccessButton =
    document.getElementById('confirmSuccessModal');

function closeSuccessModal() {
    if (!successModal) {
        return;
    }

    successModal.remove();
    document.body.classList.remove('modal-open');
}

if (successModal) {
    document.body.classList.add('modal-open');

    closeSuccessButton?.addEventListener(
        'click',
        closeSuccessModal
    );

    confirmSuccessButton?.addEventListener(
        'click',
        closeSuccessModal
    );

    successModal.addEventListener('click', event => {
        if (event.target === successModal) {
            closeSuccessModal();
        }
    });
}

if (rows && addButton) {
    addButton.addEventListener('click', () => {
        if (rows.children.length >= 100) {
            alert('A promo can contain up to 100 loose items.');
            return;
        }

        const template = document.getElementById('componentTemplate');
        rows.append(template.content.cloneNode(true));
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

document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') {
        return;
    }

    if (deleteModal && !deleteModal.hidden) {
        closeDeleteModal();
        return;
    }

    if (successModal) {
        closeSuccessModal();
        return;
    }

    if (editModal) {
        window.location.href = 'promo_setup.php';
    }
});
</script>
</body>
</html>
