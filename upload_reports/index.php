<?php
/**
 * S ASIA SALES REPORT - Data Upload Interface
 * Web form untuk upload Order History & Tax Invoice
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

$isLoggedIn = isset($_SESSION['admin_id']);
$adminUsername = $_SESSION['admin_username'] ?? '';

// Kalau tak login, redirect
if (!$isLoggedIn) {
    header('Location: ../index.php');
    exit;
}

// Handle idle timeout
if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 7200) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Define active nav for sidebar
$activeNav = 'upload';
$navBasePath = '../';

// Handle Order History and Tax Invoice uploads in this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  ini_set('max_execution_time', '0');
  ini_set('memory_limit', '2048M');

  require_once __DIR__ . '/../vendor/autoload.php';

  function normalizeHeader($header) {
    if ($header === null) return '';
    $header = trim((string)$header);
    $header = str_replace("\xEF\xBB\xBF", '', $header);
    return preg_replace('/[^a-z0-9]/', '', strtolower($header));
  }

  function getRowValue($row, $headerMap, $keys) {
    foreach ($keys as $key) {
      $normalized = normalizeHeader($key);
      if (isset($headerMap[$normalized])) {
        return $row[$headerMap[$normalized]] ?? '';
      }
    }
    return '';
  }

  function parseDate($value) {
    if ($value instanceof DateTime) return $value->format('Y-m-d H:i:s');
    if ($value === null || trim((string)$value) === '') return null;
    $value = trim((string)$value);
    if (is_numeric($value) && $value > 40000 && $value < 60000) {
      return date('Y-m-d H:i:s', (int)(($value - 25569) * 86400));
    }
    foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'] as $format) {
      $date = DateTime::createFromFormat($format, $value);
      if ($date !== false) return $date->format('Y-m-d H:i:s');
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
  }

  function parseAmount($value) {
    if ($value === null || trim((string)$value) === '') return 0.00;
    if (is_numeric($value)) return (float)$value;
    return (float)str_replace(['RM', 'SGD', 'USD', ',', ' ', '(', ')', '"'], '', trim((string)$value));
  }

  function getCompany($orderId, PDO $pdo) {
    $orderId = strtoupper(trim($orderId));
    $prefix = 'MYHQ';
    if (strpos($orderId, 'MYHQ') === 0) $prefix = 'MYHQ';
    elseif (strpos($orderId, 'MYBT') === 0 || strpos($orderId, 'MYBTCSB') === 0) $prefix = 'MYBT';
    elseif (strpos($orderId, 'SGHQ') === 0 || strpos($orderId, 'SG') === 0) $prefix = 'SGHQ';
    elseif (strpos($orderId, 'BN') === 0) $prefix = 'BNHQ';

    $map = [
      'MYHQ' => ['code' => 'MY', 'currency' => 'MYR'],
      'MYBT' => ['code' => 'MY', 'currency' => 'MYR'],
      'SGHQ' => ['code' => 'SG', 'currency' => 'SGD'],
      'BNHQ' => ['code' => 'BN', 'currency' => 'BND'],
    ];
    $companyCode = $map[$prefix]['code'] ?? 'MY';
    $currency = $map[$prefix]['currency'] ?? 'MYR';

    $stmt = $pdo->prepare('SELECT id, invoice_prefix FROM companies WHERE company_code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$companyCode]);
    $result = $stmt->fetch();
    if (!$result) {
      $insert = $pdo->prepare('INSERT INTO companies (company_code, company_name, currency_code, invoice_prefix) VALUES (?, ?, ?, ?)');
      $insert->execute([$companyCode, $companyCode === 'MY' ? 'Malaysia' : $companyCode, $currency, $prefix]);
      $companyId = (int)$pdo->lastInsertId();
    } else {
      $companyId = (int)$result['id'];
      $prefixes = array_map('trim', explode(',', $result['invoice_prefix']));
      if (!in_array($prefix, $prefixes, true)) {
        $prefixes[] = $prefix;
        $pdo->prepare('UPDATE companies SET invoice_prefix = ? WHERE id = ?')->execute([implode(',', $prefixes), $companyId]);
      }
    }
    return ['company_id' => $companyId, 'company_code' => $companyCode, 'currency_code' => $currency, 'invoice_prefix' => $prefix];
  }

  function loadFile($filePath, $originalName, $delimiter) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('Only .xlsx and .csv files are supported.');
    if ($ext === 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
      $reader->setDelimiter($delimiter ?: ',');
    } else {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    }
    $rows = $reader->load($filePath)->getActiveSheet()->toArray(null, true, true, false);
    if (count($rows) < 2) throw new RuntimeException('File is empty.');
    return $rows;
  }

  function processFile($filePath, $originalName, PDO $pdo, $delimiter) {
    $hash = hash_file('sha256', $filePath);
    $stmt = $pdo->prepare('SELECT id, status FROM import_batches WHERE file_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $existing = $stmt->fetch();
    if ($existing && $existing['status'] === 'completed') return ['success' => 0, 'skipped' => true, 'message' => 'File already imported.'];
    if ($existing) $pdo->prepare('UPDATE import_batches SET file_hash = NULL WHERE id = ?')->execute([$existing['id']]);

    $rows = loadFile($filePath, $originalName, $delimiter);
    $headerMap = [];
    foreach ($rows[0] as $i => $header) $headerMap[normalizeHeader($header)] = $i;

    $hasOrderId = isset($headerMap['orderid']);
    $hasDateTime = isset($headerMap['datetime']) || isset($headerMap['orderdatetime']) || isset($headerMap['dateandtime']);
    $hasCommission = isset($headerMap['commissionmonth']);
    $hasItemCode = isset($headerMap['itemcode']) || isset($headerMap['sku']) || isset($headerMap['itemno']);
    $isOrderHistory = $hasOrderId && $hasDateTime;
    $isTaxInvoice = $hasCommission && $hasItemCode;
    if (!$isOrderHistory && !$isTaxInvoice) {
      $orderHistoryCols = ['memberid', 'membertype', 'membername', 'subtotal', 'ordertotal'];
      $isOrderHistory = $hasOrderId && !array_diff($orderHistoryCols, array_keys($headerMap));
      $taxCols = ['itemdescription', 'qty', 'invoiceamount'];
      $isTaxInvoice = $hasCommission && $hasItemCode && !array_diff($taxCols, array_keys($headerMap));
    }
    if (!$isOrderHistory && !$isTaxInvoice) throw new RuntimeException('Unknown file format. Headers: ' . implode(', ', $rows[0]));

    $firstOrderId = '';
    foreach (array_slice($rows, 1) as $row) {
      $firstOrderId = trim(getRowValue($row, $headerMap, ['orderid', 'orderno']));
      if ($firstOrderId) break;
    }
    $company = getCompany($firstOrderId, $pdo);
    $stmt = $pdo->prepare('INSERT INTO import_batches (company_id, file_type, original_filename, file_hash, total_rows, status) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$company['company_id'], $isOrderHistory ? 'ORDER_HISTORY' : 'TAX_INVOICE', $originalName, $hash, count($rows) - 1, 'processing']);
    $batchId = (int)$pdo->lastInsertId();
    $success = 0;
    $failed = 0;

    try {
      $pdo->beginTransaction();
      $orderStmt = $pdo->prepare("INSERT INTO orders (company_id, import_batch_id, order_id, order_datetime, member_code, member_type, member_name, remark, delivery_method, mobile_no, order_type, sub_total, shipping_fee, voucher_discount, discount, convenience_fee, order_total, gst, total_bv, total_pv, total_tp, payment_mode, order_status, delivery_status, payment_gateway, payment_gateway_id, currency_code, invoice_prefix) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE import_batch_id = VALUES(import_batch_id), order_datetime = VALUES(order_datetime), member_code = VALUES(member_code), member_type = VALUES(member_type), member_name = VALUES(member_name), remark = VALUES(remark), delivery_method = VALUES(delivery_method), mobile_no = VALUES(mobile_no), order_type = VALUES(order_type), sub_total = VALUES(sub_total), shipping_fee = VALUES(shipping_fee), voucher_discount = VALUES(voucher_discount), discount = VALUES(discount), convenience_fee = VALUES(convenience_fee), order_total = VALUES(order_total), gst = VALUES(gst), total_bv = VALUES(total_bv), total_pv = VALUES(total_pv), total_tp = VALUES(total_tp), payment_mode = VALUES(payment_mode), order_status = VALUES(order_status), delivery_status = VALUES(delivery_status), payment_gateway = VALUES(payment_gateway), payment_gateway_id = VALUES(payment_gateway_id), currency_code = VALUES(currency_code), invoice_prefix = VALUES(invoice_prefix)");
      $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, commission_month, cdo, cdo_created_date, product_type, item_code, item_description, brand, email, total_weight, qty, bv, pv, total_bv, total_pv, total_retail_price, discount, invoice_amount, total_invoice_amount_paid, order_processed_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
      $orderLookup = $pdo->prepare('SELECT id FROM orders WHERE company_id = ? AND order_id = ? LIMIT 1');
      $deleteItems = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');
      $memberStmt = $pdo->prepare("INSERT INTO members (company_id, member_code, member_name, mobile_no) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, '')) ON DUPLICATE KEY UPDATE member_name = COALESCE(NULLIF(members.member_name, ''), VALUES(member_name)), mobile_no = COALESCE(NULLIF(members.mobile_no, ''), VALUES(mobile_no))");
      $replacedOrders = [];

      foreach (array_slice($rows, 1) as $row) {
        $hasData = false;
        foreach ($row as $value) if (trim((string)$value) !== '') { $hasData = true; break; }
        if (!$hasData) continue;
        $orderId = trim(getRowValue($row, $headerMap, ['orderid', 'orderno']));
        if (!$orderId) { $failed++; continue; }
        $comp = getCompany($orderId, $pdo);
        if ($isOrderHistory) {
          $datetime = parseDate(getRowValue($row, $headerMap, ['dateandtime', 'orderdatetime', 'datetime', 'orderdate']));
          $memberCode = trim((string)getRowValue($row, $headerMap, ['memberid']));
          if (!$datetime || !$memberCode) { $failed++; continue; }
          $memberStmt->execute([$comp['company_id'], $memberCode, getRowValue($row, $headerMap, ['membername']), getRowValue($row, $headerMap, ['mobileno'])]);
          $orderStmt->execute([$comp['company_id'], $batchId, $orderId, $datetime, $memberCode, getRowValue($row, $headerMap, ['membertype']), getRowValue($row, $headerMap, ['membername']), getRowValue($row, $headerMap, ['remark']), getRowValue($row, $headerMap, ['deliverymethod']), getRowValue($row, $headerMap, ['mobileno']), getRowValue($row, $headerMap, ['ordertype']), parseAmount(getRowValue($row, $headerMap, ['subtotal'])), parseAmount(getRowValue($row, $headerMap, ['shippingfee'])), parseAmount(getRowValue($row, $headerMap, ['voucherdiscount'])), parseAmount(getRowValue($row, $headerMap, ['discount'])), parseAmount(getRowValue($row, $headerMap, ['conveniencefee'])), parseAmount(getRowValue($row, $headerMap, ['ordertotal'])), parseAmount(getRowValue($row, $headerMap, ['gst'])), parseAmount(getRowValue($row, $headerMap, ['totalbv'])), parseAmount(getRowValue($row, $headerMap, ['totalpv'])), parseAmount(getRowValue($row, $headerMap, ['totaltp'])), getRowValue($row, $headerMap, ['paymentmode']), getRowValue($row, $headerMap, ['orderstatus']), getRowValue($row, $headerMap, ['deliverystatus']), getRowValue($row, $headerMap, ['paymentgateway']), getRowValue($row, $headerMap, ['paymentgatewayid']), $comp['currency_code'], $comp['invoice_prefix']]);
          $success++;
          continue;
        }
        $orderLookup->execute([$comp['company_id'], $orderId]);
        $orderDbId = $orderLookup->fetchColumn();
        $itemCode = trim(getRowValue($row, $headerMap, ['itemcode', 'sku', 'itemno']));
        if (!$orderDbId || !$itemCode) { $failed++; continue; }
        if (!isset($replacedOrders[$orderDbId])) { $deleteItems->execute([$orderDbId]); $replacedOrders[$orderDbId] = true; }
        $itemStmt->execute([$orderDbId, getRowValue($row, $headerMap, ['commissionmonth']), getRowValue($row, $headerMap, ['cdo']), parseDate(getRowValue($row, $headerMap, ['cdocreateddate'])), getRowValue($row, $headerMap, ['producttype']), $itemCode, getRowValue($row, $headerMap, ['itemdescription']), getRowValue($row, $headerMap, ['brand']), getRowValue($row, $headerMap, ['email']), parseAmount(getRowValue($row, $headerMap, ['totalweight'])), (int)parseAmount(getRowValue($row, $headerMap, ['qty'])), parseAmount(getRowValue($row, $headerMap, ['bv'])), parseAmount(getRowValue($row, $headerMap, ['pv'])), parseAmount(getRowValue($row, $headerMap, ['totalbv'])), parseAmount(getRowValue($row, $headerMap, ['totalpv'])), parseAmount(getRowValue($row, $headerMap, ['totalretailprice'])), parseAmount(getRowValue($row, $headerMap, ['discount'])), parseAmount(getRowValue($row, $headerMap, ['invoiceamount'])), parseAmount(getRowValue($row, $headerMap, ['totalinvoiceamountpaid'])), getRowValue($row, $headerMap, ['orderprocessedlocation'])]);
        $success++;
      }
      $pdo->prepare('UPDATE import_batches SET successful_rows = ?, failed_rows = ?, status = ?, imported_at = NOW() WHERE id = ?')->execute([$success, $failed, $failed ? 'completed_with_errors' : 'completed', $batchId]);
      $pdo->commit();
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $pdo->prepare('UPDATE import_batches SET status = "failed", error_message = ? WHERE id = ?')->execute([$error->getMessage(), $batchId]);
      throw $error;
    }
    return ['success' => $success, 'failed' => $failed];
  }

  try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $delimiter = substr($_POST['delimiter'] ?? ',', 0, 1) ?: ',';
    $results = ['status' => 'success'];
    foreach (['order_history', 'tax_invoice'] as $type) {
      if (!isset($_FILES[$type]) || $_FILES[$type]['error'] === UPLOAD_ERR_NO_FILE) continue;
      if ($_FILES[$type]['error'] !== UPLOAD_ERR_OK) { $results[$type] = ['error' => 'Upload failed']; $results['status'] = 'error'; continue; }
      try { $results[$type] = processFile($_FILES[$type]['tmp_name'], $_FILES[$type]['name'], $pdo, $delimiter); }
      catch (Throwable $error) { $results[$type] = ['error' => $error->getMessage()]; $results['status'] = 'error'; }
    }
    echo json_encode($results);
  } catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()]);
  }
  exit;
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Upload Reports — S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="icon" href="../images/icon-sasia.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --red:#E0202E;--red-dark:#8E1620;--red-darker:#3B0B0F;
  --teal:#00B4B4;--teal-dark:#008A8A;
  --ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;
  --gray-300:#D8D8DE;--gray-100:#F2F2F4;
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
@media(max-width:900px){
  .main{margin-left:0;padding:20px 16px 40px;}
  body.sidebar-collapsed .main{margin-left:0 !important;}
}

/* ── PAGE HEADER ── */
.page-header{margin-bottom:32px;}
.page-header h1{font-size:28px;font-weight:800;margin-bottom:4px;color:var(--ink);}
.page-header p{font-size:14px;color:var(--gray-500);}
.card{background:var(--white);border-radius:var(--radius-lg);padding:28px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);margin-bottom:24px;}
.card-title{font-size:16px;font-weight:800;margin-bottom:20px;color:var(--ink);}
.upload-group{margin-bottom:24px;}.upload-label{display:block;font-size:13px;font-weight:700;margin-bottom:8px;color:var(--gray-700);}.upload-hint{display:block;font-size:12px;color:var(--gray-500);margin-bottom:12px;}
.upload-dropzone{border:2px dashed var(--gray-300);border-radius:var(--radius-md);padding:32px 20px;text-align:center;cursor:pointer;transition:all .2s;background:var(--gray-100);}.upload-dropzone:hover,.upload-dropzone.dragover{border-color:var(--red);background:#fff0f1;}.upload-dropzone svg{width:40px;height:40px;margin:0 auto 12px;stroke:var(--red);}.upload-dropzone-text{font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;}.upload-dropzone-sub,.progress-text{font-size:12px;color:var(--gray-500);}.upload-input{display:none;}
.file-preview{margin-top:12px;padding:12px 14px;background:var(--gray-100);border-radius:var(--radius-sm);font-size:13px;display:flex;align-items:center;gap:8px;}.file-preview svg{width:16px;height:16px;stroke:var(--green);}.file-preview-clear{margin-left:auto;cursor:pointer;color:var(--red);}
.delimiter-input{width:60px;padding:8px 12px;border:1.5px solid var(--gray-300);border-radius:var(--radius-sm);font-size:13px;font-family:inherit;}.button-group{display:flex;gap:12px;margin-top:24px;flex-wrap:wrap;}.btn{padding:12px 24px;border-radius:var(--radius-md);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}.btn-primary{background:var(--red);color:white;box-shadow:0 4px 12px rgba(224,32,46,.3);}.btn-primary:hover{background:var(--red-dark);}.btn-primary:disabled{opacity:.5;cursor:not-allowed;}.btn-secondary{background:transparent;border:1.5px solid var(--gray-300);color:var(--ink);}.btn-secondary:hover{background:var(--gray-100);}.btn svg{width:16px;height:16px;}
.alert{padding:14px 16px;border-radius:var(--radius-md);margin-bottom:16px;font-size:13px;font-weight:600;}.alert-success{background:#d1fae5;border:1px solid #6ee7b7;color:#047857;}.alert-error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}.progress-bar{width:100%;height:6px;background:var(--gray-100);border-radius:3px;overflow:hidden;margin:12px 0;}.progress-fill{height:100%;background:var(--red);width:0%;transition:width .3s;}.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;}@media(max-width:768px){.grid-2{grid-template-columns:1fr;}}

 </style>
</head>
<body>
<script>
(function(){
  try {
    if (window.innerWidth >= 900 && localStorage.getItem('adminSidebarCollapsed') === '1') {
      document.body.classList.add('sidebar-collapsed');
    }
  } catch (e) {}
})();
</script>

<?php $pageTitle = 'Upload Reports'; $showMobileMenu = true; include __DIR__ . '/../includes/topnav.php'; ?>

<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<!-- LAYOUT -->
<div class="layout">
<main class="main">
  <div class="page-header">
    <h1>Upload Order Reports</h1>
    <p>Import Order History &amp; Tax Invoice data from Excel or CSV files</p>
  </div>

  <div id="statusContainer"></div>

  <!-- UPLOAD FORM -->
  <div class="card">
    <div class="card-title">📁 Select Files</div>
    
    <div class="grid-2">
      <!-- ORDER HISTORY UPLOAD -->
      <div class="upload-group">
        <label class="upload-label">Order History File</label>
        <span class="upload-hint">Supported: .xlsx, .csv</span>
        <div class="upload-dropzone" id="orderHistoryDropZone">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
          </svg>
          <div class="upload-dropzone-text">Drag files here or click</div>
          <div class="upload-dropzone-sub">or paste your Order History file</div>
        </div>
        <div id="orderHistoryPreview"></div>
        <input type="file" id="orderHistoryFile" class="upload-input" accept=".xlsx,.csv">
      </div>

      <!-- TAX INVOICE UPLOAD -->
      <div class="upload-group">
        <label class="upload-label">Tax Invoice File</label>
        <span class="upload-hint">Supported: .xlsx, .csv</span>
        <div class="upload-dropzone" id="taxInvoiceDropZone">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
          </svg>
          <div class="upload-dropzone-text">Drag files here or click</div>
          <div class="upload-dropzone-sub">or paste your Tax Invoice file</div>
        </div>
        <div id="taxInvoicePreview"></div>
        <input type="file" id="taxInvoiceFile" class="upload-input" accept=".xlsx,.csv">
      </div>
    </div>

    <!-- DELIMITER SETTING -->
    <div class="upload-group">
      <label class="upload-label">CSV Delimiter (if CSV file)</label>
      <span class="upload-hint">Usually comma (,) or semicolon (;)</span>
      <input type="text" id="delimiter" class="delimiter-input" value="," placeholder="," maxlength="1">
    </div>

    <!-- ACTION BUTTONS -->
    <div class="button-group">
      <button id="uploadBtn" class="btn btn-primary" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        Upload Files
      </button>
      <button id="clearBtn" class="btn btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="3 6 5 4 21 4"/><path d="M19 4v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>
        </svg>
        Clear
      </button>
    </div>

    <!-- PROGRESS SECTION -->
    <div id="progressSection" style="display:none; margin-top:24px;">
      <div id="progressText" class="progress-text"></div>
      <div class="progress-bar">
        <div class="progress-fill" id="progressFill"></div>
      </div>
    </div>
  </div>

  <!-- RESULTS CARD -->
  <div id="resultsCard" class="card" style="display:none;">
    <div class="card-title">📊 Upload Results</div>
    <div id="resultsContent"></div>
  </div>

</main>
</div><!-- layout -->

<script>
// ============================================================
// DOM References
// ============================================================
const orderHistoryDropZone = document.getElementById('orderHistoryDropZone');
const taxInvoiceDropZone = document.getElementById('taxInvoiceDropZone');
const orderHistoryFile = document.getElementById('orderHistoryFile');
const taxInvoiceFile = document.getElementById('taxInvoiceFile');
const orderHistoryPreview = document.getElementById('orderHistoryPreview');
const taxInvoicePreview = document.getElementById('taxInvoicePreview');
const uploadBtn = document.getElementById('uploadBtn');
const clearBtn = document.getElementById('clearBtn');
const statusContainer = document.getElementById('statusContainer');
const progressSection = document.getElementById('progressSection');
const progressFill = document.getElementById('progressFill');
const progressText = document.getElementById('progressText');
const resultsCard = document.getElementById('resultsCard');
const resultsContent = document.getElementById('resultsContent');
const delimiterInput = document.getElementById('delimiter');

// ============================================================
// Upload Dropzone Setup
// ============================================================
function setupDropZone(dropZone, fileInput, previewDiv) {
  dropZone.addEventListener('click', () => fileInput.click());
  
  dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('dragover');
  });
  
  dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('dragover');
  });
  
  dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    if (e.dataTransfer.files.length > 0) {
      fileInput.files = e.dataTransfer.files;
      showPreview(fileInput, previewDiv);
    }
  });
  fileInput.addEventListener('change', () => showPreview(fileInput, previewDiv));
}

