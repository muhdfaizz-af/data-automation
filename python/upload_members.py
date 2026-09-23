from datetime import date, datetime
from io import BytesIO
import os

import openpyxl
import pymysql
from flask import Flask, jsonify, request

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 20 * 1024 * 1024  # 20 MB

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "localhost"),
    "port": int(os.getenv("DB_PORT", "3306")),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASS", ""),
    "database": os.getenv("DB_NAME", "data_automation"),
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
    "autocommit": False,
    # Supaya tak hang selamanya kalau DB tak boleh dicapai / ada lock
    "connect_timeout": 10,
    "read_timeout": 120,
    "write_timeout": 120,
}

# NOTA: PAGE dihantar terus (bukan render_template_string) supaya
# tiada masalah dengan simbol { } dalam CSS/JS.
PAGE = r"""<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload Members — S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ============================================================
   1. DESIGN TOKENS
   ============================================================ */
:root {
  --red: #E0202E;
  --red-dark: #8E1620;
  --red-darker: #3B0B0F;
  --ink: #1B1B1F;
  --gray-700: #4A4A52;
  --gray-500: #8A8A93;
  --gray-300: #D8D8DE;
  --gray-100: #F2F2F4;
  --bg: #F5F5F7;
  --white: #FFFFFF;
  --gold: #F5A623;
  --green: #10B981;
  --radius-lg: 18px;
  --radius-md: 12px;
  --radius-sm: 8px;
  --topbar-h: 64px;
  --shadow-card: 0 2px 8px rgba(20,20,30,.06);
}

/* ============================================================
   2. RESET
   ============================================================ */
*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: 'Plus Jakarta Sans', Arial, sans-serif;
  background: var(--bg);
  color: var(--ink);
  min-height: 100vh;
}

button {
  font-family: inherit;
  cursor: pointer;
  border: none;
  background: none;
}

svg { display: block; }

/* ============================================================
   3. TOPBAR
   ============================================================ */
.topbar {
  position: fixed;
  top: 0; left: 0; right: 0;
  height: var(--topbar-h);
  background: linear-gradient(90deg, var(--red-darker), var(--red-dark));
  display: flex;
  align-items: center;
  padding: 0 32px;
  z-index: 10;
  box-shadow: 0 2px 12px rgba(0,0,0,.15);
}

.brand { display: flex; align-items: center; gap: 12px; color: #fff; }

.brand-mark {
  width: 36px; height: 36px;
  border-radius: 10px;
  background: var(--red);
  display: grid;
  place-items: center;
  font-weight: 800;
  font-size: 15px;
  box-shadow: 0 4px 10px rgba(0,0,0,.25);
}

.brand-name { font-size: 15px; font-weight: 800; letter-spacing: .3px; }
.brand-sub  { font-size: 11px; opacity: .7; font-weight: 500; }

/* ============================================================
   4. LAYOUT
   ============================================================ */
.main {
  max-width: 900px;
  margin: 0 auto;
  padding: calc(var(--topbar-h) + 32px) 24px 48px;
}

@media (max-width: 600px) {
  .main { padding: calc(var(--topbar-h) + 20px) 16px 40px; }
  .topbar { padding: 0 16px; }
}

.page-header { margin-bottom: 28px; }
.page-header h1 { font-size: 28px; font-weight: 800; margin-bottom: 4px; }
.page-header p  { font-size: 14px; color: var(--gray-500); }

/* ============================================================
   5. CARD
   ============================================================ */
.card {
  background: var(--white);
  border-radius: var(--radius-lg);
  padding: 28px;
  box-shadow: var(--shadow-card);
  border: 1px solid var(--gray-100);
  margin-bottom: 24px;
}

.card-title { font-size: 16px; font-weight: 800; margin-bottom: 20px; }

/* ============================================================
   6. UPLOAD
   ============================================================ */
.upload-label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; color: var(--gray-700); }
.upload-hint  { display: block; font-size: 12px; color: var(--gray-500); margin-bottom: 12px; }

.upload-dropzone {
  border: 2px dashed var(--gray-300);
  border-radius: var(--radius-md);
  padding: 36px 20px;
  text-align: center;
  cursor: pointer;
  transition: all .2s;
  background: var(--gray-100);
}

.upload-dropzone:hover,
.upload-dropzone.dragover {
  border-color: var(--red);
  background: #fff0f1;
}

.upload-dropzone svg {
  width: 40px; height: 40px;
  margin: 0 auto 12px;
  stroke: var(--red);
}

.upload-dropzone-text { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
.upload-dropzone-sub  { font-size: 12px; color: var(--gray-500); }
.upload-input { display: none; }

.file-preview {
  margin-top: 12px;
  padding: 12px 14px;
  background: var(--gray-100);
  border-radius: var(--radius-sm);
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.file-preview svg { width: 18px; height: 18px; stroke: var(--green); flex-shrink: 0; }
.file-preview .fname { font-weight: 600; word-break: break-all; }
.file-preview .fsize { color: var(--gray-500); font-size: 12px; }
.file-preview-clear { margin-left: auto; cursor: pointer; color: var(--red); font-weight: 700; padding: 0 4px; }

/* ============================================================
   7. BUTTONS
   ============================================================ */
.button-group { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }

.btn {
  padding: 12px 24px;
  border-radius: var(--radius-md);
  font-size: 14px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all .15s;
}

.btn svg { width: 16px; height: 16px; }

.btn-primary {
  background: var(--red);
  color: #fff;
  box-shadow: 0 4px 12px rgba(224,32,46,.3);
}
.btn-primary:hover { background: var(--red-dark); }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }

.btn-secondary {
  background: transparent;
  border: 1.5px solid var(--gray-300);
  color: var(--ink);
}
.btn-secondary:hover { background: var(--gray-100); }

/* ============================================================
   8. PROGRESS
   ============================================================ */
.progress-text { font-size: 12px; color: var(--gray-500); font-weight: 600; }

.progress-bar {
  width: 100%;
  height: 6px;
  background: var(--gray-100);
  border-radius: 3px;
  overflow: hidden;
  margin: 12px 0;
  position: relative;
}

.progress-fill {
  height: 100%;
  width: 40%;
  background: var(--red);
  border-radius: 3px;
  position: absolute;
  animation: slide 1.2s ease-in-out infinite;
}

@keyframes slide {
  0%   { left: -40%; }
  100% { left: 100%; }
}

/* ============================================================
   9. ALERTS
   ============================================================ */
.alert {
  padding: 14px 16px;
  border-radius: var(--radius-md);
  margin-bottom: 16px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.6;
}

.alert-success { background: #d1fae5; border: 1px solid #6ee7b7; color: #047857; }
.alert-error   { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

/* ============================================================
   10. COLUMN CHIPS
   ============================================================ */
.columns-list { display: flex; flex-wrap: wrap; gap: 8px; }

.column-chip {
  padding: 6px 12px;
  background: var(--gray-100);
  border: 1px solid var(--gray-300);
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  color: var(--gray-700);
}

.column-chip.required {
  background: #fff0f1;
  border-color: var(--red);
  color: var(--red-dark);
}

/* ============================================================
   11. RESULTS
   ============================================================ */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-top: 4px;
}

.stat-box {
  background: var(--gray-100);
  border-radius: var(--radius-md);
  padding: 16px;
}

.stat-num { font-size: 26px; font-weight: 800; }
.stat-lbl { font-size: 12px; color: var(--gray-500); font-weight: 600; margin-top: 2px; }

.stat-box.ok   .stat-num { color: var(--green); }
.stat-box.warn .stat-num { color: var(--gold); }
.stat-box.bad  .stat-num { color: var(--red); }

.error-list {
  margin-top: 16px;
  font-size: 12px;
  color: var(--gray-700);
  max-height: 200px;
  overflow: auto;
  background: var(--gray-100);
  border-radius: var(--radius-sm);
  padding: 12px 14px;
  line-height: 1.8;
}

@media (max-width: 600px) {
  .stat-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>

<header class="topbar">
  <div class="brand">
    <div class="brand-mark">S</div>
    <div>
      <div class="brand-name">S ASIA SALES REPORT</div>
      <div class="brand-sub">Data Upload</div>
    </div>
  </div>
</header>

<main class="main">
  <div class="page-header">
    <h1>Upload Members</h1>
    <p>Import Member List data from an Excel file</p>
  </div>

  <div id="statusContainer"></div>

  <!-- UPLOAD FORM -->
  <div class="card">
    <div class="card-title">📁 Select File</div>

    <label class="upload-label">Member List File</label>
    <span class="upload-hint">Supported: .xlsx</span>

    <div class="upload-dropzone" id="dropZone">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="17 8 12 3 7 8"/>
        <line x1="12" y1="3" x2="12" y2="15"/>
      </svg>
      <div class="upload-dropzone-text">Drag file here or click</div>
      <div class="upload-dropzone-sub">Choose your Member List Excel file</div>
    </div>
    <div id="preview"></div>
    <input type="file" id="file" class="upload-input" accept=".xlsx">

    <div class="button-group">
      <button id="uploadBtn" class="btn btn-primary" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        Upload File
      </button>
      <button id="clearBtn" class="btn btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
          <line x1="10" y1="11" x2="10" y2="17"/>
          <line x1="14" y1="11" x2="14" y2="17"/>
        </svg>
        Clear
      </button>
    </div>

    <div id="progressSection" style="display:none;margin-top:24px;">
      <div id="progressText" class="progress-text"></div>
      <div class="progress-bar"><div class="progress-fill"></div></div>
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

  <!-- RESULTS -->
  <div id="resultsCard" class="card" style="display:none;">
    <div class="card-title">📊 Upload Results</div>
    <div id="resultsContent"></div>
  </div>
</main>

<script>
const dropZone        = document.getElementById('dropZone');
const fileInput       = document.getElementById('file');
const preview         = document.getElementById('preview');
const uploadBtn       = document.getElementById('uploadBtn');
const clearBtn        = document.getElementById('clearBtn');
const statusContainer = document.getElementById('statusContainer');
const progressSection = document.getElementById('progressSection');
const progressText    = document.getElementById('progressText');
const resultsCard     = document.getElementById('resultsCard');
const resultsContent  = document.getElementById('resultsContent');

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, c => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  }[c]));
}

function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1048576).toFixed(1) + ' MB';
}

function showAlert(message, type) {
  statusContainer.innerHTML = '<div class="alert alert-' + type + '">' + message + '</div>';
}

function updateUploadBtn() {
  uploadBtn.disabled = !fileInput.files.length;
}

function showPreview() {
  if (fileInput.files.length > 0) {
    const f = fileInput.files[0];
    preview.innerHTML =
      '<div class="file-preview">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
          '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>' +
        '</svg>' +
        '<span class="fname">' + escapeHtml(f.name) + '</span>' +
        '<span class="fsize">' + formatSize(f.size) + '</span>' +
        '<span class="file-preview-clear" id="removeFile" title="Remove">✕</span>' +
      '</div>';
    document.getElementById('removeFile').addEventListener('click', clearFile);
  } else {
    preview.innerHTML = '';
  }
  updateUploadBtn();
}

function clearFile() {
  fileInput.value = '';
  showPreview();
}

function setFile(files) {
  if (!files.length) return;
  if (!files[0].name.toLowerCase().endsWith('.xlsx')) {
    showAlert('❌ Only .xlsx files are supported.', 'error');
    return;
  }
  statusContainer.innerHTML = '';
  fileInput.files = files;
  showPreview();
}

dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('dragover');
  setFile(e.dataTransfer.files);
});
fileInput.addEventListener('change', () => setFile(fileInput.files));

clearBtn.addEventListener('click', () => {
  clearFile();
  statusContainer.innerHTML = '';
  resultsCard.style.display = 'none';
  progressSection.style.display = 'none';
});

function showResults(result) {
  if (result.status === 'success') {
    const m = result.members;
    let html = '<div class="alert alert-success">✅ Upload completed successfully!</div>' +
      '<div class="stat-grid">' +
        '<div class="stat-box"><div class="stat-num">' + m.total + '</div><div class="stat-lbl">Total Rows</div></div>' +
        '<div class="stat-box ok"><div class="stat-num">' + m.inserted + '</div><div class="stat-lbl">New Members</div></div>' +
        '<div class="stat-box warn"><div class="stat-num">' + m.updated + '</div><div class="stat-lbl">Updated</div></div>' +
        '<div class="stat-box bad"><div class="stat-num">' + m.failed + '</div><div class="stat-lbl">Failed</div></div>' +
      '</div>';
    if (m.errors && m.errors.length) {
      html += '<div class="error-list"><strong>Failed rows:</strong><br>' +
        m.errors.map(escapeHtml).join('<br>') + '</div>';
    }
    resultsContent.innerHTML = html;
    clearFile();
  } else {
    resultsContent.innerHTML = '<div class="alert alert-error">❌ ' + escapeHtml(result.message || 'Upload failed') + '</div>';
  }
  resultsCard.style.display = 'block';
}

uploadBtn.addEventListener('click', async () => {
  uploadBtn.disabled = true;
  statusContainer.innerHTML = '';
  resultsCard.style.display = 'none';
  progressSection.style.display = 'block';
  progressText.textContent = '⏳ Uploading and processing file...';

  const started = Date.now();
  const ticker = setInterval(() => {
    progressText.textContent = '⏳ Processing... ' + Math.round((Date.now() - started) / 1000) + 's';
  }, 1000);

  const data = new FormData();
  data.append('members', fileInput.files[0]);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 180000);

  try {
    const response = await fetch('/upload-members', { method: 'POST', body: data, signal: controller.signal });
    const raw = await response.text();
    let result;
    try { result = JSON.parse(raw); }
    catch (e) { throw new Error('Server error ' + response.status + ': ' + raw.substring(0, 200)); }

    if (!response.ok || result.status !== 'success') {
      progressSection.style.display = 'none';
      showResults({ status: 'error', message: result.message });
    } else {
      progressSection.style.display = 'none';
      showResults(result);
    }
  } catch (error) {
    progressSection.style.display = 'none';
    showAlert('❌ ' + escapeHtml(error.name === 'AbortError'
      ? 'Request timed out (3 minit). Semak terminal Flask.'
      : error.message), 'error');
  } finally {
    clearInterval(ticker);
    clearTimeout(timeout);
    updateUploadBtn();
  }
});

updateUploadBtn();
</script>
</body>
</html>
"""

