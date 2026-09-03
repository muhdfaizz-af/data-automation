"""
index.py
Flask app - macam upload.php (page) + process.php (endpoint upload) digabung.

Jalankan:
    pip install flask pymysql openpyxl --break-system-packages
    python index.py
Buka: http://localhost:5000/
"""

from flask import Flask, request, jsonify, render_template, session, redirect

from db import get_connection
from import_functions import process_file

app = Flask(__name__, template_folder=".")
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
    return render_template("index.html")


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
        return render_template("login.html")

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

    return render_template("login.html", error="Login gagal.")


@app.route("/logout")
def logout():
    session.clear()
    return redirect("/login")


if __name__ == "__main__":
    app.run(host="0.0.0.0", debug=True, port=5000)