function showPreview(fileInput, previewDiv) {
  if (fileInput.files.length > 0) {
    const file = fileInput.files[0];
    previewDiv.innerHTML = `
      <div class="file-preview">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>
        </svg>
        <span>${file.name}</span>
        <span class="file-preview-clear" onclick="document.getElementById('${fileInput.id}').value=''; document.getElementById('${fileInput.id}').dispatchEvent(new Event('change'));">✕</span>
      </div>
    `;
  } else {
    previewDiv.innerHTML = '';
  }
  updateUploadBtn();
}

function updateUploadBtn() {
  uploadBtn.disabled = !orderHistoryFile.files.length && !taxInvoiceFile.files.length;
}

setupDropZone(orderHistoryDropZone, orderHistoryFile, orderHistoryPreview);
setupDropZone(taxInvoiceDropZone, taxInvoiceFile, taxInvoicePreview);

// ============================================================
// Clear Button
// ============================================================
clearBtn.addEventListener('click', () => {
  orderHistoryFile.value = '';
  taxInvoiceFile.value = '';
  orderHistoryPreview.innerHTML = '';
  taxInvoicePreview.innerHTML = '';
  updateUploadBtn();
  statusContainer.innerHTML = '';
  resultsCard.style.display = 'none';
  progressSection.style.display = 'none';
});

