<?php
/**
 * Admin Dashboard — Redesigned
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config/db.php';

// ── CSRF Protection ──
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ── Login Attempt Throttle ──
function tooManyLoginAttempts() {
    $maxAttempts = 5;
    $timeWindow = 900; // 15 minit
    $key = 'login_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $attempts = $_SESSION[$key] ?? [];
    $attempts = array_filter($attempts, fn($t) => time() - $t < $timeWindow);
    return count($attempts) >= $maxAttempts;
}

function recordFailedLoginAttempt() {
    $key = 'login_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if (!isset($_SESSION[$key])) $_SESSION[$key] = [];
    $_SESSION[$key][] = time();
}

function clearLoginAttempts() {
    $key = 'login_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    unset($_SESSION[$key]);
}

function getDBConnection() {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
        return new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (Exception $e) { return null; }
}

$isLoggedIn    = isset($_SESSION['admin_id']);
$adminUsername = $_SESSION['admin_username'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {

    // ── CSRF check ──
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Sila cuba lagi.';
    }
    // ── Brute-force throttle ──
    elseif (tooManyLoginAttempts()) {
        $error = 'Terlalu banyak cubaan gagal. Sila cuba lagi dalam beberapa minit.';
    }
    else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required.';
        } else {
            $pdo = getDBConnection();
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare('SELECT id, username, password FROM admin_users WHERE username = ?');
                    $stmt->execute([$username]);
                    $admin = $stmt->fetch();

                    if ($admin && password_verify($password, $admin['password'])) {
                        // ── Regenerate session ID lepas login berjaya (elak session fixation) ──
                        session_regenerate_id(true);

                        $_SESSION['admin_id']       = $admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['login_time']     = time();
                        $_SESSION['last_activity']  = time();

                        clearLoginAttempts();
                        unset($_SESSION['csrf_token']); // rotate token

                        header('Location: ' . $_SERVER['PHP_SELF']); exit;
                    } else {
                        recordFailedLoginAttempt();
                        $error = 'Invalid username or password.';
                    }
                } catch (Exception $e) { $error = 'Database error occurred.'; }
            } else { $error = 'Database connection failed.'; }
        }
    }
}

function getOverallProductSales(PDO $pdo, $from, $to, $rate) {
    $sql = "SELECT o.id AS database_order_id, UPPER(TRIM(COALESCE(oi.brand, ''))) AS brand,
                UPPER(TRIM(COALESCE(oi.item_code, ''))) AS item_code,
                SUM(COALESCE(oi.qty, 0)) AS quantity,
                SUM(COALESCE(oi.invoice_amount, 0)) AS invoice_sales,
                c.company_code
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN companies c ON c.id = o.company_id
            WHERE o.order_datetime >= :from AND o.order_datetime < :to
              AND o.order_status = 'confirmed' AND c.company_code IN ('MY', 'SG')
              AND UPPER(TRIM(COALESCE(oi.brand, ''))) IN ('CHOCO ALBAB', 'NAFESA', 'ZEKY', 'STK')
            GROUP BY o.id, UPPER(TRIM(COALESCE(oi.brand, ''))),
                UPPER(TRIM(COALESCE(oi.item_code, ''))), c.company_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['from' => $from . ' 00:00:00', 'to' => date('Y-m-d 00:00:00', strtotime($to . ' +1 day'))]);
    $rows = $stmt->fetchAll();
    $joyOrders = [];
    foreach ($rows as $row) {
        if ($row['item_code'] === 'JOY-BUNDLE-1') $joyOrders[(int)$row['database_order_id']] = true;
    }

    $total = 0.0;
    foreach ($rows as $row) {
        $orderId = (int)$row['database_order_id'];
        if ($row['item_code'] === 'JOY-BUNDLE-1') continue;
        if ($row['item_code'] === 'BCD-002' && isset($joyOrders[$orderId])) {
            $total += (float)$row['quantity'] * 37.00;
        } else {
            $total += (float)$row['invoice_sales'] * ($row['company_code'] === 'SG' ? $rate : 1);
        }
    }
    return round($total, 2);
}

function dashboardProductName($description) {
    $description = trim($description);
    $description = preg_replace('/\s*\((?:NORMAL|COMPOSITE)\)\s*/i', ' ', $description);
    $description = preg_replace('/^\s*\(PREORDER\)\s*/i', '', $description);
    $description = preg_replace('/\s*\(FULFILMENT[^)]*\)\s*/i', ' ', $description);
    return trim((string)preg_replace('/\s+/', ' ', $description));
}

function dashboardProductCategory($brand, $itemCode, $description, $productType) {
    $brand = strtoupper(trim($brand));
    $itemCode = strtoupper(trim($itemCode));
    $description = strtoupper(trim($description));
    $productType = strtoupper(trim($productType));
    foreach (['PAPERBAG', 'PAPER BAG', 'BUNTING', 'BROCHURE', 'FLYER', 'VOUCHER', 'DISPLAY', 'MINI RAK', 'MINI RACK', 'TABLE CLOTH', 'APRON', 'PLASTIC CUP', 'TUMBLER', 'WOVEN BAG', 'MENU BOARD', ' BOARD '] as $term) {
        if (str_contains($description, $term)) return null;
    }
    if ($itemCode === 'BPCC-001' || str_contains($description, 'JOY CUP 12 OZ DOME SET')) return 'JOY CUP 12 OZ DOME SET (100PCS)';
    if ($itemCode === 'BPCC-002' || str_contains($description, 'JOY CUP 12 OZ SIPPY SET')) return 'JOY CUP 12 OZ SIPPY SET (100PCS)';
    if ($itemCode === 'BPCC-003' || str_contains($description, 'JOY CUP 16 OZ DOME SET')) return 'JOY CUP 16 OZ DOME SET (100PCS)';
    if (preg_match('/^(?:BCD-002|BCDC-002|CBCDA-002|STK-BCDS-002|JOY-BUNDLE-1|Q-BCD-002)(?:-|$)/', $itemCode) || str_contains($description, 'BELGIAN CHOCOLATE DRINK')) return '(BCDB) BOX BELGIAN CHOCOLATE DRINK';
    if (in_array($itemCode, ['CA-6', 'CA-006'], true) || str_starts_with($itemCode, 'CAC-011') || str_starts_with($itemCode, 'STK-CA-6') || str_contains($description, 'UNICORN STRAWBERRY')) return 'UNICORN STRAWBERRY CHOCOLATE TUB';
    if (str_contains($description, 'CUTIE MINI CHOCO CRUNCH')) return 'CUTIE MINI CHOCO CRUNCH TUB';
    if (str_contains($description, 'BUTTERCREAM LATTE')) return 'BUTTERCREAM LATTE DRINK';
    if (str_contains($description, 'CUTIE CHOCO BALL')) return 'CUTIE CHOCO BALL TUB';
    if (str_contains($description, 'CUTIE MINI CHOCO DORAYAKI')) return 'CUTIE MINI CHOCO DORAYAKI TUB';
    if (str_contains($description, 'CUTIE CHOCO RICE')) return 'CUTIE CHOCO RICE TUB';
    if (str_contains($description, 'PISTACHIO DREAM')) return 'PISTACHIO DREAM TUB';
    if (str_contains($description, 'COTTON CANDY CHOCOLATE')) return 'COTTON CANDY CHOCOLATE TUB';
    if (preg_match('/^(?:BRC|BRCC|STK-BRC)/', $itemCode) || str_contains($description, 'BRAZILIAN COFFEE')) return 'BRAZILIAN COFFEE DRINK';
    if (preg_match('/^(?:BMD|BMDC)/', $itemCode) || str_contains($description, 'BELGIAN MOCHA')) return 'BELGIAN MOCHA DRINK';
    if (preg_match('/^(?:BBC|BBCC)/', $itemCode) || str_contains($description, 'BLUEBERRY CHOCOLATE')) return 'BLUEBERRY CHOCOLATE DRINK';
    if (str_starts_with($itemCode, 'ZEKY-BH') || str_starts_with($itemCode, 'STK-ZEKY-BH') || str_contains($description, 'ZEKY BRAIN HERO')) return 'ZEKY BRAIN HERO';
    if ($brand === 'STK' && preg_match('/^STK-N(?!F(?:-|$))/', $itemCode)) return 'SCARF';
    if ($brand === 'NAFESA') return preg_match('/^(?:NCH|NTU|NST|NIN)/', $itemCode) || str_contains($description, 'INNER') ? 'INNER' : 'SCARF';
    if ($brand === 'CHOCO ALBAB') return in_array($productType, ['NORMAL', 'COMPOSITE'], true) ? dashboardProductName($description) : null;
    return null;
}

