"""
index.py
Flask app - macam upload.php (page) + process.php (endpoint upload) digabung.

Jalankan:
    pip install flask pymysql openpyxl --break-system-packages
    python index.py
Buka: http://localhost:5000/
"""

from flask import Flask, request, jsonify, render_template_string, session, redirect

from db import get_connection
from import_functions import process_file

INDEX_HTML = r'''<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload Reports — S ASIA SALES REPORT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#E0202E;--red-dark:#8E1620;--red-darker:#3B0B0F;--ink:#1B1B1F;--gray-700:#4A4A52;--gray-500:#8A8A93;--gray-300:#D8D8DE;--gray-100:#F2F2F4;--bg:#F5F5F7;--white:#FFFFFF;--radius-lg:18px;--radius-md:12px;--topbar-h:64px;--shadow-card:0 2px 8px rgba(20,20,30,.06)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',Arial,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh}
button{font-family:inherit;cursor:pointer;border:none;background:none}svg{display:block}
.topbar{position:fixed;top:0;left:0;right:0;height:var(--topbar-h);background:linear-gradient(90deg,var(--red-darker),var(--red-dark));display:flex;align-items:center;padding:0 32px;z-index:10;box-shadow:0 2px 12px rgba(0,0,0,.15)}
.brand{display:flex;align-items:center;gap:12px;color:#fff}.brand-mark{width:36px;height:36px;border-radius:10px;background:var(--red);display:grid;place-items:center;font-weight:800;font-size:15px;box-shadow:0 4px 10px rgba(0,0,0,.25)}.brand-name{font-size:15px;font-weight:800;letter-spacing:.3px}.brand-sub{font-size:11px;opacity:.7;font-weight:500}
.main{max-width:900px;margin:0 auto;padding:calc(var(--topbar-h) + 32px) 24px 48px}.page-header{margin-bottom:28px}.page-header h1{font-size:28px;font-weight:800;margin-bottom:4px}.page-header p{font-size:14px;color:var(--gray-500)}
.card{background:var(--white);border-radius:var(--radius-lg);padding:28px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);margin-bottom:24px}.card-title{font-size:16px;font-weight:800;margin-bottom:20px}.field{margin-bottom:20px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:8px;color:var(--gray-700)}.field-hint{display:block;font-size:12px;color:var(--gray-500);margin-bottom:8px}.field input[type=file],.field input[type=text]{width:100%;padding:12px 14px;border:1.5px solid var(--gray-300);border-radius:var(--radius-md);font-family:inherit;font-size:14px;color:var(--ink);background:var(--white)}.field input[type=file]{padding:10px 12px;background:var(--gray-100);cursor:pointer}.delimiter-wrap{max-width:120px}
.button-group{display:flex;gap:12px;margin-top:24px;flex-wrap:wrap}.btn{padding:12px 24px;border-radius:var(--radius-md);font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px}.btn svg{width:16px;height:16px}.btn-primary{background:var(--red);color:#fff;box-shadow:0 4px 12px rgba(224,32,46,.3)}.btn-primary:hover{background:var(--red-dark)}.btn-primary:disabled{opacity:.5;cursor:not-allowed}
.result-box{margin-top:20px;background:var(--gray-100);border-radius:var(--radius-md);padding:18px 20px;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:12.5px;line-height:1.6;color:var(--gray-700);white-space:pre-wrap;word-break:break-word;max-height:360px;overflow:auto;border:1px solid var(--gray-300)}.result-box.ok{background:#d1fae5;border-color:#6ee7b7;color:#047857}.result-box.err{background:#fee2e2;border-color:#fecaca;color:#991b1b}.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:600px){.main{padding:calc(var(--topbar-h) + 20px) 16px 40px}.topbar{padding:0 16px}}
</style>
</head>
<body>
<header class="topbar"><div class="brand"><div class="brand-mark">S</div><div><div class="brand-name">S ASIA SALES REPORT</div><div class="brand-sub">Reports Upload</div></div></div></header>
<main class="main"><div class="page-header"><h1>Upload Order Reports</h1><p>Muat naik Order History &amp; Tax Invoice untuk diproses</p></div><div class="card"><div class="card-title">Select Files</div><form id="uploadForm"><div class="field"><label for="order_history">Order History File</label><span class="field-hint">Supported: .xlsx, .csv</span><input type="file" id="order_history" name="order_history" accept=".xlsx,.csv"></div><div class="field"><label for="tax_invoice">Tax Invoice File</label><span class="field-hint">Supported: .xlsx, .csv</span><input type="file" id="tax_invoice" name="tax_invoice" accept=".xlsx,.csv"></div><div class="field delimiter-wrap"><label for="delimiter">CSV Delimiter</label><span class="field-hint">Default: <code>,</code></span><input type="text" id="delimiter" name="delimiter" value="," maxlength="1"></div><div class="button-group"><button type="submit" id="submitBtn" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Upload Files</button></div></form><pre id="result" class="result-box" style="display:none"></pre></div></main>
<script>
const apiUrl=window.location.hostname==='localhost'||window.location.hostname==='127.0.0.1'?`${window.location.origin}/process`:`${window.location.origin}/python/process`;
const form=document.getElementById('uploadForm'),resultEl=document.getElementById('result'),submitBtn=document.getElementById('submitBtn');
function setResult(text,type){resultEl.style.display='block';resultEl.className='result-box'+(type?' '+type:'');resultEl.textContent=text}
form.addEventListener('submit',async event=>{event.preventDefault();submitBtn.disabled=true;const originalHTML=submitBtn.innerHTML;submitBtn.innerHTML='<span class="spinner"></span> Uploading...';setResult('Uploading','');try{const response=await fetch(apiUrl,{method:'POST',body:new FormData(event.target),credentials:'same-origin'});const text=await response.text();let data;try{data=JSON.parse(text)}catch{throw new Error(`Server returned non-JSON response (${response.status}): ${text.slice(0,200)}`)}if(!response.ok)throw new Error(data.message||`HTTP ${response.status}`);setResult(JSON.stringify(data,null,2),'ok')}catch(error){setResult('Error: '+error.message,'err')}finally{submitBtn.disabled=false;submitBtn.innerHTML=originalHTML}})
</script>
</body></html>'''