// ============================================================
// Upload Button
// ============================================================
uploadBtn.addEventListener('click', async () => {
  uploadBtn.disabled = true;
  statusContainer.innerHTML = '';
  resultsCard.style.display = 'none';
  progressSection.style.display = 'block';
  progressFill.style.width = '0%';
  progressText.textContent = '⏳ Preparing files...';
  
  const formData = new FormData();
  if (orderHistoryFile.files.length) {
    formData.append('order_history', orderHistoryFile.files[0]);
  }
  if (taxInvoiceFile.files.length) {
    formData.append('tax_invoice', taxInvoiceFile.files[0]);
  }
  if (delimiterInput.value) {
    formData.append('delimiter', delimiterInput.value);
  }
  
  try {
    progressText.textContent = '⏳ Uploading and processing files...';
    progressFill.style.width = '30%';
    
    const response = await fetch('index.php', {
      method: 'POST',
      body: formData
    });
    
    progressFill.style.width = '70%';
    progressText.textContent = '⏳ Finalizing...';
    
    if (!response.ok) {
      const errorText = await response.text();
      throw new Error('Server error: ' + response.status + ' - ' + errorText.substring(0, 200));
    }
    
    const result = await response.json();
    progressFill.style.width = '100%';
    progressText.textContent = '✅ Complete!';
    
    setTimeout(() => {
      progressSection.style.display = 'none';
      showResults(result);
      uploadBtn.disabled = false;
    }, 500);
    
  } catch (error) {
    progressSection.style.display = 'none';
    showAlert('❌ Error: ' + error.message, 'error');
    uploadBtn.disabled = false;
  }
});

