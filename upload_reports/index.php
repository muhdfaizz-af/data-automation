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
    
    const response = await fetch('process.php', {
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