NULL_TEXT = {"", "null", "none", "nan"}


def normalize_header(value):
    return "".join(ch for ch in str(value or "").strip().lower() if ch.isalnum())


def get(row, headers, names):
    """Ambil raw value dari row berdasarkan senarai nama header."""
    for name in names:
        index = headers.get(normalize_header(name))
        if index is not None and index < len(row):
            return row[index]
    return None


def clean_text(value):
    """None / '' / 'null' -> None. Float bulat (8681955.0) -> '8681955'."""
    if value is None:
        return None
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    text = str(value).strip()
    return None if text.lower() in NULL_TEXT else text


def parse_date(value, with_time=True):
    if value is None:
        return None
    out_fmt = "%Y-%m-%d %H:%M:%S" if with_time else "%Y-%m-%d"
    if isinstance(value, (datetime, date)):
        return value.strftime(out_fmt)
    text = clean_text(value)
    if not text or text.startswith("0000-00-00"):
        return None
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d", "%d/%m/%Y", "%d/%m/%Y %H:%M:%S"):
        try:
            return datetime.strptime(text, fmt).strftime(out_fmt)
        except ValueError:
            continue
    return None


def parse_amount(value):
    text = clean_text(value)
    if not text:
        return None
    text = text.upper()
    for token in ("RM", "SGD", "USD", ",", " "):
        text = text.replace(token, "")
    try:
        return float(text)
    except ValueError:
        return None