// ============================================================
// UI Helpers
// ============================================================
function showAlert(message, type) {
  statusContainer.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
}

function showResults(result) {
  if (result.status === 'success') {
    let html = '<div class="alert alert-success">✅ Upload completed successfully!</div><div style="font-size:13px;line-height:1.8;">';
    
    if (result.order_history) {
      if (result.order_history.skipped) {
        html += `<p><strong>Order History:</strong> ⏭️ Skipped - ${result.order_history.message || 'Already imported'}</p>`;
      } else {
        html += `<p><strong>Order History:</strong> ✅ ${result.order_history.success || 0} rows imported`;
        if (result.order_history.failed) {
          html += `, ⚠️ ${result.order_history.failed} failed`;
        }
        html += `</p>`;
      }
    }
    
    if (result.tax_invoice) {
      if (result.tax_invoice.skipped) {
        html += `<p><strong>Tax Invoice:</strong> ⏭️ Skipped - ${result.tax_invoice.message || 'Already imported'}</p>`;
      } else {
        html += `<p><strong>Tax Invoice:</strong> ✅ ${result.tax_invoice.success || 0} rows imported`;
        if (result.tax_invoice.failed) {
          html += `, ⚠️ ${result.tax_invoice.failed} failed`;
        }
        html += `</p>`;
      }
    }
    
    html += '</div>';
    resultsContent.innerHTML = html;
    
    // Clear file inputs
    orderHistoryFile.value = '';
    taxInvoiceFile.value = '';
    orderHistoryPreview.innerHTML = '';
    taxInvoicePreview.innerHTML = '';
    updateUploadBtn();
    
  } else {
    let errors = result.message || 'Upload failed';
    if (result.order_history && result.order_history.error) {
      errors += `<br><strong>Order History:</strong> ${result.order_history.error}`;
    }
    if (result.tax_invoice && result.tax_invoice.error) {
      errors += `<br><strong>Tax Invoice:</strong> ${result.tax_invoice.error}`;
    }
    resultsContent.innerHTML = `<div class="alert alert-error">❌ ${errors}</div>`;
  }
  
  resultsCard.style.display = 'block';
}

// ============================================================
// Sidebar Functions
// ============================================================
function toggleSidebarOnDesktop() {
  if (window.innerWidth >= 900) {
    const collapsed = document.body.classList.toggle('sidebar-collapsed');
    try {
      if (collapsed) localStorage.setItem('adminSidebarCollapsed', '1');
      else localStorage.removeItem('adminSidebarCollapsed');
    } catch (e) {}
  } else {
    openDrawer();
  }
}

function openDrawer() {
  document.getElementById('sidebarDrawer').classList.add('open');
  document.getElementById('drawerOverlay').classList.add('open');
}

function closeDrawer() {
  document.getElementById('sidebarDrawer').classList.remove('open');
  document.getElementById('drawerOverlay').classList.remove('open');
}

// Handle window resize for sidebar
window.addEventListener('resize', function() {
  if (window.innerWidth < 900) {
    document.body.classList.remove('sidebar-collapsed');
  } else {
    try {
      if (localStorage.getItem('adminSidebarCollapsed') === '1') {
        document.body.classList.add('sidebar-collapsed');
      }
    } catch (e) {}
  }
});

// Initial state
updateUploadBtn();
</script>

</body>
</html>