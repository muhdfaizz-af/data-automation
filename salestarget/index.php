<?php
/**
 * S ASIA SALES REPORT - Sales Target & Reforecast (single file)
 * GET  -> renders the page (Target editable, Sales/Different/New Target computed live)
 * POST -> AJAX upsert of target_amount (JSON body: {targets:[{date,amount},...]})
 *
 * Only target_amount is ever written to DB. Sales/Different/New Target
 * are recalculated live from `orders` on every load — never stored.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';           // expects $pdo (PDO instance)

if (!function_exists('toMyr')) {
    function toMyr($amount, $currency) {
        // TODO: replace with the real FX helper used elsewhere (e.g. sales-performance.php)
        $rates = ['MYR' => 1.0, 'SGD' => 3.35];
        return $amount * ($rates[$currency] ?? 1.0);
    }
}

$isLoggedIn = isset($_SESSION['admin_id']);

$pdo = null;
try {
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
} catch (PDOException $e) {
  http_response_code(503);
  exit('Database connection failed. Please check the database configuration.');
}

// ============================================================
// POST = AJAX save (return JSON, exit early — never reaches HTML below)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!$isLoggedIn) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Session tamat, sila login semula.']);
        exit;
    }

    $adminId = (int)$_SESSION['admin_id'];
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['targets']) || !is_array($body['targets'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap.']);
        exit;
    }

    $sql = "INSERT INTO sales_target (target_date, target_amount, created_by, updated_by)
          VALUES (:date, :amount, :created_by, :updated_by)
            ON DUPLICATE KEY UPDATE
                target_amount = VALUES(target_amount),
                updated_by    = VALUES(updated_by)";
    $stmt = $pdo->prepare($sql);

    $saved  = 0;
    $errors = [];

    try {
        $pdo->beginTransaction();

        foreach ($body['targets'] as $row) {
            $date   = $row['date']   ?? null;
            $amount = $row['amount'] ?? null;

            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $errors[] = "Tarikh tidak sah: " . json_encode($row);
                continue;
            }
            if (!is_numeric($amount) || $amount < 0) {
                $errors[] = "Jumlah tidak sah untuk $date";
                continue;
            }
            $stmt->execute([
              ':date'       => $date,
              ':amount'     => (float)$amount,
              ':created_by' => $adminId,
              ':updated_by' => $adminId,
            ]);
            $saved++;
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'saved' => $saved, 'errors' => $errors]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Ralat database semasa simpan target.']);
    }

    exit; // IMPORTANT: stop here, don't fall through to HTML rendering below
}

// ============================================================
// GET = render page
// ============================================================
if (!$isLoggedIn) {
    header('Location: ../index.php');
    exit;
}
if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 7200) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

$activeNav   = 'sales-target';
$navBasePath = '../';

// ---- resolve selected month (default: current) ----
$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}
[$selYear, $selMonth] = array_map('intval', explode('-', $monthParam));

$firstDay    = sprintf('%04d-%02d-01', $selYear, $selMonth);
$daysInMonth = (int)date('t', strtotime($firstDay));
$lastDay     = sprintf('%04d-%02d-%02d', $selYear, $selMonth, $daysInMonth);
$today       = date('Y-m-d');

// ---- existing targets for this month ----
$targets = [];
$stmt = $pdo->prepare("SELECT target_date, target_amount FROM sales_target WHERE target_date BETWEEN :start AND :end");
$stmt->execute([':start' => $firstDay, ':end' => $lastDay]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $targets[$row['target_date']] = (float)$row['target_amount'];
}

// ---- actual sales for this month, grouped by date+currency, converted to MYR ----
$salesByDate = [];
$stmt = $pdo->prepare(
    "SELECT DATE(order_datetime) AS d, currency_code, SUM(sub_total) AS subtotal_sum
     FROM orders
     WHERE order_datetime >= :start AND order_datetime < DATE_ADD(:end, INTERVAL 1 DAY)
    GROUP BY DATE(order_datetime), currency_code"
);
$stmt->execute([':start' => $firstDay, ':end' => $lastDay]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $myr = toMyr((float)$row['subtotal_sum'], $row['currency_code']);
    $salesByDate[$row['d']] = ($salesByDate[$row['d']] ?? 0) + $myr;
}

// ---- reforecast: CumulativeVariance / RemainingDays ----
$isCurrentMonth = ($monthParam === date('Y-m'));
$todayDayNum    = (int)date('j');
$remainingDays  = $isCurrentMonth ? ($daysInMonth - $todayDayNum + 1) : $daysInMonth;
if ($remainingDays < 1) $remainingDays = 1;

$cumulativeVariance = 0.0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = sprintf('%04d-%02d-%02d', $selYear, $selMonth, $d);
    if ($dateStr >= $today) continue;
    $target = $targets[$dateStr] ?? 0.0;
    $sales  = $salesByDate[$dateStr] ?? 0.0;
    $cumulativeVariance += ($sales - $target);
}

$rows = [];
$totals = ['target' => 0.0, 'sales' => 0.0, 'different' => 0.0, 'new_target' => 0.0];

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = sprintf('%04d-%02d-%02d', $selYear, $selMonth, $d);
    $isPast  = ($dateStr < $today);

    $target    = $targets[$dateStr] ?? 0.0;
    $sales     = $isPast ? ($salesByDate[$dateStr] ?? 0.0) : null;
    $different = $isPast ? ($sales - $target) : null;
    $newTarget = $isPast ? $sales : ($target - ($cumulativeVariance / $remainingDays));

    $rows[] = [
        'date' => $dateStr, 'is_past' => $isPast, 'target' => $target,
        'sales' => $sales, 'different' => $different, 'new_target' => $newTarget,
    ];

    $totals['target']     += $target;
    $totals['sales']      += $sales ?? 0.0;
    $totals['different']  += $different ?? 0.0;
    $totals['new_target'] += $newTarget;
}

$monthLabel = date('F Y', strtotime($firstDay));
$prevMonth  = date('Y-m', strtotime($firstDay . ' -1 month'));
$nextMonth  = date('Y-m', strtotime($firstDay . ' +1 month'));

function fmtMoney($v) {
    if ($v === null) return '—';
    return 'RM ' . number_format($v, 2);
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sales Target — S ASIA SALES REPORT</title>
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
  --gold:#F5A623;--green:#10B981;--red-neg:#DC2626;
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

@media(max-width:900px){ .main{margin-left:0 !important;} }

.layout{display:flex;margin-top:var(--topbar-h);}
.main{margin-left:var(--sidebar-w);flex:1;padding:28px 32px 48px;max-width:1200px;min-width:0;transition:margin-left .25s ease;}
body.sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed);}
@media(max-width:900px){
  .main{margin-left:0;padding:20px 16px 40px;}
  body.sidebar-collapsed .main{margin-left:0 !important;}
}

.page-header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;}
.page-header h1{font-size:28px;font-weight:800;margin-bottom:4px;color:var(--ink);}
.page-header p{font-size:14px;color:var(--gray-500);}

.card{background:var(--white);border-radius:var(--radius-lg);padding:28px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);margin-bottom:24px;}

.month-nav{display:flex;align-items:center;gap:10px;}
.month-nav a{width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);border:1.5px solid var(--gray-300);color:var(--ink);font-weight:700;}
.month-nav a:hover{background:var(--gray-100);}
.month-label{font-size:15px;font-weight:800;min-width:150px;text-align:center;}
.month-picker{padding:9px 12px;border:1.5px solid var(--gray-300);border-radius:var(--radius-sm);font-size:13px;font-family:inherit;}

.legend{display:flex;gap:18px;font-size:12px;color:var(--gray-500);margin-bottom:16px;flex-wrap:wrap;}
.legend span{display:inline-flex;align-items:center;gap:6px;}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;}
.dot-locked{background:var(--gray-500);}
.dot-future{background:var(--teal);}

.tbl-wrap{overflow-x:auto;}
table.target-tbl{width:100%;border-collapse:collapse;font-size:13px;}
table.target-tbl th{text-align:right;padding:10px 12px;background:var(--gray-100);font-weight:800;color:var(--gray-700);white-space:nowrap;position:sticky;top:0;}
table.target-tbl th:first-child,table.target-tbl td:first-child{text-align:left;}
table.target-tbl td{padding:8px 12px;border-bottom:1px solid var(--gray-100);text-align:right;white-space:nowrap;}
table.target-tbl tr.row-today td{background:#fff8e1;}
table.target-tbl tr.row-locked td{color:var(--gray-500);}
table.target-tbl tr.row-total td{font-weight:800;border-top:2px solid var(--ink);border-bottom:none;background:var(--gray-100);}

.target-input{width:130px;padding:7px 10px;border:1.5px solid var(--gray-300);border-radius:var(--radius-sm);font-size:13px;font-family:inherit;text-align:right;}
.target-input:focus{outline:none;border-color:var(--red);}
.target-input[readonly]{background:var(--gray-100);border-color:var(--gray-100);color:var(--gray-500);cursor:not-allowed;}

.badge-lock{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--gray-500);}
.diff-pos{color:var(--green);font-weight:700;}
.diff-neg{color:var(--red-neg);font-weight:700;}

.button-group{display:flex;gap:12px;margin-top:20px;flex-wrap:wrap;align-items:center;}
.btn{padding:12px 24px;border-radius:var(--radius-md);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.btn-primary{background:var(--red);color:white;box-shadow:0 4px 12px rgba(224,32,46,.3);}
.btn-primary:hover{background:var(--red-dark);}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;}
.btn svg{width:16px;height:16px;}
.save-status{font-size:13px;font-weight:600;}
.save-status.ok{color:var(--green);}
.save-status.err{color:var(--red-neg);}

.alert{padding:14px 16px;border-radius:var(--radius-md);margin-bottom:16px;font-size:13px;font-weight:600;}
.alert-success{background:#d1fae5;border:1px solid #6ee7b7;color:#047857;}
.alert-error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
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

<?php $pageTitle = 'Sales Target'; $showMobileMenu = true; include __DIR__ . '/../includes/topnav.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="layout">
<main class="main">
  <div class="page-header">
    <div>
      <h1>Sales Target &amp; Reforecast</h1>
      <p>Set daily target — Sales, Different &amp; New Target dikira live dari data order sebenar</p>
    </div>
    <div class="month-nav">
      <a href="?month=<?= $prevMonth ?>" title="Bulan sebelum">‹</a>
      <div class="month-label"><?= htmlspecialchars($monthLabel) ?></div>
      <a href="?month=<?= $nextMonth ?>" title="Bulan seterusnya">›</a>
      <input type="month" class="month-picker" id="monthPicker" value="<?= htmlspecialchars($monthParam) ?>">
    </div>
  </div>

  <div id="statusContainer"></div>

  <div class="card">
    <div class="legend">
      <span><i class="dot dot-future"></i> Semua tarikh boleh diedit</span>
    </div>

    <form id="targetForm">
      <div class="tbl-wrap">
        <table class="target-tbl">
          <thead>
            <tr>
              <th>Date</th>
              <th>Target</th>
              <th>Sales</th>
              <th>Different</th>
              <th>New Target</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
                $rowClass = '';
                if ($r['date'] === $today) $rowClass .= ' row-today';
                $diffClass = '';
                if ($r['different'] !== null) {
                    $diffClass = $r['different'] >= 0 ? 'diff-pos' : 'diff-neg';
                }
            ?>
            <tr class="<?= trim($rowClass) ?>">
              <td>
                <?= date('D, d M', strtotime($r['date'])) ?>
              </td>
              <td>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  class="target-input"
                  data-date="<?= $r['date'] ?>"
                  value="<?= number_format($r['target'], 2, '.', '') ?>"
                >
              </td>
              <td><?= fmtMoney($r['sales']) ?></td>
              <td class="<?= $diffClass ?>"><?= $r['different'] !== null ? ($r['different'] >= 0 ? '+' : '') . fmtMoney($r['different']) : '—' ?></td>
              <td><?= fmtMoney($r['new_target']) ?></td>
            </tr>
            <?php endforeach; ?>

            <tr class="row-total">
              <td>TOTAL</td>
              <td><?= fmtMoney($totals['target']) ?></td>
              <td><?= fmtMoney($totals['sales']) ?></td>
              <td class="<?= $totals['different'] >= 0 ? 'diff-pos' : 'diff-neg' ?>"><?= ($totals['different'] >= 0 ? '+' : '') . fmtMoney($totals['different']) ?></td>
              <td><?= fmtMoney($totals['new_target']) ?></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="button-group">
        <button type="submit" class="btn btn-primary" id="saveBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
          Save Targets
        </button>
        <span class="save-status" id="saveStatus"></span>
      </div>
    </form>
  </div>
</main>
</div>

<script>
document.getElementById('monthPicker').addEventListener('change', function(){
  if (this.value) window.location.href = '?month=' + this.value;
});

// POSTs to THIS SAME FILE (no more separate save-target.php)
document.getElementById('targetForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const saveBtn = document.getElementById('saveBtn');
  const status = document.getElementById('saveStatus');
  const statusContainer = document.getElementById('statusContainer');

  const inputs = document.querySelectorAll('.target-input');
  const payload = Array.from(inputs).map(inp => ({
    date: inp.dataset.date,
    amount: parseFloat(inp.value || '0')
  }));

  if (payload.length === 0) {
    status.textContent = 'Takde apa nak save.';
    status.className = 'save-status err';
    return;
  }

  saveBtn.disabled = true;
  status.textContent = '⏳ Saving...';
  status.className = 'save-status';

  try {
    const res = await fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ targets: payload })
    });
    const data = await res.json();

    if (!res.ok || data.status !== 'success') {
      throw new Error(data.message || ('Server error ' + res.status));
    }

    statusContainer.innerHTML = `<div class="alert alert-success">✅ ${data.saved} target berjaya disimpan.</div>`;
    status.textContent = '';
    setTimeout(() => window.location.reload(), 600);

  } catch (err) {
    statusContainer.innerHTML = `<div class="alert alert-error">❌ Gagal simpan: ${err.message}</div>`;
    status.textContent = '';
    saveBtn.disabled = false;
  }
});

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
function openDrawer(){ document.getElementById('sidebarDrawer').classList.add('open'); document.getElementById('drawerOverlay').classList.add('open'); }
function closeDrawer(){ document.getElementById('sidebarDrawer').classList.remove('open'); document.getElementById('drawerOverlay').classList.remove('open'); }
window.addEventListener('resize', function(){
  if (window.innerWidth < 900) {
    document.body.classList.remove('sidebar-collapsed');
  } else {
    try { if (localStorage.getItem('adminSidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed'); } catch (e) {}
  }
});
</script>
</body>
</html>