function dashboardQuantityRow($category, $itemCode, $productType, $description) {
    $itemCode = strtoupper(trim($itemCode));
    $productType = strtoupper(trim($productType));
    $search = $itemCode . ' ' . strtoupper(trim($description));
    if ($category === 'SCARF') {
        foreach (['CHARM', 'BRACELET', 'KEYCHAIN', 'ENAMEL PIN', 'HANDSOCK', 'HAND SOCK', 'STK-NMJ04-MR', 'STK-NMJ02-BG', 'STK-NMJ06-RC', 'STK-NRQ01-EM', 'STK-NRW01-EM'] as $term) {
            if (str_contains($search, $term)) return false;
        }
    }
    $codes = ['(BCDB) BOX BELGIAN CHOCOLATE DRINK' => 'BCD-002', 'UNICORN STRAWBERRY CHOCOLATE TUB' => 'CA-6', 'CUTIE MINI CHOCO CRUNCH TUB' => 'CA-9', 'CUTIE CHOCO BALL TUB' => 'CA-8', 'CUTIE MINI CHOCO DORAYAKI TUB' => 'CA-13', 'CUTIE CHOCO RICE TUB' => 'CA-12', 'PISTACHIO DREAM TUB' => 'CA-15', 'COTTON CANDY CHOCOLATE TUB' => 'CA-10', 'ZEKY BRAIN HERO' => 'ZEKY-BH', 'BRAZILIAN COFFEE DRINK' => 'BRC-001'];
    if (isset($codes[$category])) return $itemCode === $codes[$category];
    if ($category === 'SCARF') return $productType === 'NORMAL' || (bool)preg_match('/^STK-N(?!F(?:-|$))/', $itemCode);
    return $productType === 'NORMAL';
}

function getDashboardTopProducts(PDO $pdo, $from, $to, $rate) {
    $stmt = $pdo->prepare("SELECT oi.order_id, UPPER(TRIM(COALESCE(oi.brand, ''))) AS brand, oi.product_type, oi.item_code, oi.item_description, c.company_code, SUM(COALESCE(oi.qty, 0)) AS quantity, SUM(COALESCE(oi.invoice_amount, 0)) AS invoice_sales FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id INNER JOIN companies c ON c.id = o.company_id WHERE o.order_datetime >= :from AND o.order_datetime < :to AND o.order_status = 'confirmed' AND c.company_code IN ('MY', 'SG') AND UPPER(TRIM(COALESCE(oi.brand, ''))) IN ('CHOCO ALBAB', 'NAFESA', 'ZEKY', 'STK') GROUP BY oi.order_id, UPPER(TRIM(COALESCE(oi.brand, ''))), oi.product_type, oi.item_code, oi.item_description, c.company_code");
    $stmt->execute(['from' => $from . ' 00:00:00', 'to' => date('Y-m-d 00:00:00', strtotime($to . ' +1 day'))]);
    $rows = $stmt->fetchAll();
    $joyOrders = [];
    $joyCupQuantities = [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string)$row['item_code']));
        $orderId = (int)$row['order_id'];
        if ($code === 'JOY-BUNDLE-1') $joyOrders[$orderId] = true;
        if (in_array($code, ['BPC-001', 'BPC-002'], true)) $joyCupQuantities[$orderId][$code] = ($joyCupQuantities[$orderId][$code] ?? 0) + (int)$row['quantity'];
    }
    $products = [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string)$row['item_code']));
        if ($code === 'JOY-BUNDLE-1') continue;
        $category = dashboardProductCategory($row['brand'], $code, $row['item_description'] ?? '', $row['product_type'] ?? '');
        if ($category === null) continue;
        $sales = (float)$row['invoice_sales'];
        if ($code === 'BCD-002' && isset($joyOrders[(int)$row['order_id']])) $sales = (float)$row['quantity'] * 37.00;
        elseif ($row['company_code'] === 'SG') $sales *= $rate;
        $description = strtoupper((string)($row['item_description'] ?? ''));
        $allocations = str_contains($description, '30 MCC & 30 BALL') ? ['CUTIE MINI CHOCO CRUNCH TUB' => 0.5, 'CUTIE CHOCO BALL TUB' => 0.5] : (str_contains($description, '30 RICE & 30 DORAYAKI') ? ['CUTIE CHOCO RICE TUB' => 0.5, 'CUTIE MINI CHOCO DORAYAKI TUB' => 0.5] : [$category => 1.0]);
        foreach ($allocations as $allocatedCategory => $share) {
            if (!isset($products[$allocatedCategory])) $products[$allocatedCategory] = ['product' => $allocatedCategory, 'quantity' => 0, 'total' => 0.0];
            $products[$allocatedCategory]['total'] += $sales * $share;
            if ($share === 1.0 && dashboardQuantityRow($allocatedCategory, $code, $row['product_type'] ?? '', $row['item_description'] ?? '')) $products[$allocatedCategory]['quantity'] += (int)$row['quantity'];
            $cupSource = ['BPCC-001' => 'BPC-001', 'BPCC-002' => 'BPC-001', 'BPCC-003' => 'BPC-002'][$code] ?? null;
            if ($share === 1.0 && $cupSource !== null && isset($joyCupQuantities[(int)$row['order_id']][$cupSource])) $products[$allocatedCategory]['quantity'] += $joyCupQuantities[(int)$row['order_id']][$cupSource];
        }
    }
    $products = array_values(array_filter($products, static fn($product) => $product['total'] > 0));
    usort($products, static fn($a, $b) => $b['total'] <=> $a['total'] ?: strcasecmp($a['product'], $b['product']));
    foreach ($products as &$product) $product['total'] = round($product['total'], 2);
    unset($product);
    return array_slice($products, 0, 5);
}