LOGIN_HTML = r'''<!DOCTYPE html>
<html lang="ms"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login</title><style>body{font-family:Arial,sans-serif;max-width:360px;margin:80px auto;padding:20px}label,input,button{display:block;width:100%;margin:10px 0;padding:10px;box-sizing:border-box}button{cursor:pointer;background:#E0202E;color:#fff;border:0}</style></head><body><h1>Admin Login</h1>{% if error %}<p style="color:red">{{ error }}</p>{% endif %}<form method="POST" action="/login"><label>Username<input type="text" name="username" required></label><label>Password<input type="password" name="password" required></label><button type="submit">Login</button></form></body></html>'''

app = Flask(__name__)
app.secret_key = "TUKAR-KE-SECRET-KEY-SAMA-KATEGORI-DENGAN-PHP"  # wajib tukar

# Live deployment note:
# - Keep PHP as the main authenticated app.
# - Expose this service behind a reverse proxy such as /python/
# - Do not expose the Flask app directly as the public login page.


# ============================================================
# AUTH CHECK
# ============================================================
# PHP punya file check $_SESSION['admin_id']. Flask session ini
# BERASINGAN daripada PHP session (mekanisme lain), jadi login
# di sini tak automatik "sambung" dengan login PHP.
#
# Kalau nak share login status dengan sistem PHP sedia ada,
# ada 2 cara biasa:
#   1) Semak terus dalam DB - contohnya table `admin_sessions`
#      atau `admins` + cookie token yang PHP letak.
#   2) Buat endpoint kecil dalam PHP untuk "verify session",
#      dan Python call endpoint tu untuk sahkan login.
#
# Buat masa ini, function ini hanya check Flask session sendiri
# (login page ringkas kena dibuat berasingan, atau sambung ikut
# cara sistem PHP korang simpan session).
def is_logged_in():
    return session.get("admin_id") is not None


@app.route("/")
@app.route("/python/")
def upload_page():
    if not is_logged_in():
        return redirect("/login")
    return render_template_string(INDEX_HTML)


@app.route("/process", methods=["POST"])
@app.route("/python/process", methods=["POST"])
def process():
    if not is_logged_in():
        return jsonify({"status": "error", "message": "Not authenticated"}), 401

    delimiter = (request.form.get("delimiter") or ",")[:1] or ","
    results = {"status": "success"}

    conn = get_connection()
    try:
        for field in ("order_history", "tax_invoice"):
            file = request.files.get(field)
            if not file or file.filename == "":
                continue
            try:
                file_bytes = file.read()
                results[field] = process_file(file_bytes, file.filename, conn, delimiter)
            except Exception as e:
                results[field] = {"error": str(e)}
                results["status"] = "error"
    finally:
        conn.close()

    return jsonify(results)


# ------------------------------------------------------------
# Contoh login ringkas (TUKAR ikut logic login sedia ada korang -
# ideal-nya check terhadap table `admins` yang sama dengan PHP guna)
# ------------------------------------------------------------
@app.route("/login", methods=["GET", "POST"])
def login():
    if request.method == "GET":
        return render_template_string(LOGIN_HTML)

    username = request.form.get("username")
    password = request.form.get("password")

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, username, password FROM admin_users WHERE username=%s LIMIT 1",
                (username,),
            )
            admin = cur.fetchone()
    finally:
        conn.close()

    # PHP app uses password_verify() with bcrypt hashes stored in `admin_users.password`
    import bcrypt
    if admin and bcrypt.checkpw(password.encode(), admin["password"].encode()):
        session["admin_id"] = admin["id"]
        session["admin_username"] = admin["username"]
        return redirect("/")

    return render_template_string(LOGIN_HTML, error="Login gagal.")


@app.route("/logout")
def logout():
    session.clear()
    return redirect("/login")


if __name__ == "__main__":
    app.run(host="0.0.0.0", debug=True, port=5000)
