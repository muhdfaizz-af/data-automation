<?php
/**
 * Import Order History and Tax Invoice files.
 * Simplified version - fixed header detection.
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '2048M');
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Not authenticated']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid request method']));
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function normalizeHeader($header) {
    if ($header === null) return '';
    $header = trim((string)$header);
    $header = str_replace("\xEF\xBB\xBF", '', $header);
    $header = strtolower($header);
    $header = preg_replace('/[^a-z0-9]/', '', $header);
    return $header;
}

function getRowValue($row, $headerMap, $keys) {
    foreach ($keys as $key) {
        $normalized = normalizeHeader($key);
        if (isset($headerMap[$normalized])) {
            $index = $headerMap[$normalized];
            return isset($row[$index]) ? $row[$index] : '';
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
    
    $formats = ['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'];
    foreach ($formats as $format) {
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

// ============================================================
// COMPANY DETECTION
// ============================================================

function getCompany($orderId, PDO $pdo) {
    $orderId = strtoupper(trim($orderId));
    
    // Detect prefix from order ID
    $prefix = 'MYHQ'; // default
    if (strpos($orderId, 'MYHQ') === 0) $prefix = 'MYHQ';
    elseif (strpos($orderId, 'MYBT') === 0 || strpos($orderId, 'MYBTCSB') === 0) $prefix = 'MYBT';
    elseif (strpos($orderId, 'SGHQ') === 0 || strpos($orderId, 'SG') === 0) $prefix = 'SGHQ';
    elseif (strpos($orderId, 'BN') === 0) $prefix = 'BNHQ';
    
    // Map prefix to company_code
    $map = [
        'MYHQ' => ['code' => 'MY', 'currency' => 'MYR'],
        'MYBT' => ['code' => 'MY', 'currency' => 'MYR'],
        'SGHQ' => ['code' => 'SG', 'currency' => 'SGD'],
        'BNHQ' => ['code' => 'BN', 'currency' => 'BND']
    ];
    
    $companyCode = $map[$prefix]['code'] ?? 'MY';
    $currency = $map[$prefix]['currency'] ?? 'MYR';
    
    // Get or create company
    $stmt = $pdo->prepare('SELECT id, invoice_prefix FROM companies WHERE company_code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$companyCode]);
    $result = $stmt->fetch();
    
    if (!$result) {
        $insert = $pdo->prepare('INSERT INTO companies (company_code, company_name, currency_code, invoice_prefix) VALUES (?, ?, ?, ?)');
        $insert->execute([$companyCode, $companyCode == 'MY' ? 'Malaysia' : 'Singapore', $currency, $prefix]);
        $companyId = (int)$pdo->lastInsertId();
        $invoicePrefixes = $prefix;
    } else {
        $companyId = (int)$result['id'];
        $invoicePrefixes = $result['invoice_prefix'];
        
        // Add new prefix if not exists
        $prefixes = array_map('trim', explode(',', $invoicePrefixes));
        if (!in_array($prefix, $prefixes)) {
            $prefixes[] = $prefix;
            $update = $pdo->prepare('UPDATE companies SET invoice_prefix = ? WHERE id = ?');
            $update->execute([implode(',', $prefixes), $companyId]);
        }
    }
    
    return [
        'company_id' => $companyId,
        'company_code' => $companyCode,
        'currency_code' => $currency,
        'invoice_prefix' => $prefix
    ];
}

// ============================================================
// LOAD FILE
// ============================================================

function loadFile($filePath, $originalName, $delimiter) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'])) {
        throw new RuntimeException('Only .xlsx and .csv files are supported.');
    }
    
    if ($ext === 'csv') {
        $reader = new Csv();
        $reader->setDelimiter($delimiter ?: ',');
    } else {
        $reader = new Xlsx();
    }
    
    $rows = $reader->load($filePath)->getActiveSheet()->toArray(null, true, true, false);
    if (count($rows) < 2) throw new RuntimeException('File is empty.');
    return $rows;
}

// ============================================================
// PROCESS FILE
// ============================================================

function processFile($filePath, $originalName, PDO $pdo, $delimiter) {
    $hash = hash_file('sha256', $filePath);
    
    // Check duplicate
    $stmt = $pdo->prepare('SELECT id, status FROM import_batches WHERE file_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $existing = $stmt->fetch();
    if ($existing && $existing['status'] === 'completed') {
        return ['success' => 0, 'skipped' => true, 'message' => 'File already imported.'];
    }
    if ($existing) {
        $pdo->prepare('UPDATE import_batches SET file_hash = NULL WHERE id = ?')->execute([$existing['id']]);
    }
    
    $rows = loadFile($filePath, $originalName, $delimiter);
    
    // Build header map
    $headerMap = [];
    $rawHeaders = $rows[0];
    foreach ($rawHeaders as $i => $header) {
        $headerMap[normalizeHeader($header)] = $i;
    }
    
    // DEBUG: Log headers
    error_log("Headers: " . json_encode($rawHeaders));
    error_log("Normalized: " . json_encode(array_keys($headerMap)));
    
    // ============================================================
    // FIX: Better file type detection
    // ============================================================
    $hasOrderId = isset($headerMap['orderid']);
    $hasDateTime = isset($headerMap['datetime']) || isset($headerMap['orderdatetime']) || isset($headerMap['dateandtime']);
    $hasCommission = isset($headerMap['commissionmonth']);
    $hasItemCode = isset($headerMap['itemcode']) || isset($headerMap['sku']) || isset($headerMap['itemno']);
    
    $isOrderHistory = $hasOrderId && $hasDateTime;
    $isTaxInvoice = $hasCommission && $hasItemCode;
    
    // If still can't detect, check for Order History specific columns
    if (!$isOrderHistory && !$isTaxInvoice) {
        // Check for Order History columns
        $orderHistoryCols = ['memberid', 'membertype', 'membername', 'subtotal', 'ordertotal'];
        $hasOrderCols = true;
        foreach ($orderHistoryCols as $col) {
            if (!isset($headerMap[$col])) {
                $hasOrderCols = false;
                break;
            }
        }
        if ($hasOrderCols && $hasOrderId) {
            $isOrderHistory = true;
        }
        
        // Check for Tax Invoice columns
        $taxCols = ['itemdescription', 'qty', 'invoiceamount'];
        $hasTaxCols = true;
        foreach ($taxCols as $col) {
            if (!isset($headerMap[$col])) {
                $hasTaxCols = false;
                break;
            }
        }
        if ($hasTaxCols && $hasCommission && $hasItemCode) {
            $isTaxInvoice = true;
        }
    }
    
    if (!$isOrderHistory && !$isTaxInvoice) {
        throw new RuntimeException('Unknown file format. Headers: ' . implode(', ', $rawHeaders));
    }
    
    // Get company from first order
    $firstOrderId = '';
    foreach (array_slice($rows, 1) as $row) {
        $firstOrderId = trim(getRowValue($row, $headerMap, ['orderid', 'orderno']));
        if ($firstOrderId) break;
    }
    
    $company = getCompany($firstOrderId, $pdo);
    
    // Create batch
    $stmt = $pdo->prepare('INSERT INTO import_batches (company_id, file_type, original_filename, file_hash, total_rows, status) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$company['company_id'], $isOrderHistory ? 'ORDER_HISTORY' : 'TAX_INVOICE', $originalName, $hash, count($rows) - 1, 'processing']);
    $batchId = (int)$pdo->lastInsertId();
    
    $success = 0;
    $failed = 0;
    
    try {
        $pdo->beginTransaction();
        
        // Order insert
        $orderStmt = $pdo->prepare("
            INSERT INTO orders (
                company_id, import_batch_id, order_id, order_datetime, member_code, member_type,
                member_name, remark, delivery_method, mobile_no, order_type, sub_total, shipping_fee,
                voucher_discount, discount, convenience_fee, order_total, gst, total_bv, total_pv,
                total_tp, payment_mode, order_status, delivery_status, payment_gateway,
                payment_gateway_id, currency_code, invoice_prefix
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                import_batch_id = VALUES(import_batch_id),
                order_datetime = VALUES(order_datetime),
                member_code = VALUES(member_code),
                member_type = VALUES(member_type),
                member_name = VALUES(member_name),
                remark = VALUES(remark),
                delivery_method = VALUES(delivery_method),
                mobile_no = VALUES(mobile_no),
                order_type = VALUES(order_type),
                sub_total = VALUES(sub_total),
                shipping_fee = VALUES(shipping_fee),
                voucher_discount = VALUES(voucher_discount),
                discount = VALUES(discount),
                convenience_fee = VALUES(convenience_fee),
                order_total = VALUES(order_total),
                gst = VALUES(gst),
                total_bv = VALUES(total_bv),
                total_pv = VALUES(total_pv),
                total_tp = VALUES(total_tp),
                payment_mode = VALUES(payment_mode),
                order_status = VALUES(order_status),
                delivery_status = VALUES(delivery_status),
                payment_gateway = VALUES(payment_gateway),
                payment_gateway_id = VALUES(payment_gateway_id),
                currency_code = VALUES(currency_code),
                invoice_prefix = VALUES(invoice_prefix)
        ");
        
        // Item insert
        $itemStmt = $pdo->prepare('
            INSERT INTO order_items (
                order_id, commission_month, cdo, cdo_created_date, product_type,
                item_code, item_description, brand, email, total_weight, qty,
                bv, pv, total_bv, total_pv, total_retail_price, discount,
                invoice_amount, total_invoice_amount_paid, order_processed_location
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $orderLookup = $pdo->prepare('SELECT id FROM orders WHERE company_id = ? AND order_id = ? LIMIT 1');
        $deleteItems = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');
        $replacedOrders = [];
        
        foreach (array_slice($rows, 1) as $rowNum => $row) {
            // Skip empty
            $hasData = false;
            foreach ($row as $val) {
                if (trim((string)$val) !== '') { $hasData = true; break; }
            }
            if (!$hasData) continue;
            
            $orderId = trim(getRowValue($row, $headerMap, ['orderid', 'orderno']));
            if (!$orderId) { $failed++; continue; }
            
            $comp = getCompany($orderId, $pdo);
            if (!$comp) { $failed++; continue; }
            
            if ($isOrderHistory) {
                // Try multiple date column names
                $datetime = parseDate(getRowValue($row, $headerMap, ['dateandtime', 'orderdatetime', 'datetime', 'orderdate']));
                if (!$datetime) { 
                    $failed++; 
                    error_log("Failed to parse date for order: " . $orderId);
                    continue; 
                }
                
                $orderStmt->execute([
                    $comp['company_id'], $batchId, $orderId, $datetime,
                    getRowValue($row, $headerMap, ['memberid']),
                    getRowValue($row, $headerMap, ['membertype']),
                    getRowValue($row, $headerMap, ['membername']),
                    getRowValue($row, $headerMap, ['remark']),
                    getRowValue($row, $headerMap, ['deliverymethod']),
                    getRowValue($row, $headerMap, ['mobileno']),
                    getRowValue($row, $headerMap, ['ordertype']),
                    parseAmount(getRowValue($row, $headerMap, ['subtotal'])),
                    parseAmount(getRowValue($row, $headerMap, ['shippingfee'])),
                    parseAmount(getRowValue($row, $headerMap, ['voucherdiscount'])),
                    parseAmount(getRowValue($row, $headerMap, ['discount'])),
                    parseAmount(getRowValue($row, $headerMap, ['conveniencefee'])),
                    parseAmount(getRowValue($row, $headerMap, ['ordertotal'])),
                    parseAmount(getRowValue($row, $headerMap, ['gst'])),
                    parseAmount(getRowValue($row, $headerMap, ['totalbv'])),
                    parseAmount(getRowValue($row, $headerMap, ['totalpv'])),
                    parseAmount(getRowValue($row, $headerMap, ['totaltp'])),
                    getRowValue($row, $headerMap, ['paymentmode']),
                    getRowValue($row, $headerMap, ['orderstatus']),
                    getRowValue($row, $headerMap, ['deliverystatus']),
                    getRowValue($row, $headerMap, ['paymentgateway']),
                    getRowValue($row, $headerMap, ['paymentgatewayid']),
                    $comp['currency_code'],
                    $comp['invoice_prefix']
                ]);
                $success++;
                continue;
            }
            
            // Tax Invoice
            $orderLookup->execute([$comp['company_id'], $orderId]);
            $orderDbId = $orderLookup->fetchColumn();
            if (!$orderDbId) { 
                $failed++; 
                error_log("Order not found: " . $orderId);
                continue; 
            }
            
            if (!isset($replacedOrders[$orderDbId])) {
                $deleteItems->execute([$orderDbId]);
                $replacedOrders[$orderDbId] = true;
            }
            
            $itemCode = trim(getRowValue($row, $headerMap, ['itemcode', 'sku', 'itemno']));
            if (!$itemCode) { $failed++; continue; }
            
            $itemStmt->execute([
                $orderDbId,
                getRowValue($row, $headerMap, ['commissionmonth']),
                getRowValue($row, $headerMap, ['cdo']),
                parseDate(getRowValue($row, $headerMap, ['cdocreateddate'])),
                getRowValue($row, $headerMap, ['producttype']),
                $itemCode,
                getRowValue($row, $headerMap, ['itemdescription']),
                getRowValue($row, $headerMap, ['brand']),
                getRowValue($row, $headerMap, ['email']),
                parseAmount(getRowValue($row, $headerMap, ['totalweight'])),
                (int)parseAmount(getRowValue($row, $headerMap, ['qty'])),
                parseAmount(getRowValue($row, $headerMap, ['bv'])),
                parseAmount(getRowValue($row, $headerMap, ['pv'])),
                parseAmount(getRowValue($row, $headerMap, ['totalbv'])),
                parseAmount(getRowValue($row, $headerMap, ['totalpv'])),
                parseAmount(getRowValue($row, $headerMap, ['totalretailprice'])),
                parseAmount(getRowValue($row, $headerMap, ['discount'])),
                parseAmount(getRowValue($row, $headerMap, ['invoiceamount'])),
                parseAmount(getRowValue($row, $headerMap, ['totalinvoiceamountpaid'])),
                getRowValue($row, $headerMap, ['orderprocessedlocation'])
            ]);
            $success++;
        }
        
        $pdo->prepare('UPDATE import_batches SET successful_rows = ?, failed_rows = ?, status = ?, imported_at = NOW() WHERE id = ?')
            ->execute([$success, $failed, $failed ? 'completed_with_errors' : 'completed', $batchId]);
        $pdo->commit();
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare('UPDATE import_batches SET status = "failed", error_message = ? WHERE id = ?')->execute([$e->getMessage(), $batchId]);
        throw $e;
    }
    
    return ['success' => $success, 'failed' => $failed];
}

// ============================================================
// MAIN
// ============================================================

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $delimiter = substr($_POST['delimiter'] ?? ',', 0, 1) ?: ',';
    $results = ['status' => 'success'];
    
    foreach (['order_history', 'tax_invoice'] as $type) {
        if (!isset($_FILES[$type]) || $_FILES[$type]['error'] === UPLOAD_ERR_NO_FILE) continue;
        if ($_FILES[$type]['error'] !== UPLOAD_ERR_OK) {
            $results[$type] = ['error' => 'Upload failed'];
            $results['status'] = 'error';
            continue;
        }
        try {
            $results[$type] = processFile($_FILES[$type]['tmp_name'], $_FILES[$type]['name'], $pdo, $delimiter);
        } catch (Throwable $e) {
            $results[$type] = ['error' => $e->getMessage()];
            $results['status'] = 'error';
        }
    }
    
    echo json_encode($results);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}