function getDashboardData($pdo, $requestedDate = null) {
    $defaultDate = date('Y-m-d', strtotime('-1 day'));
    $reportDate = is_string($requestedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)
        ? $requestedDate : $defaultDate;
    if ($reportDate > date('Y-m-d')) $reportDate = $defaultDate;
    $monthStart = date('Y-m-01', strtotime($reportDate));
    $yearStart = date('Y-01-01', strtotime($reportDate));
    $trendStart = date('Y-m-d', strtotime($reportDate . ' -6 days'));
    $data = [
        'report_date' => $reportDate, 'total' => 0, 'mtd' => 0, 'ytd' => 0,
        'previous_total' => 0, 'target' => 0, 'monthly_target' => 0, 'active_agents' => 0,
        'new_agent_mtd' => 0, 'new_agent_mtd_prev' => 0,
        'active_agents_prev' => 0, 'mtd_prev' => 0, 'ytd_prev' => 0, 'asd' => 0, 'asd_prev' => 0,
        'trend' => [], 'trend_prev_total' => 0,
        'hubs' => [], 'hubs_mtd' => [], 'brands' => [], 'brands_mtd' => [], 'products' => [],
    ];
    if (!$pdo) return $data;

    $rate = 3.27;
    $dateToExclusive = function ($date) { return date('Y-m-d 00:00:00', strtotime($date . ' +1 day')); };
    $sumOrders = function ($from, $to) use ($pdo, $rate, $dateToExclusive) {
        $sql = "SELECT COALESCE(SUM(CASE WHEN c.company_code = 'SG' THEN o.sub_total * :rate ELSE o.sub_total END), 0)
                FROM orders o JOIN companies c ON c.id = o.company_id
                WHERE o.order_datetime >= :from AND o.order_datetime < :to AND o.order_status = 'Confirmed'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['rate' => $rate, 'from' => $from . ' 00:00:00', 'to' => $dateToExclusive($to)]);
        $total = (float)$stmt->fetchColumn();
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN c.company_code = 'SG' THEN ms.amount * :rate ELSE ms.amount END), 0)
                FROM manual_sales ms JOIN companies c ON c.id = ms.company_id WHERE ms.sales_date BETWEEN :from AND :to");
            $stmt->execute(['rate' => $rate, 'from' => $from, 'to' => $to]);
            $total += (float)$stmt->fetchColumn();
        } catch (Exception $e) {}
        return round($total, 2);
    };
    $countAgents = function ($from, $to) use ($pdo, $dateToExclusive) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT o.member_code) FROM orders o
            WHERE o.order_datetime >= :from AND o.order_datetime < :to AND o.order_status = 'Confirmed'
            AND o.member_code IS NOT NULL AND o.member_code <> ''");
        $stmt->execute(['from' => $from . ' 00:00:00', 'to' => $dateToExclusive($to)]);
        return (int)$stmt->fetchColumn();
    };
    $getAsdMetrics = function ($from, $to) use ($pdo, $rate, $dateToExclusive) {
        $stmt = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN c.company_code = 'SG' THEN o.sub_total * :rate ELSE o.sub_total END), 0) AS total_sales,
                COUNT(DISTINCT CASE WHEN o.member_code IS NOT NULL AND TRIM(o.member_code) <> '' THEN TRIM(o.member_code) ELSE NULL END) AS active_agents
            FROM orders o
            INNER JOIN companies c ON c.id = o.company_id
            WHERE o.order_datetime >= :from AND o.order_datetime < :to
              AND o.order_status = 'Confirmed'
              AND o.order_type IN ('Repurchase Order', 'On Behalf Repurchase Order')
              AND UPPER(TRIM(COALESCE(o.member_type, ''))) = 'DISTRIBUTOR'");
        $stmt->execute(['rate' => $rate, 'from' => $from . ' 00:00:00', 'to' => $dateToExclusive($to)]);
        $metrics = $stmt->fetch();
        $totalSales = round((float)($metrics['total_sales'] ?? 0), 2);
        $activeAgents = (int)($metrics['active_agents'] ?? 0);
        return [
            'total_sales' => $totalSales,
            'active_agents' => $activeAgents,
            'asd' => $activeAgents > 0 ? round($totalSales / $activeAgents, 2) : 0,
        ];
    };
    $getNewAgentMetrics = function ($from, $to) use ($pdo, $dateToExclusive) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(o.id, ':', CASE
            WHEN UPPER(TRIM(oi.item_code)) = 'STK-ZEKY' OR UPPER(TRIM(oi.item_code)) LIKE 'STK-ZEKY-%' THEN 'STK-ZEKY'
            WHEN UPPER(TRIM(oi.item_code)) = 'STK-CA' OR UPPER(TRIM(oi.item_code)) LIKE 'STK-CA-%' THEN 'STK-CA'
            WHEN UPPER(TRIM(oi.item_code)) = 'STK-NF' OR UPPER(TRIM(oi.item_code)) LIKE 'STK-NF-%' THEN 'STK-NF'
            END))
            FROM orders o
            INNER JOIN companies c ON c.id = o.company_id
            INNER JOIN order_items oi ON oi.order_id = o.id
            WHERE o.order_datetime >= :from AND o.order_datetime < :to
              AND o.order_status = 'Confirmed'
              AND o.order_type IN ('Registration Order', 'On Behalf Register Order', 'SPC Upgrade Order')
              AND c.company_code IN ('MY', 'SG')
              AND (
                  UPPER(TRIM(oi.item_code)) = 'STK-ZEKY' OR UPPER(TRIM(oi.item_code)) LIKE 'STK-ZEKY-%'
                  OR UPPER(TRIM(oi.item_code)) = 'STK-CA' OR UPPER(TRIM(oi.item_code)) LIKE 'STK-CA-%'
                  OR UPPER(TRIM(oi.item_code)) = 'STK-NF' OR UPPER(TRIM(oi.item_code)) LIKE 'STK-NF-%'
              )");
        $stmt->execute(['from' => $from . ' 00:00:00', 'to' => $dateToExclusive($to)]);
        return (int)$stmt->fetchColumn();
    };
    $hubExpr = "CASE WHEN c.company_code = 'SG' THEN 'Singapore'
        WHEN o.order_id LIKE 'MYH%' THEN 'West Malaysia'
        WHEN o.order_id LIKE 'MYB%' AND (o.member_code IS NULL OR o.member_code NOT LIKE 'BN%') THEN 'East Malaysia'
        WHEN o.order_id LIKE 'MYB%' AND o.member_code LIKE 'BN%' THEN 'Brunei' ELSE NULL END";
    $getHubs = function ($from, $to) use ($pdo, $rate, $dateToExclusive, $hubExpr) {
        $stmt = $pdo->prepare("SELECT {$hubExpr} AS hub, SUM(CASE WHEN c.company_code = 'SG' THEN o.sub_total * :rate ELSE o.sub_total END) AS total
            FROM orders o JOIN companies c ON c.id = o.company_id
            WHERE o.order_datetime >= :from AND o.order_datetime < :to AND o.order_status = 'Confirmed'
            GROUP BY hub ORDER BY total DESC");
        $stmt->execute(['rate' => $rate, 'from' => $from . ' 00:00:00', 'to' => $dateToExclusive($to)]);
        return array_values(array_filter($stmt->fetchAll(), static fn($hub) => $hub['hub'] !== null));
    };
    $getBrands = function ($from, $to) use ($pdo, $rate, $dateToExclusive) {
        $stmt = $pdo->prepare("SELECT UPPER(TRIM(COALESCE(oi.brand, 'Other'))) AS brand,
            SUM(CASE WHEN c.company_code = 'SG' THEN oi.invoice_amount * :rate ELSE oi.invoice_amount END) AS total
            FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN companies c ON c.id = o.company_id
            WHERE o.order_datetime >= :from AND o.order_datetime < :to AND o.order_status = 'Confirmed'
            GROUP BY brand ORDER BY total DESC LIMIT 5");
        $stmt->execute(['rate' => $rate, 'from' => $from . ' 00:00:00', 'to' => $dateToExclusive($to)]);
        return $stmt->fetchAll();
    };

    try {
        // Keep the headline total aligned with Overall Products (Tax Invoice formula).
        // Match Sales Comparison: confirmed system orders plus manual sales.
        $data['total'] = $sumOrders($reportDate, $reportDate);
        $data['mtd'] = $sumOrders($monthStart, $reportDate);
        $data['ytd'] = $sumOrders($yearStart, $reportDate);
        $data['previous_total'] = $sumOrders(date('Y-m-d', strtotime($reportDate . ' -1 day')), date('Y-m-d', strtotime($reportDate . ' -1 day')));

        // ── Previous-period comparisons ──
        $prevMonthStart = date('Y-m-01', strtotime($reportDate . ' -1 month'));
        $prevMonthSameDay = date('Y-m-d', strtotime($reportDate . ' -1 month'));
        $data['mtd_prev'] = $sumOrders($prevMonthStart, $prevMonthSameDay);

        $prevYearStart = date('Y-01-01', strtotime($reportDate . ' -1 year'));
        $prevYearSameDay = date('Y-m-d', strtotime($reportDate . ' -1 year'));
        $data['ytd_prev'] = $sumOrders($prevYearStart, $prevYearSameDay);

        // ── Agents (MTD distinct) + ASD (same qualifying rules as ASD page) ──
        $asdMetrics = $getAsdMetrics($monthStart, $reportDate);
        $asdMetricsPrev = $getAsdMetrics($prevMonthStart, $prevMonthSameDay);
        $data['active_agents'] = $asdMetrics['active_agents'];
        $data['active_agents_prev'] = $asdMetricsPrev['active_agents'];
        $data['asd'] = $asdMetrics['asd'];
        $data['asd_prev'] = $asdMetricsPrev['asd'];
        $data['new_agent_mtd'] = $getNewAgentMetrics($monthStart, $reportDate);
        $data['new_agent_mtd_prev'] = $getNewAgentMetrics($prevMonthStart, $prevMonthSameDay);

        // ── 7-day trend + previous 7-day total ──
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime($reportDate . " -{$i} days"));
            $trend[$day] = $sumOrders($day, $day);
        }
        $data['trend'] = $trend;
        $data['trend_prev_total'] = $sumOrders(date('Y-m-d', strtotime($reportDate . ' -13 days')), date('Y-m-d', strtotime($reportDate . ' -7 days')));

        // ── Hub + brand breakdown (single day + MTD) ──
        $data['hubs'] = $getHubs($reportDate, $reportDate);
        $data['hubs_mtd'] = $getHubs($monthStart, $reportDate);
        $data['brands'] = $getBrands($reportDate, $reportDate);
        $data['brands_mtd'] = $getBrands($monthStart, $reportDate);

        $data['products'] = getDashboardTopProducts($pdo, $reportDate, $reportDate, $rate);

        try {
            $stmt = $pdo->prepare('SELECT target_amount FROM sales_target WHERE target_date = :date');
            $stmt->execute(['date' => $reportDate]);
            $data['target'] = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare('SELECT COALESCE(SUM(target_amount), 0) FROM sales_target WHERE target_date BETWEEN :from AND :to');
            $stmt->execute(['from' => $monthStart, 'to' => $reportDate]);
            $data['monthly_target'] = (float)$stmt->fetchColumn();
        } catch (Exception $e) {}
    } catch (Exception $e) {}
    return $data;
}

$dashboardData = null;
$requestedReportDate = $_GET['report_date'] ?? null;
$dbActive = false;
if ($isLoggedIn) {
    // ── Idle timeout check ──
    $idleLimit = 7200;
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleLimit) {
        session_unset();
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?expired=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    $pdo = getDBConnection();
    $dbActive = $pdo instanceof PDO;
    if ($pdo) $dashboardData = getDashboardData($pdo, $requestedReportDate);
}

function dashboardMoney($amount) {
    return 'RM ' . number_format((float)$amount, 2);
}

function dashboardMoneyShort($amount) {
    $amount = (float)$amount;
    if (abs($amount) >= 1000000) return 'RM ' . number_format($amount / 1000000, 2) . 'M';
    return 'RM ' . number_format($amount, 0);
}

function dashboardPercent($amount, $total) {
    return $total > 0 ? round(((float)$amount / (float)$total) * 100) : 0;
}

function dashboardChange($current, $previous) {
    return $previous > 0 ? (($current - $previous) / $previous) * 100 : 0;
}

// Renders a donut chart (SVG) + legend list for a hub dataset.
function renderHubDonut($hubs, $total, $hubColors, $centerLabel) {
    $r = 54; $circ = 2 * M_PI * $r;
    $svg = '<svg viewBox="0 0 140 140" class="donut-svg"><circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="#edf2f7" stroke-width="18"/>';
    $offset = 0;
    if ($total > 0 && $hubs) {
        foreach ($hubs as $hub) {
            $pct = ((float)$hub['total'] / $total) * 100;
            $seg = ($pct / 100) * $circ;
            $color = $hubColors[$hub['hub']] ?? '#9ba7b3';
            $svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="18" '
                . 'stroke-dasharray="' . round($seg, 2) . ' ' . round($circ - $seg, 2) . '" '
                . 'stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 70 70)" stroke-linecap="butt"/>';
            $offset += $seg;
        }
    }
    $svg .= '</svg>';
    $legend = '<div class="donut-legend">';
    if ($hubs) {
        foreach ($hubs as $hub) {
            $pct = dashboardPercent($hub['total'], $total);
            $color = $hubColors[$hub['hub']] ?? '#9ba7b3';
            $legend .= '<div class="legend-row"><span class="legend-dot" style="background:' . $color . '"></span>'
                . '<div class="legend-text"><span class="legend-name">' . htmlspecialchars($hub['hub']) . '</span>'
                . '<span class="legend-val">' . dashboardMoney($hub['total']) . '</span></div>'
                . '<span class="legend-pct">' . $pct . '%</span></div>';
        }
    } else {
        $legend .= '<div class="data-note">No hub sales found.</div>';
    }
    $legend .= '</div>';

    return '<div class="donut-wrap"><div class="donut-chart">' . $svg
        . '<div class="donut-center"><strong>' . dashboardMoney($total) . '</strong><span>Total Sales</span></div></div>'
        . $legend . '</div>';
}

