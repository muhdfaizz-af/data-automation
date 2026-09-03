"""
db.py
Sama macam config/db.php - isi DB_HOST, DB_NAME, DB_USER, DB_PASS
dengan value SAMA persis yang ada dalam config/db.php punya PHP.

Guna PyMySQL (pure python, tak perlu compile driver).
Install: pip install pymysql --break-system-packages
"""

import pymysql
import pymysql.cursors

# ============================================================
# ISI SAMA MACAM config/db.php
# ============================================================
DB_HOST = "localhost"
DB_PORT = 3306
DB_NAME = "data_automation"       # <-- tukar ikut db.php punya DB_NAME
DB_USER = "root"              # <-- tukar ikut db.php punya DB_USER
DB_PASS = ""                  # <-- tukar ikut db.php punya DB_PASS
DB_CHARSET = "utf8mb4"


def get_connection():
    """
    Return satu koneksi PDO-style (pymysql Connection).
    autocommit=False sebab kita nak manage transaction sendiri
    (sama macam PDO beginTransaction/commit/rollBack dalam process.php).
    """
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset=DB_CHARSET,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )
