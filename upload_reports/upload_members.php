<?php
/**
 * S ASIA SALES REPORT - Members Upload Interface
 * Web form untuk upload Member List (Excel / CSV)
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

// Handle member uploads in this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  ini_set('max_execution_time', '0');
  ini_set('memory_limit', '1024M');

  if (!isset($_FILES['members']) || $_FILES['members']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please select a member list file.']);
    exit;
  }

  require_once __DIR__ . '/../vendor/autoload.php';

  function memberHeader($value) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$value)));
  }

  function memberValue($row, $headers, array $names) {
    foreach ($names as $name) {
      $key = memberHeader($name);
      if (isset($headers[$key])) return trim((string)($row[$headers[$key]] ?? ''));
    }
    return '';
  }

  function memberDate($value) {
    if ($value === '') return null;
    if (in_array(trim((string)$value), ['0000-00-00', '0000-00-00 00:00:00'], true)) return null;
    if (is_numeric($value) && $value > 40000 && $value < 60000) return date('Y-m-d', (int)(($value - 25569) * 86400));
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
  }

  function memberAmount($value) {
    if ($value === '') return null;
    $value = str_replace(['RM', 'SGD', ',', ' ', '"'], '', strtoupper($value));
    return is_numeric($value) ? (float)$value : null;
  }

  function memberCompanyId(PDO $pdo, $value) {
    $value = strtoupper(trim((string)$value));
    $stmt = $pdo->prepare('SELECT id FROM companies WHERE UPPER(company_code) = :value OR UPPER(company_name) = :value LIMIT 1');
    $stmt->execute(['value' => $value]);
    $companyId = $stmt->fetchColumn();
    return $companyId === false ? null : (int)$companyId;
  }

  try {
    $file = $_FILES['members'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'csv', 'txt', 'tsv'], true)) throw new RuntimeException('Only .xlsx, .csv, .txt and .tsv files are supported.');

    $hash = hash_file('sha256', $file['tmp_name']);
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $duplicate = $pdo->prepare('SELECT id, status FROM import_batches WHERE file_hash = :hash LIMIT 1');
    $duplicate->execute(['hash' => $hash]);
    $existingBatch = $duplicate->fetch();
    if ($existingBatch && $existingBatch['status'] === 'completed') {
      echo json_encode(['status' => 'success', 'members' => ['skipped' => true, 'message' => 'File already imported.']]);
      exit;
    }
    if ($existingBatch) $pdo->prepare('UPDATE import_batches SET file_hash = NULL WHERE id = :id')->execute(['id' => $existingBatch['id']]);

    if ($extension === 'xlsx') {
      $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    } else {
      $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
      $delimiter = $_POST['delimiter'] ?? ',';
      $reader->setDelimiter($delimiter === "\t" ? "\t" : ($delimiter !== '' ? $delimiter : ','));
    }
    $rows = $reader->load($file['tmp_name'])->getActiveSheet()->toArray(null, true, true, false);
    if (count($rows) < 2) throw new RuntimeException('File is empty.');

    $headers = [];
    foreach ($rows[0] as $index => $header) $headers[memberHeader($header)] = $index;
    $memberIdNames = ['memberid', 'membercode', 'memberno', 'id'];
    $hasMemberId = false;
    foreach ($memberIdNames as $name) $hasMemberId = $hasMemberId || isset($headers[memberHeader($name)]);
    if (!$hasMemberId) throw new RuntimeException('Required columns missing. Header must include Member ID.');

    $firstMemberId = memberValue($rows[1], $headers, $memberIdNames);
    $firstCompanyCode = strtoupper(substr($firstMemberId, 0, 2)) === 'SG' ? 'SG' : 'MY';
    $companyId = memberCompanyId($pdo, $firstCompanyCode);
    if (!$companyId) throw new RuntimeException('Company code was not found in the companies table.');
    $batch = $pdo->prepare("INSERT INTO import_batches (company_id, file_type, original_filename, file_hash, total_rows, status) VALUES (:company_id, 'MEMBERS', :filename, :hash, :total_rows, 'processing')");
    $batch->execute(['company_id' => $companyId, 'filename' => $file['name'], 'hash' => $hash, 'total_rows' => count($rows) - 1]);
    $batchId = (int)$pdo->lastInsertId();

    $statement = $pdo->prepare("INSERT INTO members (company_id, import_batch_id, member_code, member_name, nric, mobile_no, email, joined_date, sponsor_code, sponsor_name, status, cl_code, cl_name, occupation, date_of_birth, source_of_funds, estimated_monthly_income, gender, marital_status, current_rank, highest_rank) VALUES (:company_id, :batch_id, :member_code, :member_name, :nric, :mobile_no, :email, :joined_date, :sponsor_code, :sponsor_name, :status, :cl_code, :cl_name, :occupation, :date_of_birth, :source_of_funds, :estimated_monthly_income, :gender, :marital_status, :current_rank, :highest_rank) ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), import_batch_id = VALUES(import_batch_id), member_name = VALUES(member_name), nric = VALUES(nric), mobile_no = VALUES(mobile_no), email = VALUES(email), joined_date = VALUES(joined_date), sponsor_code = VALUES(sponsor_code), sponsor_name = VALUES(sponsor_name), status = VALUES(status), cl_code = VALUES(cl_code), cl_name = VALUES(cl_name), occupation = VALUES(occupation), date_of_birth = VALUES(date_of_birth), source_of_funds = VALUES(source_of_funds), estimated_monthly_income = VALUES(estimated_monthly_income), gender = VALUES(gender), marital_status = VALUES(marital_status), current_rank = VALUES(current_rank), highest_rank = VALUES(highest_rank)");
    $pdo->beginTransaction();
    $inserted = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];
    foreach (array_slice($rows, 1) as $rowNumber => $row) {
      $line = $rowNumber + 2;
      $memberId = memberValue($row, $headers, $memberIdNames);
      $companyCode = strtoupper(substr($memberId, 0, 2)) === 'SG' ? 'SG' : 'MY';
      $rowCompanyId = memberCompanyId($pdo, $companyCode);
      if (!$rowCompanyId || $memberId === '') { $failed++; $errors[] = "Row {$line}: Valid Company and Member ID are required."; continue; }
      try {
        $statement->execute([
          'company_id' => $rowCompanyId, 'batch_id' => $batchId, 'member_code' => $memberId,
          'member_name' => memberValue($row, $headers, ['nameasperic', 'name', 'fullname']),
          'nric' => memberValue($row, $headers, ['nric', 'ic', 'icno']),
          'mobile_no' => memberValue($row, $headers, ['mobileno', 'mobile', 'phoneno']),
          'email' => memberValue($row, $headers, ['email', 'emailaddress']),
          'joined_date' => memberDate(memberValue($row, $headers, ['joineddate', 'joindate', 'registrationdate'])),
          'sponsor_code' => memberValue($row, $headers, ['sponsorid', 'sponsorcode']),
          'sponsor_name' => memberValue($row, $headers, ['sponsorname']), 'status' => memberValue($row, $headers, ['status']),
          'cl_code' => memberValue($row, $headers, ['clcode']), 'cl_name' => memberValue($row, $headers, ['clname']),
          'occupation' => memberValue($row, $headers, ['occupation']), 'date_of_birth' => memberDate(memberValue($row, $headers, ['dateofbirth', 'dob', 'birthdate'])),
          'source_of_funds' => memberValue($row, $headers, ['sourceoffunds']),
          'estimated_monthly_income' => memberAmount(memberValue($row, $headers, ['estimatedmonthlyincome', 'monthlyincome'])),
          'gender' => memberValue($row, $headers, ['gender', 'sex']), 'marital_status' => memberValue($row, $headers, ['maritalstatus']),
          'current_rank' => memberValue($row, $headers, ['currentrank', 'rank']), 'highest_rank' => memberValue($row, $headers, ['highestrank']),
        ]);
        if ($statement->rowCount() === 1) $inserted++;
        else $updated++;
      } catch (Throwable $error) { $failed++; $errors[] = "Row {$line}: " . $error->getMessage(); }
    }
    $pdo->commit();
    $pdo->prepare('UPDATE import_batches SET successful_rows = :successful, failed_rows = :failed, status = :status, imported_at = NOW() WHERE id = :id')->execute(['successful' => $inserted + $updated, 'failed' => $failed, 'status' => $failed ? 'completed_with_errors' : 'completed', 'id' => $batchId]);
    echo json_encode(['status' => 'success', 'members' => ['total' => count($rows) - 1, 'inserted' => $inserted, 'updated' => $updated, 'failed' => $failed, 'errors' => array_slice($errors, 0, 100)]]);
  } catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()]);
  }
  exit;
}

// Define active nav for sidebar
$activeNav = 'upload_members';
$navBasePath = '../';
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Upload Members — S ASIA SALES REPORT</title>
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

/* ── MEMBERS PAGE EXTRAS ── */
.columns-list{display:flex;flex-wrap:wrap;gap:8px;}
.column-chip{padding:6px 12px;background:var(--gray-100);border:1px solid var(--gray-300);border-radius:999px;font-size:12px;font-weight:600;color:var(--gray-700);}
.column-chip.required{background:#fff0f1;border-color:var(--red);color:var(--red-dark);}
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:12px;}
.stat-box{background:var(--gray-100);border-radius:var(--radius-md);padding:14px 16px;}
.stat-box .stat-num{font-size:22px;font-weight:800;color:var(--ink);}
.stat-box .stat-lbl{font-size:12px;color:var(--gray-500);font-weight:600;margin-top:2px;}
.stat-box.ok .stat-num{color:var(--green);}
.stat-box.warn .stat-num{color:var(--gold);}
.stat-box.bad .stat-num{color:var(--red);}
.error-list{margin-top:16px;font-size:12px;color:var(--gray-700);max-height:180px;overflow:auto;background:var(--gray-100);border-radius:var(--radius-sm);padding:12px 14px;line-height:1.7;}
@media(max-width:768px){.stat-grid{grid-template-columns:repeat(2,1fr);}}

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