def read_rows(file_bytes):
    workbook = openpyxl.load_workbook(BytesIO(file_bytes), read_only=True, data_only=True)
    sheet = workbook.active
    # Penting: abaikan saiz sheet yang salah (contoh A1:T1048576) dalam fail export
    sheet.reset_dimensions()
    rows = []
    for row in sheet.iter_rows(values_only=True):
        if any(clean_text(v) is not None for v in row):  # skip row kosong
            rows.append(row)
    workbook.close()
    return rows


def import_members(file_bytes, filename):
    if not filename.lower().endswith(".xlsx"):
        raise ValueError("Only .xlsx files are supported.")

    rows = read_rows(file_bytes)
    if len(rows) < 2:
        raise ValueError("The Excel file is empty.")

    headers = {normalize_header(v): i for i, v in enumerate(rows[0])}
    if "company" not in headers or "memberid" not in headers:
        raise ValueError("Required columns missing: Company and Member ID.")

    data_rows = rows[1:]
    print(f"[members] {filename}: {len(data_rows)} data rows", flush=True)

    connection = pymysql.connect(**DB_CONFIG)
    try:
        with connection.cursor() as cursor:
            company_cache = {}

            def company_id(company):
                key = (company or "").upper()
                if not key:
                    return None
                if key not in company_cache:
                    cursor.execute(
                        "SELECT id FROM companies WHERE UPPER(company_code)=%s OR UPPER(company_name)=%s LIMIT 1",
                        (key, key),
                    )
                    found = cursor.fetchone()
                    company_cache[key] = found["id"] if found else None
                return company_cache[key]

            first_company = company_id(clean_text(get(data_rows[0], headers, ["Company"])))
            if not first_company:
                raise ValueError("Company code was not found in the companies table.")

            cursor.execute(
                "INSERT INTO import_batches (company_id,file_type,original_filename,total_rows,status) "
                "VALUES (%s,'MEMBERS',%s,%s,'processing')",
                (first_company, filename, len(data_rows)),
            )
            batch_id = cursor.lastrowid
            inserted = updated = failed = 0
            errors = []

            sql = """
                INSERT INTO members (
                    company_id, import_batch_id, member_code, member_name, nric, mobile_no,
                    email, joined_date, sponsor_code, sponsor_name, status, cl_code, cl_name,
                    occupation, date_of_birth, source_of_funds, estimated_monthly_income,
                    gender, marital_status, current_rank, highest_rank
                ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                ON DUPLICATE KEY UPDATE
                    import_batch_id=VALUES(import_batch_id), member_name=VALUES(member_name),
                    nric=VALUES(nric), mobile_no=VALUES(mobile_no), email=VALUES(email),
                    joined_date=VALUES(joined_date), sponsor_code=VALUES(sponsor_code),
                    sponsor_name=VALUES(sponsor_name), status=VALUES(status), cl_code=VALUES(cl_code),
                    cl_name=VALUES(cl_name), occupation=VALUES(occupation), date_of_birth=VALUES(date_of_birth),
                    source_of_funds=VALUES(source_of_funds), estimated_monthly_income=VALUES(estimated_monthly_income),
                    gender=VALUES(gender), marital_status=VALUES(marital_status),
                    current_rank=VALUES(current_rank), highest_rank=VALUES(highest_rank)
            """

            for line, row in enumerate(data_rows, start=2):
                code = clean_text(get(row, headers, ["Member ID", "Member Code"]))
                current_company = company_id(clean_text(get(row, headers, ["Company"])))
                if not code or not current_company:
                    failed += 1
                    errors.append(f"Row {line}: valid Company and Member ID are required")
                    continue
                try:
                    cursor.execute(sql, (
                        current_company, batch_id, code,
                        clean_text(get(row, headers, ["Name as per IC", "Name"])),
                        clean_text(get(row, headers, ["NRIC"])),
                        clean_text(get(row, headers, ["Mobile No", "Mobile"])),
                        clean_text(get(row, headers, ["Email"])),
                        parse_date(get(row, headers, ["Joined Date"])),
                        clean_text(get(row, headers, ["Sponsor ID", "Sponsor Code"])),
                        clean_text(get(row, headers, ["Sponsor Name"])),
                        clean_text(get(row, headers, ["Status"])),
                        clean_text(get(row, headers, ["CL Code"])),
                        clean_text(get(row, headers, ["CL Name"])),
                        clean_text(get(row, headers, ["Occupation"])),
                        parse_date(get(row, headers, ["Date of Birth"]), with_time=False),
                        clean_text(get(row, headers, ["Source of Funds"])),
                        parse_amount(get(row, headers, ["Estimated Monthly Income"])),
                        clean_text(get(row, headers, ["Gender"])),
                        clean_text(get(row, headers, ["Marital Status"])),
                        clean_text(get(row, headers, ["Current Rank"])),
                        clean_text(get(row, headers, ["Highest Rank"])),
                    ))
                    # MySQL: rowcount 1 = insert baru, 2 = update, 0 = tiada perubahan
                    if cursor.rowcount == 1:
                        inserted += 1
                    else:
                        updated += 1
                except Exception as error:
                    failed += 1
                    errors.append(f"Row {line}: {error}")

                if (line - 1) % 500 == 0:
                    print(f"[members] processed {line - 1}/{len(data_rows)}", flush=True)

            cursor.execute(
                "UPDATE import_batches SET successful_rows=%s, failed_rows=%s, status=%s, imported_at=NOW() WHERE id=%s",
                (inserted + updated, failed, "completed_with_errors" if failed else "completed", batch_id),
            )
        connection.commit()
        print(f"[members] done: +{inserted} ~{updated} x{failed}", flush=True)
        return {
            "total": len(data_rows),
            "inserted": inserted,
            "updated": updated,
            "failed": failed,
            "errors": errors[:100],
        }
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


@app.get("/")
def page():
    return PAGE


@app.post("/upload-members")
def upload_members():
    uploaded = request.files.get("members")
    if not uploaded or not uploaded.filename:
        return jsonify({"status": "error", "message": "Please choose an Excel file."}), 400
    try:
        return jsonify({"status": "success", "members": import_members(uploaded.read(), uploaded.filename)})
    except Exception as error:
        print(f"[members] ERROR: {error}", flush=True)
        return jsonify({"status": "error", "message": str(error)}), 500


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5001, debug=False)