// Renders horizontal brand bars for a brand dataset.
function renderBrandBars($brands, $total, $palette) {
    if (!$brands) return '<div class="data-note">No brand sales found.</div>';
    $maxBrand = max(array_merge([1], array_map(fn($b) => (float)$b['total'], $brands)));
    $html = '<div class="brand-list">';
    foreach ($brands as $i => $brand) {
        $pct = dashboardPercent($brand['total'], $total);
        $width = ((float)$brand['total'] / $maxBrand) * 100;
        $color = $palette[$i % count($palette)];
        $html .= '<div class="brand-row"><span class="brand-name">' . htmlspecialchars($brand['brand']) . '</span>'
            . '<div class="brand-bar"><div class="brand-fill" style="width:' . $width . '%;background:' . $color . '"></div></div>'
            . '<span class="brand-total">' . dashboardMoney($brand['total']) . '<br><small>' . $pct . '%</small></span></div>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $isLoggedIn ? 'Dashboard' : 'Admin Login' ?> — S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="icon" href="./images/icon-sasia.png"/>
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
  --sidebar-w:256px;--sidebar-w-collapsed:76px;--topbar-h:64px;
  --shadow:0 1px 2px rgba(20,20,30,.04),0 8px 24px -12px rgba(20,20,30,.10);
  --shadow-card:0 2px 8px rgba(20,20,30,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;}
a{text-decoration:none;color:inherit;}
button{font-family:inherit;cursor:pointer;border:none;background:none;}
svg{display:block;}

/* ── LOGIN ── */
.login-wrap{min-height:100vh;background:linear-gradient(135deg,var(--red) 0%,var(--red-dark) 60%,var(--red-darker) 100%);display:flex;align-items:center;justify-content:center;padding:24px;position:relative;overflow:hidden;}
.login-wrap::before{content:'';position:absolute;top:-100px;right:-100px;width:400px;height:400px;background:rgba(255,255,255,.04);border-radius:50%;}
.login-wrap::after{content:'';position:absolute;bottom:-80px;left:-80px;width:300px;height:300px;background:rgba(0,180,180,.15);border-radius:50%;}
.login-card{background:var(--white);border-radius:24px;box-shadow:0 24px 60px rgba(0,0,0,.2);width:100%;max-width:420px;padding:40px 36px;position:relative;z-index:2;}
.login-brand{text-align:center;margin-bottom:28px;}
.login-brand img{height:44px;margin-bottom:10px;}
.login-brand-fallback{width:56px;height:56px;border-radius:16px;background:var(--red);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;}
.login-brand-fallback svg{width:28px;height:28px;fill:white;}
.login-title{font-size:1.375rem;font-weight:800;text-align:center;margin-bottom:4px;}
.login-sub{font-size:0.8125rem;color:var(--gray-500);text-align:center;margin-bottom:28px;font-weight:500;}
.fg{margin-bottom:16px;}
.fg label{display:block;font-size:0.75rem;font-weight:700;margin-bottom:6px;color:var(--gray-700);}
.fg input{width:100%;padding:11px 14px;border:1.5px solid var(--gray-300);border-radius:10px;font-size:0.875rem;font-family:'Plus Jakarta Sans',sans-serif;color:var(--ink);outline:none;transition:border-color .2s,box-shadow .2s;}
.fg input:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.1);}
.err-msg{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:9px;font-size:0.8125rem;font-weight:600;margin-bottom:16px;}
.btn-login{width:100%;padding:13px;background:var(--red);color:#fff;border-radius:30px;font-size:0.875rem;font-weight:800;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .15s,box-shadow .15s;box-shadow:0 4px 14px rgba(224,32,46,.3);}
.btn-login:hover{background:var(--red-dark);}
.btn-login svg{width:16px;height:16px;stroke:white;fill:none;}

/* ── LAYOUT ── */
.layout{display:flex;margin-top:var(--topbar-h);}
.main{margin-left:var(--sidebar-w);flex:1;padding:28px 32px 48px;min-width:0;transition:margin-left .25s ease;}

/* ── PAGE HEADER ── */
.page-header{margin-bottom:24px;}
.page-header h1{font-size:1.5rem;font-weight:800;margin-bottom:3px;}
.page-header p{font-size:0.875rem;color:var(--gray-500);}

/* ── QUICK LINKS (kept for other pages) ── */
.quick-links{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;}
.quick-link{display:flex;flex-direction:column;align-items:center;gap:10px;padding:18px 12px;background:var(--gray-100);border-radius:var(--radius-md);cursor:pointer;transition:background .15s,transform .15s;border:1.5px solid transparent;}
.quick-link:hover{background:var(--white);border-color:var(--red);transform:translateY(-2px);box-shadow:var(--shadow-card);}
.quick-link-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;}
.quick-link-icon svg{width:20px;height:20px;}
.qli-red{background:rgba(224,32,46,.1);} .qli-red svg{stroke:var(--red);}
.qli-teal{background:rgba(0,180,180,.12);} .qli-teal svg{stroke:var(--teal);}
.qli-gold{background:rgba(245,166,35,.12);} .qli-gold svg{stroke:var(--gold);}
.qli-green{background:rgba(16,185,129,.12);} .qli-green svg{stroke:var(--green);}
.qli-purple{background:rgba(124,58,237,.1);} .qli-purple svg{stroke:#7c3aed;}
.quick-link-label{font-size:0.75rem;font-weight:700;text-align:center;color:var(--ink);}

/* ── SYSINFO ── */
.sysinfo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.si-item{padding:14px 16px;background:var(--gray-100);border-radius:var(--radius-md);}
.si-lbl{font-size:0.6875rem;font-weight:800;color:var(--gray-500);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.si-val{font-size:0.875rem;font-weight:700;color:var(--ink);}

/* ── DRAWER ── */
.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;opacity:0;transition:opacity .25s;}
.drawer-overlay.open{opacity:1;}
.sidebar-drawer{position:fixed;top:0;left:-280px;bottom:0;width:260px;background:#fff;z-index:400;transition:left .28s cubic-bezier(.4,0,.2,1);padding:20px 14px 80px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;box-shadow:4px 0 24px rgba(0,0,0,.12);}
.sidebar-drawer.open{left:0;}
.drawer-header{display:flex;align-items:center;justify-content:space-between;padding-bottom:16px;border-bottom:1px solid var(--gray-100);margin-bottom:8px;}
.drawer-close{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:var(--gray-100);border:none;cursor:pointer;}
.drawer-close svg{width:16px;height:16px;stroke:var(--gray-700);}

/* ══════════════ SALES DASHBOARD (v2 — matches reference design) ══════════════ */

/* Hero banner */
.dash-hero{background:linear-gradient(110deg,#c80d23 0%,#8e1620 62%,#008f9f 100%);border-radius:18px;padding:24px 28px;margin-bottom:20px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;position:relative;overflow:hidden;}
.dash-hero::after{content:'';position:absolute;width:220px;height:220px;border-radius:50%;right:80px;bottom:-150px;background:rgba(255,255,255,.08);}
.dash-hero-content{position:relative;z-index:1;min-width:260px;}
.dash-hero-kicker{font-size:0.6875rem;letter-spacing:1.2px;font-weight:800;opacity:.8;text-transform:uppercase;margin-bottom:6px;}
.dash-hero-content h1{font-size:1.5625rem;line-height:1.2;font-weight:800;margin-bottom:5px;}
.dash-hero-content p{font-size:0.75rem;opacity:.88;font-weight:500;}
.dash-hero-right{position:relative;z-index:1;display:flex;align-items:stretch;gap:12px;flex-wrap:wrap;}
.hero-box{background:rgba(255,255,255,.97);border-radius:12px;padding:9px 14px;display:flex;align-items:center;gap:10px;color:var(--ink);}
.hero-box-icon{width:32px;height:32px;border-radius:9px;background:rgba(224,32,46,.1);display:flex;align-items:center;justify-content:center;flex:none;}
.hero-box-icon svg{width:16px;height:16px;stroke:var(--red);fill:none;stroke-width:2;}
.hero-box-label{font-size:0.6875rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.3px;}
.hero-box-value{font-size:0.75rem;font-weight:800;color:var(--ink);}
.hero-box input[type=date]{border:none;background:transparent;font:800 0.78125rem 'Plus Jakarta Sans',sans-serif;color:var(--ink);cursor:pointer;padding:0;outline:none;}
.hero-status-dot{width:8px;height:8px;border-radius:50%;background:var(--green);flex:none;box-shadow:0 0 0 3px rgba(16,185,129,.18);}
.hero-cta{background:linear-gradient(135deg,var(--teal),var(--teal-dark));border-radius:14px;padding:12px 18px;display:flex;align-items:center;gap:10px;position:relative;overflow:hidden;min-width:190px;}
.hero-cta::after{content:'';position:absolute;width:70px;height:70px;border-radius:50%;background:rgba(255,255,255,.15);right:-20px;top:-25px;}
.hero-cta-icon{font-size:1.375rem;position:relative;z-index:1;}
.hero-cta-text{position:relative;z-index:1;font-size:0.75rem;font-weight:800;line-height:1.3;}

/* Metric cards */
.metric-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;}
.metric-card{background:#fff;border:1px solid var(--gray-100);border-radius:14px;padding:16px;box-shadow:var(--shadow-card);min-width:0;transition:transform .15s,box-shadow .15s;}
.metric-card:hover{transform:translateY(-2px);box-shadow:var(--shadow);}
.metric-head{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.metric-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.0625rem;flex:none;}
.mi-red{background:rgba(224,32,46,.1);} .mi-green{background:rgba(16,185,129,.12);}
.mi-teal{background:rgba(0,180,180,.12);} .mi-purple{background:rgba(124,58,237,.1);}
.mi-gold{background:rgba(245,166,35,.12);}
.metric-label{font-size:0.75rem;font-weight:700;color:var(--gray-700);}
.metric-value{font-size:1.1875rem;font-weight:800;color:var(--ink);margin:10px 0 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.metric-foot{font-size:0.6875rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.mf-up{color:var(--green);} .mf-down{color:var(--red);} .mf-neutral{color:var(--gray-500);font-weight:600;}

/* Card grid */
.dashboard-grid{display:grid;grid-template-columns:1.1fr 1fr 1.05fr;gap:16px;margin-bottom:16px;}
.dashboard-card{background:#fff;border:1px solid var(--gray-100);border-radius:14px;padding:18px;box-shadow:var(--shadow-card);min-width:0;}
.card-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:14px;flex-wrap:wrap;}
.card-heading h2{font-size:0.875rem;font-weight:800;color:var(--ink);}

/* Toggle pills */
.toggle-group{display:flex;gap:6px;background:var(--gray-100);padding:3px;border-radius:20px;}
.toggle-btn{padding:5px 12px;border-radius:16px;font-size:0.6875rem;font-weight:800;color:var(--gray-500);transition:background .15s,color .15s;}
.toggle-btn.active{background:var(--red);color:#fff;}

/* Donut (hub) */
.donut-wrap{display:flex;align-items:center;gap:18px;flex-wrap:wrap;}
.donut-chart{position:relative;width:200px;height:200px;flex:none;margin:0 auto;}
.donut-svg{width:100%;height:100%;}
.donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;}
.donut-center strong{font-size:0.9375rem;font-weight:800;color:var(--ink);line-height:1.2;}
.donut-center span{font-size:0.6875rem;color:var(--gray-500);font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
.donut-legend{flex:1;min-width:150px;display:flex;flex-direction:column;gap:10px;}
.legend-row{display:flex;align-items:center;gap:8px;font-size:0.75rem;}
.legend-dot{width:9px;height:9px;border-radius:50%;flex:none;}
.legend-text{display:flex;flex-direction:column;flex:1;min-width:0;}
.legend-name{font-weight:700;color:var(--gray-700);}
.legend-val{font-weight:800;color:var(--ink);font-size:0.75rem;}
.legend-pct{font-weight:700;color:var(--gray-500);font-size:0.6875rem;}

/* Brand bars */
.brand-list{display:grid;grid-template-columns:minmax(0,78px) minmax(0,1fr) max-content;gap:13px 10px;}
.brand-row{display:grid;grid-column:1/-1;grid-template-columns:subgrid;align-items:center;font-size:0.6875rem;min-width:0;}
.brand-name{min-width:0;font-weight:700;overflow-wrap:anywhere;}
.brand-bar{min-width:0;height:26px;background:#edf2f7;border-radius:6px;overflow:hidden;}
.brand-fill{height:100%;border-radius:6px;}
.brand-total{text-align:right;font-weight:800;color:var(--ink);white-space:nowrap;}
.brand-total small{color:var(--gray-500);font-weight:700;}

/* Product table */
.product-table{width:100%;border-collapse:collapse;font-size:0.6875rem;}
.product-table th{background:#eef4f8;color:black;text-align:left;font-size:0.6875rem;padding:9px 8px;font-weight:800;}
.product-table th:last-child,.product-table td:last-child{text-align:right;}
.product-table td{padding:9px 8px;border-bottom:1px solid #edf2f7;}
.product-table td:first-child{font-weight:700;color:var(--ink);max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.product-empty,.data-note{font-size:0.75rem;color:var(--gray-500);padding:16px 0;text-align:center;}

/* Trend + Monthly row */
.bottom-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}

/* Trend chart (line) */
.trend-chart-wrap{width:100%;}
.trend-chart-svg{width:100%;height:auto;}
.trend-pill{background:#ecfdf5;color:var(--green);border-radius:20px;padding:6px 12px;font-size:0.6875rem;font-weight:800;white-space:nowrap;}
.trend-pill.down{background:#fee2e2;color:var(--red);}

/* Monthly performance */
.monthly-pill{background:#ecfdf5;color:var(--green);border-radius:20px;padding:6px 12px;font-size:0.6875rem;font-weight:800;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.monthly-pill.behind{background:#fff7ed;color:#c2410c;}
.monthly-value{font-size:22px;font-weight:800;color:var(--ink);margin-bottom:4px;}
.monthly-value small{font-size:20px;font-weight:700;color:var(--gray-500);}
.monthly-progress{height:10px;background:#e8eef2;border-radius:9px;overflow:hidden;margin-bottom:16px;}
.monthly-progress span{display:block;height:100%;background:linear-gradient(90deg,var(--red),var(--teal));border-radius:9px;}
.monthly-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.monthly-stat{font-size:0.75rem;color:var(--gray-500);font-weight:600;}
.monthly-stat strong{display:block;font-size:1rem;color:var(--ink);margin-top:4px;font-weight:800;}
.monthly-stat.neg strong{color:var(--red);}

@media(max-width:1300px){.dashboard-grid{grid-template-columns:1fr 1fr;}.dashboard-grid .dashboard-card:nth-child(3){grid-column:1/-1;}}
@media(max-width:1100px){.metric-grid{grid-template-columns:repeat(3,1fr);} .sysinfo-grid{grid-template-columns:1fr 1fr;} .bottom-grid{grid-template-columns:1fr;}}
@media(max-width:900px){
  .sidebar{display:none;}
  .main{margin-left:0;padding:20px;}
  body.sidebar-collapsed .main{margin-left:0;}
  .dashboard-grid{grid-template-columns:1fr;}
  .dashboard-grid .dashboard-card:nth-child(3){grid-column:auto;}
  .monthly-stats{grid-template-columns:1fr;gap:10px;}
}
@media(max-width:700px){.metric-grid{grid-template-columns:repeat(2,1fr);}.dash-hero{align-items:flex-start;padding:20px;}.main .page-header{display:none;}.metric-value{font-size:1rem;}.brand-list{grid-template-columns:minmax(0,66px) minmax(0,1fr) max-content;}}
@media(max-width:600px){.metric-grid{grid-template-columns:1fr 1fr;} .main{padding:16px 14px 40px;} .dash-hero{padding:20px 22px;} .dash-hero-content h1{font-size:1.25rem;}}
</style>
</head>
<body>
<?php if ($isLoggedIn): ?>
<script>
// ── Restore collapsed sidebar state before paint (desktop only), elak "flash" ──
(function(){
    try {
        if (window.innerWidth >= 900 && localStorage.getItem('adminSidebarCollapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (e) {
        // ignore storage errors
    }
})();
</script>
<?php endif; ?>

<?php if (!$isLoggedIn): ?>
<!-- ════════════════════ LOGIN PAGE ════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <img src="./images/logo-sasia.png" alt="S ASIA" onerror="this.style.display='none';document.querySelector('.login-brand-fallback').style.display='flex'">
      <div class="login-brand-fallback" style="display:none;">
        <svg viewBox="0 0 24 24"><path d="M4 4h10a6 6 0 1 1 0 12H9l5 5H4z"/></svg>
      </div>
    </div>
    <h1 class="login-title">Admin Login</h1>
    <p class="login-sub">S ASIA SALES REPORT — Management System</p>

    <?php if ($error): ?>
    <div class="err-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <div class="fg">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus autocomplete="username">
      </div>
      <div class="fg">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn-login">
        <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Login to Dashboard
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ════════════════════ DASHBOARD ════════════════════ -->

<?php $pageTitle = 'Dashboard'; $navBasePath = './'; include __DIR__ . '/includes/topnav.php'; ?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<?php
$d = $dashboardData ?: getDashboardData(null, $requestedReportDate);
$reportLabel = date('d M Y', strtotime($d['report_date']));
$reportDay = date('d', strtotime($d['report_date']));
$reportShort = date('d M', strtotime($d['report_date']));
$latestReportDate = date('Y-m-d', strtotime('-1 day'));
$prevDayLabel = date('d M Y', strtotime($d['report_date'] . ' -1 day'));
$prevMonthLabel = date('M Y', strtotime($d['report_date'] . ' -1 month'));
$prevYearLabel = date('Y', strtotime($d['report_date'])) - 1;

$previousChange = dashboardChange($d['total'], $d['previous_total']);
$mtdChange = dashboardChange($d['mtd'], $d['mtd_prev']);
$ytdChange = dashboardChange($d['ytd'], $d['ytd_prev']);
$agentChange = dashboardChange($d['active_agents'], $d['active_agents_prev']);
$newAgentChange = dashboardChange($d['new_agent_mtd'], $d['new_agent_mtd_prev']);
$asdChange = dashboardChange($d['asd'], $d['asd_prev']);
$trendChange = dashboardChange(array_sum($d['trend']), $d['trend_prev_total']);

$dayOfMonth = (int)date('j', strtotime($d['report_date']));
$daysInMonth = (int)date('t', strtotime($d['report_date']));
$proratedTarget = (float)$d['monthly_target'];
$monthlyProgress = $d['monthly_target'] > 0 ? min(100, ($d['mtd'] / $d['monthly_target']) * 100) : 0;
$monthlyDifference = $d['mtd'] - $proratedTarget;
$monthlyDifferencePercent = $proratedTarget > 0 ? ($monthlyDifference / $proratedTarget) * 100 : 0;
$daysRemaining = max(0, $daysInMonth - $dayOfMonth);
$onTrack = $d['monthly_target'] > 0 ? ($monthlyDifference >= 0) : true;

$maxTrend = max(array_merge([1], array_values($d['trend'])));
$hubColors = ['West Malaysia' => '#ed1b2f', 'East Malaysia' => '#ff8b98', 'Brunei' => '#00a6b5', 'Singapore' => '#9ba7b3', 'Other' => '#f5a623'];
$brandPalette = ['#c8102e', '#f4a6ae', '#8b5e3c', '#00b4b4', '#f5a623'];

// Trend chart geometry
$chartW = 700; $chartH = 320; $padL = 40; $padR = 24; $padT = 32; $padB = 32;
$plotW = $chartW - $padL - $padR; $plotH = $chartH - $padT - $padB;
$trendKeys = array_keys($d['trend']);
$n = max(1, count($trendKeys) - 1);
$points = [];
foreach (array_values($d['trend']) as $i => $val) {
    $x = $padL + ($i / $n) * $plotW;
    $y = $padT + $plotH - (($val / $maxTrend) * $plotH);
    $points[] = [$x, $y, $val];
}
$polyline = implode(' ', array_map(fn($p) => round($p[0],1) . ',' . round($p[1],1), $points));
$areaPath = 'M' . round($points[0][0],1) . ',' . round($padT+$plotH,1) . ' ';
foreach ($points as $p) { $areaPath .= 'L' . round($p[0],1) . ',' . round($p[1],1) . ' '; }
$areaPath .= 'L' . round($points[count($points)-1][0],1) . ',' . round($padT+$plotH,1) . ' Z';
?>
<main class="main">
    <div class="page-header"><h1>Dashboard</h1></div>

    <section class="dash-hero">
        <div class="dash-hero-content">
            <div class="dash-hero-kicker">S ASIA SALES REPORT</div>
            <h1>Welcome back, <?= htmlspecialchars($adminUsername) ?> <span aria-hidden="true">&#128075;</span></h1>
            <p>Here's your sales overview. Keep track of performance and drive greater results together.</p>
        </div>
        <div class="dash-hero-right">
            <form method="get" class="hero-box" style="cursor:pointer;">
                <span class="hero-box-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                <span>
                    <span class="hero-box-label" style="display:block;">Reporting Date<?= $d['report_date'] === $latestReportDate ? ' (Yesterday)' : '' ?></span>
                    <input type="date" name="report_date" value="<?= htmlspecialchars($d['report_date']) ?>" max="<?= htmlspecialchars($latestReportDate) ?>">
                </span>
            </form>
            <div class="hero-box">
                <span class="hero-status-dot"></span>
                <span>
                    <span class="hero-box-label" style="display:block;">Status</span>
                    <span class="hero-box-value"><?= $dbActive ? 'Active' : 'Offline' ?></span>
                </span>
            </div>
            <div class="hero-cta">
                <span class="hero-cta-icon" aria-hidden="true">&#128200;</span>
                <span class="hero-cta-text">Better Insights<br>Stronger Tomorrow</span>
            </div>
        </div>
    </section>

    <section class="metric-grid">
        <article class="metric-card">
            <div class="metric-head"><span class="metric-label">Total Sales (<?= htmlspecialchars($reportShort) ?>)</span><span class="metric-icon mi-red">&#128200;</span></div>
            <div class="metric-value"><?= dashboardMoney($d['total']) ?></div>
            <div class="metric-foot <?= $previousChange >= 0 ? 'mf-up' : 'mf-down' ?>"><?= $previousChange >= 0 ? '&#8593; +' : '&#8595; ' ?><?= number_format(abs($previousChange), 1) ?>% vs <?= htmlspecialchars($prevDayLabel) ?></div>
        </article>
        <article class="metric-card">
            <div class="metric-head"><span class="metric-label">MTD Sales</span><span class="metric-icon mi-green">&#128176;</span></div>
            <div class="metric-value"><?= dashboardMoney($d['mtd']) ?></div>
            <div class="metric-foot <?= $mtdChange >= 0 ? 'mf-up' : 'mf-down' ?>"><?= $mtdChange >= 0 ? '&#8593; +' : '&#8595; ' ?><?= number_format(abs($mtdChange), 1) ?>% vs <?= htmlspecialchars($prevMonthLabel) ?> (MTD)</div>
        </article>
        <article class="metric-card">
            <div class="metric-head"><span class="metric-label">YTD Sales</span><span class="metric-icon mi-teal">&#128202;</span></div>
            <div class="metric-value"><?= dashboardMoney($d['ytd']) ?></div>
            <div class="metric-foot <?= $ytdChange >= 0 ? 'mf-up' : 'mf-down' ?>"><?= $ytdChange >= 0 ? '&#8593; +' : '&#8595; ' ?><?= number_format(abs($ytdChange), 1) ?>% vs <?= htmlspecialchars($prevYearLabel) ?> (YTD)</div>
        </article>
        <article class="metric-card">
            <div class="metric-head"><span class="metric-label">New Agent (MTD)</span><span class="metric-icon mi-purple">&#128101;</span></div>
            <div class="metric-value"><?= number_format($d['new_agent_mtd']) ?></div>
            <div class="metric-foot <?= $newAgentChange >= 0 ? 'mf-up' : 'mf-down' ?>"><?= $newAgentChange >= 0 ? '&#8593; +' : '&#8595; ' ?><?= number_format(abs($newAgentChange), 1) ?>% vs <?= htmlspecialchars($prevMonthLabel) ?> (MTD)</div>
        </article>
        <article class="metric-card">
            <div class="metric-head"><span class="metric-label">ASD / Avg Sales per Dealer</span><span class="metric-icon mi-gold">&#127919;</span></div>
            <div class="metric-value"><?= dashboardMoney($d['asd']) ?></div>
            <div class="metric-foot <?= $asdChange >= 0 ? 'mf-up' : 'mf-down' ?>"><?= $asdChange >= 0 ? '&#8593; +' : '&#8595; ' ?><?= number_format(abs($asdChange), 1) ?>% vs <?= htmlspecialchars($prevMonthLabel) ?> (MTD)</div>
        </article>
    </section>

    <section class="dashboard-grid">
        <article class="dashboard-card">
            <div class="card-heading">
                <h2>Sales by Hub</h2>
                <div class="toggle-group" data-toggle-group="hub">
                    <button type="button" class="toggle-btn active" data-target="yesterday">Yesterday</button>
                    <button type="button" class="toggle-btn" data-target="mtd">MTD</button>
                </div>
            </div>
            <div class="toggle-view" data-group="hub" data-view="yesterday">
                <?= renderHubDonut($d['hubs'], array_sum(array_column($d['hubs'], 'total')), $hubColors, dashboardMoneyShort(array_sum(array_column($d['hubs'], 'total')))) ?>
            </div>
            <div class="toggle-view" data-group="hub" data-view="mtd" style="display:none;">
                <?= renderHubDonut($d['hubs_mtd'], array_sum(array_column($d['hubs_mtd'], 'total')), $hubColors, dashboardMoneyShort(array_sum(array_column($d['hubs_mtd'], 'total')))) ?>
            </div>
        </article>

        <article class="dashboard-card">
            <div class="card-heading">
                <h2>Sales by Brand</h2>
                <div class="toggle-group" data-toggle-group="brand">
                    <button type="button" class="toggle-btn active" data-target="yesterday">Yesterday</button>
                    <button type="button" class="toggle-btn" data-target="mtd">MTD</button>
                </div>
            </div>
            <div class="toggle-view" data-group="brand" data-view="yesterday">
                <?= renderBrandBars($d['brands'], $d['total'], $brandPalette) ?>
            </div>
            <div class="toggle-view" data-group="brand" data-view="mtd" style="display:none;">
                <?= renderBrandBars($d['brands_mtd'], $d['mtd'], $brandPalette) ?>
            </div>
        </article>

        <article class="dashboard-card">
            <div class="card-heading"><h2>Top 5 Products (<?= htmlspecialchars($reportShort) ?>)</h2></div>
            <?php if ($d['products']): ?>
            <div style="overflow-x:auto"><table class="product-table"><thead><tr><th>No.</th><th>Product Code</th><th>Qty</th><th>Total Sales</th></tr></thead><tbody>
            <?php foreach ($d['products'] as $index => $product): ?>
                <tr><td><?= $index + 1 ?></td><td title="<?= htmlspecialchars($product['product']) ?>"><?= htmlspecialchars($product['product']) ?></td><td><?= number_format((int)$product['quantity']) ?></td><td><?= dashboardMoney($product['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php else: ?><div class="product-empty">No product sales found for this reporting date.</div><?php endif; ?>
        </article>
    </section>

    <section class="bottom-grid">
        <article class="dashboard-card">
            <div class="card-heading">
                <h2>Sales Trend (Last 7 Days)</h2>
                <span class="trend-pill <?= $trendChange >= 0 ? '' : 'down' ?>"><?= $trendChange >= 0 ? '&#8593; +' : '&#8595; ' ?><?= number_format(abs($trendChange), 1) ?>% vs previous 7 days</span>
            </div>
            <div class="trend-chart-wrap">
                <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="trend-chart-svg" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="trendFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#e0202e" stop-opacity="0.28"/>
                            <stop offset="100%" stop-color="#e0202e" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path d="<?= $areaPath ?>" fill="url(#trendFill)"/>
                    <polyline points="<?= $polyline ?>" fill="none" stroke="#e0202e" stroke-width="2.5"/>
                    <?php foreach ($points as $i => $p): ?>
                    <circle cx="<?= round($p[0], 1) ?>" cy="<?= round($p[1], 1) ?>" r="3.5" fill="#fff" stroke="#e0202e" stroke-width="2"/>
                    <?php $textAnchor = 'middle'; $labelX = $p[0]; if ($i === 0) {$textAnchor = 'start';} if ($i === count($points) - 1) {$textAnchor = 'end';}?>
                    <text x="<?= round($labelX, 1) ?>" y="<?= round($p[1] - 10, 1) ?>" font-size="12" font-weight="700" fill="#172554" text-anchor="<?= $textAnchor ?>"><?= 'RM ' . number_format($p[2], 0) ?></text>
                    <text x="<?= round($p[0], 1) ?>" y="<?= $chartH - 6 ?>" font-size="12" fill="#8A8A93" text-anchor="<?= $textAnchor ?>"><?= htmlspecialchars(date('d M', strtotime($trendKeys[$i]))) ?></text>
                <?php endforeach; ?>
                </svg>
            </div>
        </article>

        <article class="dashboard-card">
            <div class="card-heading">
                <h2>Monthly Performance (<?= htmlspecialchars(date('M Y', strtotime($d['report_date']))) ?>)</h2>
                <span class="monthly-pill <?= $onTrack ? '' : 'behind' ?>">&#127919; <?= $onTrack ? 'On track to meet monthly target!' : 'Behind monthly target pace' ?></span>
            </div>
            <div class="monthly-value"><?= dashboardMoney($d['mtd']) ?> <small>/ <?= $d['monthly_target'] > 0 ? dashboardMoney($d['monthly_target']) : 'No target set' ?></small></div>
            <div class="monthly-progress"><span style="width:<?= $monthlyProgress ?>%"></span></div>
            <div class="monthly-stats">
                <div class="monthly-stat">Supposedly Current Target<strong><?= $d['monthly_target'] > 0 ? dashboardMoney($proratedTarget) : '—' ?></strong></div>
                <div class="monthly-stat <?= $monthlyDifference < 0 ? 'neg' : '' ?>">Difference<strong><?= $d['monthly_target'] > 0 ? ($monthlyDifference >= 0 ? '+' : '-') . dashboardMoney(abs($monthlyDifference)) . ' (' . ($monthlyDifferencePercent >= 0 ? '+' : '') . number_format($monthlyDifferencePercent, 1) . '%)' : '—' ?></strong></div>
                <div class="monthly-stat">Days Remaining<strong><?= $daysRemaining ?> days</strong></div>
            </div>
        </article>
    </section>
</main>

<script>
function bindDashboardToggles() {
document.querySelectorAll('[data-toggle-group]').forEach(function (group) {
    var name = group.getAttribute('data-toggle-group');
    group.querySelectorAll('.toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            group.querySelectorAll('.toggle-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var target = btn.getAttribute('data-target');
            document.querySelectorAll('.toggle-view[data-group="' + name + '"]').forEach(function (view) {
                view.style.display = (view.getAttribute('data-view') === target) ? '' : 'none';
            });
        });
    });
});
}

function bindDashboardDateFilter() {
    var dateForm = document.querySelector('.hero-box form, form.hero-box');
    if (!dateForm || dateForm.dataset.ajaxBound === '1') return;

    dateForm.dataset.ajaxBound = '1';
    var dateInput = dateForm.querySelector('input[name="report_date"]');
    if (dateInput) {
        dateInput.addEventListener('change', function () {
            dateForm.requestSubmit();
        });
    }
    dateForm.addEventListener('submit', function (event) {
        event.preventDefault();

        if (!dateInput || !dateInput.value) return;

        var submitButton = dateInput;
        submitButton.disabled = true;

        var requestUrl = new URL(window.location.href);
        requestUrl.search = '';
        requestUrl.searchParams.set('report_date', dateInput.value);

        fetch(requestUrl.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Dashboard request failed');
                return response.text();
            })
            .then(function (html) {
                var parsedPage = new DOMParser().parseFromString(html, 'text/html');
                var updatedMain = parsedPage.querySelector('.main');
                var currentMain = document.querySelector('.main');
                if (!updatedMain || !currentMain) throw new Error('Dashboard content missing');

                currentMain.replaceWith(updatedMain);
                bindDashboardToggles();
                bindDashboardDateFilter();
            })
            .catch(function () {
                dateInput.disabled = false;
                dateInput.form.submit();
            });
    });
}

bindDashboardToggles();
bindDashboardDateFilter();
</script>

<?php endif; ?>
</body>
</html>