<?php $pageTitle = 'Upload Members'; $showMobileMenu = true; include __DIR__ . '/../includes/topnav.php'; ?>

<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<!-- LAYOUT -->
<div class="layout">
<main class="main">
  <div class="page-header">
    <h1>Upload Members</h1>
    <p>Import Member List data from Excel or CSV files</p>
  </div>

  <div id="statusContainer"></div>

  <!-- UPLOAD FORM -->
  <div class="card">
    <div class="card-title">📁 Select File</div>

    <!-- MEMBER LIST UPLOAD -->
    <div class="upload-group">
      <label class="upload-label">Member List File</label>
      <span class="upload-hint">Supported: .xlsx, .csv, .txt (tab-separated)</span>
      <div class="upload-dropzone" id="membersDropZone">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <div class="upload-dropzone-text">Drag files here or click</div>
        <div class="upload-dropzone-sub">or paste your Member List file</div>
      </div>
      <div id="membersPreview"></div>
      <input type="file" id="membersFile" class="upload-input" accept=".xlsx,.csv,.txt,.tsv">
    </div>

    <!-- DELIMITER SETTING -->
    <div class="upload-group">
      <label class="upload-label">CSV Delimiter (if CSV / TXT file)</label>
      <span class="upload-hint">Usually comma (,) or semicolon (;). Taip <strong>t</strong> untuk Tab</span>
      <input type="text" id="delimiter" class="delimiter-input" value="," placeholder="," maxlength="1">
    </div>

    <!-- ACTION BUTTONS -->
    <div class="button-group">
      <button id="uploadBtn" class="btn btn-primary" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        Upload File
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

  <!-- EXPECTED COLUMNS -->
  <div class="card">
    <div class="card-title">📋 Expected Columns</div>
    <span class="upload-hint">Header row mesti ada column berikut. Yang berwarna merah wajib diisi.</span>
    <div class="columns-list">
      <span class="column-chip required">Company</span>
      <span class="column-chip required">Member ID</span>
      <span class="column-chip">Name as per IC</span>
      <span class="column-chip">NRIC</span>
      <span class="column-chip">Mobile No</span>
      <span class="column-chip">Email</span>
      <span class="column-chip">Joined Date</span>
      <span class="column-chip">Sponsor ID</span>
      <span class="column-chip">Sponsor Name</span>
      <span class="column-chip">Status</span>
      <span class="column-chip">CL Code</span>
      <span class="column-chip">CL Name</span>
      <span class="column-chip">Occupation</span>
      <span class="column-chip">Date of Birth</span>
      <span class="column-chip">Source of Funds</span>
      <span class="column-chip">Estimated Monthly Income</span>
      <span class="column-chip">Gender</span>
      <span class="column-chip">Marital Status</span>
      <span class="column-chip">Current Rank</span>
      <span class="column-chip">Highest Rank</span>
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
const membersDropZone = document.getElementById('membersDropZone');
const membersFile = document.getElementById('membersFile');
const membersPreview = document.getElementById('membersPreview');
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
// Helpers
// ============================================================
function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

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
        <span>${escapeHtml(file.name)}</span>
        <span class="file-preview-clear" onclick="document.getElementById('${fileInput.id}').value=''; document.getElementById('${fileInput.id}').dispatchEvent(new Event('change'));">✕</span>
      </div>
    `;
  } else {
    previewDiv.innerHTML = '';
  }
  updateUploadBtn();
}

function updateUploadBtn() {
  uploadBtn.disabled = !membersFile.files.length;
}

setupDropZone(membersDropZone, membersFile, membersPreview);

// ============================================================
// Clear Button
// ============================================================
clearBtn.addEventListener('click', () => {
  membersFile.value = '';
  membersPreview.innerHTML = '';
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
  progressText.textContent = '⏳ Preparing file...';

  const formData = new FormData();
  if (membersFile.files.length) {
    formData.append('members', membersFile.files[0]);
  }
  if (delimiterInput.value) {
    // "t" = Tab
    const d = delimiterInput.value.toLowerCase() === 't' ? '\t' : delimiterInput.value;
    formData.append('delimiter', d);
  }

  try {
    progressText.textContent = '⏳ Uploading and processing file...';
    progressFill.style.width = '30%';

    const response = await fetch('upload_members.php', {
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
    showAlert('❌ Error: ' + escapeHtml(error.message), 'error');
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
    const m = result.members || {};
    let html = '<div class="alert alert-success">✅ Upload completed successfully!</div>';

    if (m.skipped) {
      html += `<p style="font-size:13px;"><strong>Members:</strong> ⏭️ Skipped - ${escapeHtml(m.message || 'Already imported')}</p>`;
    } else {
      html += `
        <div class="stat-grid">
          <div class="stat-box"><div class="stat-num">${m.total || 0}</div><div class="stat-lbl">Total Rows</div></div>
          <div class="stat-box ok"><div class="stat-num">${m.inserted || 0}</div><div class="stat-lbl">New Members</div></div>
          <div class="stat-box warn"><div class="stat-num">${m.updated || 0}</div><div class="stat-lbl">Updated</div></div>
          <div class="stat-box bad"><div class="stat-num">${m.failed || 0}</div><div class="stat-lbl">Failed</div></div>
        </div>`;

      if (m.errors && m.errors.length) {
        html += '<div class="error-list"><strong>Failed rows:</strong><br>' +
          m.errors.map(e => escapeHtml(e)).join('<br>') + '</div>';
      }
    }

    resultsContent.innerHTML = html;

    // Clear file input
    membersFile.value = '';
    membersPreview.innerHTML = '';
    updateUploadBtn();

  } else {
    let errors = escapeHtml(result.message || 'Upload failed');
    if (result.members && result.members.error) {
      errors += `<br><strong>Members:</strong> ${escapeHtml(result.members.error)}`;
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