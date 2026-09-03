<?php
/**
 * S ASIA SALES REPORT - Manual Sales Entry (Single File)
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

$isLoggedIn = isset($_SESSION['admin_id']);
$adminUsername = $_SESSION['admin_username'] ?? '';

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

$activeNav = 'manual_sales';
$navBasePath = '../';
$message = '';
$messageType = '';

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $companyId = (int)($_POST['company_id'] ?? 0);
    $channelId = (int)($_POST['sales_channel_id'] ?? 0);
    $salesDate = $_POST['sales_date'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $brand = $_POST['brand'] ?? 'CHOCO ALBAB';
    $remarks = trim($_POST['remarks'] ?? '');
    $userId = (int)$_SESSION['admin_id'];
    
    if ($_POST['action'] === 'save') {
        try {
            if (!$companyId || !$channelId || !$salesDate || $amount <= 0) {
                throw new Exception('Please fill in all required fields.');
            }
            
            $stmt = $pdo->prepare('SELECT id FROM manual_sales WHERE company_id = ? AND sales_channel_id = ? AND sales_date = ?');
            $stmt->execute([$companyId, $channelId, $salesDate]);
            if ($stmt->fetch()) {
                throw new Exception('Entry already exists for this company, channel and date.');
            }
            
            $stmt = $pdo->prepare('INSERT INTO manual_sales (company_id, sales_channel_id, sales_date, amount, brand, remarks, entered_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$companyId, $channelId, $salesDate, $amount, $brand, $remarks, $userId]);
            
            $message = 'Manual sales saved successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if ($_POST['action'] === 'delete' && isset($_POST['delete_id'])) {
        try {
            $stmt = $pdo->prepare('DELETE FROM manual_sales WHERE id = ?');
            $stmt->execute([(int)$_POST['delete_id']]);
            $message = 'Entry deleted successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ============================================================
// FETCH DATA
// ============================================================
$companies = [];
$channels = [];
$recentEntries = [];

try {
    if ($pdo) {
        $companies = $pdo->query('SELECT id, company_code, company_name, currency_code FROM companies WHERE is_active = 1 ORDER BY company_code')->fetchAll();
        $channels = $pdo->query('SELECT id, channel_code, channel_name FROM sales_channels WHERE is_active = 1 ORDER BY channel_code')->fetchAll();
        $recentEntries = $pdo->query('
            SELECT ms.*, c.company_code, c.currency_code, sc.channel_code 
            FROM manual_sales ms
            JOIN companies c ON ms.company_id = c.id
            JOIN sales_channels sc ON ms.sales_channel_id = sc.id
            ORDER BY ms.sales_date DESC, ms.created_at DESC
            LIMIT 50
        ')->fetchAll();
    }
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Manual Sales — S ASIA SALES REPORT</title>
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
@media(max-width:768px){
  .grid-2,.grid-3{grid-template-columns:1fr;}
}

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
.btn-danger{background:var(--red);color:white;padding:4px 12px;font-size:12px;border-radius:var(--radius-sm);}
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

<?php $pageTitle = 'Manual Sales'; $showMobileMenu = true; include __DIR__ . '/../includes/topnav.php'; ?>

<!-- SIDEBAR -->
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<!-- LAYOUT -->
<div class="layout">
<main class="main">
  <div class="page-header">
    <h1>Manual Sales Entry</h1>
    <p>Enter sales that are not available in Solucis system</p>
  </div>

  <!-- STATUS MESSAGES -->
  <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <!-- FORM -->
  <div class="card">
    <div class="card-title">➕ Add Manual Sales</div>
    
    <form method="POST" action="">
      <input type="hidden" name="action" value="save">
      
      <div class="grid-2">
        <div class="form-group">
          <label class="form-label" for="company_id">Company <span class="required">*</span></label>
          <select class="form-control" id="company_id" name="company_id" required>
            <option value="">— Select Company —</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= $c['id'] ?>">
                <?= htmlspecialchars($c['company_code']) ?> — <?= htmlspecialchars($c['company_name']) ?> (<?= $c['currency_code'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="sales_channel_id">Sales Channel <span class="required">*</span></label>
          <select class="form-control" id="sales_channel_id" name="sales_channel_id" required>
            <option value="">— Select Channel —</option>
            <?php foreach ($channels as $ch): ?>
              <option value="<?= $ch['id'] ?>">
                <?= htmlspecialchars($ch['channel_code']) ?> — <?= htmlspecialchars($ch['channel_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="grid-3">
        <div class="form-group">
          <label class="form-label" for="sales_date">Sales Date <span class="required">*</span></label>
          <input type="date" class="form-control" id="sales_date" name="sales_date" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="amount">Amount <span class="required">*</span></label>
          <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" placeholder="0.00" required>
          <span class="form-hint">Currency auto-detected from company</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="brand">Brand <span class="required">*</span></label>
          <select class="form-control" id="brand" name="brand" required>
            <option value="CHOCO ALBAB">CHOCO ALBAB</option>
            <option value="NAFESA">NAFESA</option>
            <option value="ZEKY">ZEKY</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="remarks">Remarks</label>
        <input type="text" class="form-control" id="remarks" name="remarks" placeholder="Optional notes about this entry">
      </div>

      <div class="button-group">
        <button type="submit" class="btn btn-success">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/><polygon points="18 2 22 6 12 16 8 16 8 12 18 2"/></svg>
          Save Entry
        </button>
        <button type="reset" class="btn btn-secondary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 4 21 4"/><path d="M19 4v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
          Reset
        </button>
      </div>
    </form>
  </div>

  <!-- RECENT ENTRIES -->
  <div class="card">
    <div class="card-title">📋 Recent Manual Sales</div>
    
    <?php if (empty($recentEntries)): ?>
      <div class="text-center text-muted" style="padding:20px;">
        <p>No manual sales entries yet.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Company</th>
              <th>Channel</th>
              <th>Brand</th>
              <th>Amount</th>
              <th>Remarks</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentEntries as $row): ?>
              <?php 
                $brandClass = $row['brand'] === 'CHOCO ALBAB' ? 'ca' : ($row['brand'] === 'NAFESA' ? 'nf' : 'zk');
                $currencyClass = $row['currency_code'] === 'MYR' ? 'myr' : 'sgd';
              ?>
              <tr>
                <td><?= htmlspecialchars($row['sales_date']) ?></td>
                <td><strong><?= htmlspecialchars($row['company_code']) ?></strong></td>
                <td><?= htmlspecialchars($row['channel_code']) ?></td>
                <td><span class="badge badge-<?= $brandClass ?>"><?= htmlspecialchars($row['brand']) ?></span></td>
                <td><span class="badge badge-<?= $currencyClass ?>"><?= htmlspecialchars($row['currency_code']) ?> <?= number_format($row['amount'], 2) ?></span></td>
                <td><?= htmlspecialchars($row['remarks'] ?: '-') ?></td>
                <td class="text-center">
                  <form method="POST" action="" onsubmit="return confirm('Delete this entry?')" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-danger">✕</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</main>
</div>

<script>
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

// Auto clear success message after 5 seconds
setTimeout(function() {
  const alerts = document.querySelectorAll('.alert-success');
  alerts.forEach(function(el) {
    el.style.transition = 'opacity 0.5s';
    el.style.opacity = '0';
    setTimeout(function() { el.remove(); }, 500);
  });
}, 5000);
</script>

</body>
</html>