<?php
/**
 * S ASIA SALES REPORT - Members Upload Interface (FAST VERSION)
 *
 * Perubahan utama:
 *  1. Baca file secara STREAMING (OpenSpout utk xlsx, fgetcsv utk csv) - memori rendah, tak load 500k row sekali gus.
 *  2. Company di-cache sekali (bukan 1 query setiap row).
 *  3. Batch INSERT ... ON DUPLICATE KEY UPDATE (1000 row / query) dalam 1 transaction.
 *  4. Proses jalan di BACKGROUND (response ditutup awal) + browser poll progress sebenar.
 *  5. session_write_close() supaya polling tak kena block oleh session lock.
 *
 * Install sekali: composer require openspout/openspout
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

const IMPORT_CHUNK = 1000; // bilangan row per query

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
// PENTING: lepaskan session lock supaya request progress boleh jalan serentak
session_write_close();

/* ============================================================
 * Helpers
 * ============================================================ */
const FIELD_ALIASES = [
    'member_code'              => ['memberid', 'membercode', 'memberno', 'id'],
    'member_name'              => ['nameasperic', 'name', 'fullname'],
    'nric'                     => ['nric', 'ic', 'icno'],
    'mobile_no'                => ['mobileno', 'mobile', 'phoneno'],
    'email'                    => ['email', 'emailaddress'],
    'joined_date'              => ['joineddate', 'joindate', 'registrationdate'],
    'sponsor_code'             => ['sponsorid', 'sponsorcode'],
    'sponsor_name'             => ['sponsorname'],
    'status'                   => ['status'],
    'cl_code'                  => ['clcode'],
    'cl_name'                  => ['clname'],
    'occupation'               => ['occupation'],
    'date_of_birth'            => ['dateofbirth', 'dob', 'birthdate'],
    'source_of_funds'          => ['sourceoffunds'],
    'estimated_monthly_income' => ['estimatedmonthlyincome', 'monthlyincome'],
    'gender'                   => ['gender', 'sex'],
    'marital_status'           => ['maritalstatus'],
    'current_rank'             => ['currentrank', 'rank'],
    'highest_rank'             => ['highestrank'],
];

function memberHeader($value) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$value)));
}

function memberCell($v) {
    if ($v === null || is_bool($v)) return '';
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
    if (is_float($v)) return floor($v) == $v ? sprintf('%.0f', $v) : (string)$v;
    return (string)$v;
}

function memberDate($value) {
    $value = trim((string)$value);
    if ($value === '' || in_array($value, ['0000-00-00', '0000-00-00 00:00:00'], true)) return null;
    if (is_numeric($value) && $value > 20000 && $value < 80000) return gmdate('Y-m-d', (int)(($value - 25569) * 86400));
    // Format Malaysia: d/m/Y atau d-m-Y (strtotime salah baca sebagai m/d/Y)
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})(?:\s.*)?$#', $value, $m)) {
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    }
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d', $ts);
}

function memberAmount($value) {
    if ($value === '') return null;
    $value = str_replace(['RM', 'SGD', ',', ' ', '"'], '', strtoupper($value));
    return is_numeric($value) ? (float)$value : null;
}

function dbConnect(): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

/** Cache semua company sekali: CODE / NAME => id */
function companyMap(PDO $pdo): array {
    $map = [];
    foreach ($pdo->query('SELECT id, company_code, company_name FROM companies') as $r) {
        foreach ([$r['company_code'], $r['company_name']] as $k) {
            $k = strtoupper(trim((string)$k));
            if ($k !== '' && !isset($map[$k])) $map[$k] = (int)$r['id'];
        }
    }
    return $map;
}

function companyCodeOf(string $memberId): string {
    return strtoupper(substr($memberId, 0, 2)) === 'SG' ? 'SG' : 'MY';
}

/** Generator: yield [lineNo => cells[]] secara streaming */
function memberRows(string $path, string $ext, string $delimiter): Generator {
    if ($ext === 'xlsx') {
        if (!class_exists(\OpenSpout\Reader\XLSX\Reader::class)) {
            throw new RuntimeException('Library OpenSpout belum diinstall. Run: composer require openspout/openspout');
        }
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    yield $line => array_map('memberCell', $row->toArray());
                }
                break; // sheet pertama sahaja
            }
        } finally {
            $reader->close();
        }
    } else {
        $fh = fopen($path, 'rb');
        if (!$fh) throw new RuntimeException('Cannot open uploaded file.');
        try {
            if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh); // buang BOM
            $line = 0;
            while (($r = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
                $line++;
                if ($r === [null]) continue;
                yield $line => $r;
            }
        } finally {
            fclose($fh);
        }
    }
}

/** Anggaran jumlah row (utk progress bar sahaja, murah) */
function estimateRows(string $path, string $ext): int {
    if ($ext === 'xlsx') {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return 0;
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
                $s = $zip->getStream($name);
                $head = $s ? fread($s, 8192) : '';
                if ($s) fclose($s);
                if (preg_match('/<dimension ref="[A-Z]+\d+:[A-Z]+(\d+)"/', $head, $m)) $total = max(0, (int)$m[1] - 1);
                break;
            }
        }
        $zip->close();
        return $total;
    }
    $fh = fopen($path, 'rb');
    if (!$fh) return 0;
    $n = 0;
    while (!feof($fh)) { $buf = fread($fh, 1048576); $n += substr_count($buf, "\n"); }
    fclose($fh);
    return max(0, $n - 1);
}

function buildColumnMap(array $headerRow): array {
    $idx = [];
    foreach ($headerRow as $i => $h) {
        $k = memberHeader($h);
        if ($k !== '' && !isset($idx[$k])) $idx[$k] = $i;
    }
    $map = [];
    foreach (FIELD_ALIASES as $field => $aliases) {
        $map[$field] = null;
        foreach ($aliases as $a) {
            if (isset($idx[$a])) { $map[$field] = $idx[$a]; break; }
        }
    }
    return $map;
}

/** Return array params, atau string (mesej error) */
function parseMemberRow(array $row, array $map, array $companies, int $batchId) {
    $get = fn($f) => $map[$f] === null ? '' : trim((string)($row[$map[$f]] ?? ''));
    $memberId = $get('member_code');
    if ($memberId === '') return 'Member ID is required.';
    $companyId = $companies[companyCodeOf($memberId)] ?? null;
    if (!$companyId) return 'Company not found for this Member ID.';

    $p = [$companyId, $batchId];
    foreach (array_keys(FIELD_ALIASES) as $f) {
        $v = $get($f);
        $p[] = match ($f) {
            'joined_date', 'date_of_birth' => memberDate($v),
            'estimated_monthly_income'     => memberAmount($v),
            default                        => $v,
        };
    }
    return $p;
}

function upsertStatement(PDO $pdo, int $n): PDOStatement {
    static $cache = [];
    if (isset($cache[$n])) return $cache[$n];
    $cols = array_merge(['company_id', 'import_batch_id'], array_keys(FIELD_ALIASES));
    $one  = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $upd  = [];
    foreach ($cols as $c) if ($c !== 'member_code') $upd[] = "$c = VALUES($c)";
    $sql = 'INSERT INTO members (' . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, $n, $one))
         . ' ON DUPLICATE KEY UPDATE ' . implode(',', $upd);
    return $cache[$n] = $pdo->prepare($sql);
}

/* ---------- progress file ---------- */
function progressPath(int $batchId): string {
    return sys_get_temp_dir() . '/sasia_member_import_' . $batchId . '.json';
}
function writeProgress(int $batchId, array $p): void {
    $p['updated_at'] = time();
    @file_put_contents(progressPath($batchId), json_encode($p), LOCK_EX);
}

/* ---------- core import ---------- */
function runImport(PDO $pdo, int $batchId, string $path, string $ext, string $delim, array $companies, array $progress): void {
    $gen = memberRows($path, $ext, $delim);
    $gen->rewind();
    $map = buildColumnMap($gen->current());
    $gen->next();

    $before = (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();

    $buf = []; $lines = [];
    $total = 0; $ok = 0; $failed = 0; $errors = [];
    $lastWrite = microtime(true);

    $flush = function () use (&$buf, &$lines, &$ok, &$failed, &$errors, $pdo) {
        $n = count($buf);
        if ($n === 0) return;
        try {
            $pdo->beginTransaction();
            upsertStatement($pdo, $n)->execute(array_merge(...$buf));
            $pdo->commit();
            $ok += $n;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Fallback: cari row yang bermasalah satu-satu
            $pdo->beginTransaction();
            $stmt = upsertStatement($pdo, 1);
            foreach ($buf as $k => $params) {
                try { $stmt->execute($params); $ok++; }
                catch (Throwable $e2) {
                    $failed++;
                    if (count($errors) < 100) $errors[] = "Row {$lines[$k]}: " . $e2->getMessage();
                }
            }
            $pdo->commit();
        }
        $buf = []; $lines = [];
    };

    for (; $gen->valid(); $gen->next()) {
        $row = $gen->current();
        if (trim(implode('', $row)) === '') continue; // skip row kosong
        $total++;
        $p = parseMemberRow($row, $map, $companies, $batchId);
        if (is_string($p)) {
            $failed++;
            if (count($errors) < 100) $errors[] = 'Row ' . $gen->key() . ': ' . $p;
            continue;
        }
        $buf[] = $p; $lines[] = $gen->key();
        if (count($buf) >= IMPORT_CHUNK) {
            $flush();
            if (microtime(true) - $lastWrite >= 0.5) {
                $progress['processed'] = $total; $progress['ok'] = $ok; $progress['failed'] = $failed;
                writeProgress($batchId, $progress);
                $lastWrite = microtime(true);
            }
        }
    }
    $flush();

    $after    = (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
    $inserted = max(0, $after - $before);
    $updated  = max(0, $ok - $inserted);
    $status   = $failed ? 'completed_with_errors' : 'completed';

    $pdo->prepare('UPDATE import_batches SET total_rows = :t, successful_rows = :s, failed_rows = :f, status = :st, imported_at = NOW() WHERE id = :id')
        ->execute(['t' => $total, 's' => $ok, 'f' => $failed, 'st' => $status, 'id' => $batchId]);

    $progress = array_merge($progress, [
        'status' => $status, 'total' => $total, 'processed' => $total,
        'ok' => $ok, 'inserted' => $inserted, 'updated' => $updated,
        'failed' => $failed, 'errors' => $errors,
    ]);
    writeProgress($batchId, $progress);
}

/* ============================================================
 * GET ?progress=ID  -> status semasa
 * ============================================================ */
if (isset($_GET['progress'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $file = progressPath((int)$_GET['progress']);
    if (!is_file($file)) { echo json_encode(['status' => 'unknown']); exit; }
    $p = json_decode((string)file_get_contents($file), true) ?: ['status' => 'unknown'];
    $p['stale'] = $p['status'] === 'processing' && (time() - ($p['updated_at'] ?? time())) > 180;
    echo json_encode($p);
    exit;
}

/* ============================================================
 * POST -> terima file, validate, balas segera, proses di background
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(0);
    ini_set('memory_limit', '512M');
    ignore_user_abort(true);

    if (!isset($_FILES['members']) || $_FILES['members']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please select a member list file (check upload_max_filesize / post_max_size if file is large).']);
        exit;
    }

    require_once __DIR__ . '/../vendor/autoload.php';

    $stored = null;
    $batchId = 0;
    try {
        $file = $_FILES['members'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv', 'txt', 'tsv'], true)) throw new RuntimeException('Only .xlsx, .csv, .txt and .tsv files are supported.');

        $delimiter = (string)($_POST['delimiter'] ?? ',');
        $delimiter = ($delimiter === "\t" || strtolower($delimiter) === 't') ? "\t" : ($delimiter !== '' ? $delimiter[0] : ',');
        if ($ext === 'tsv') $delimiter = "\t";

        $hash = hash_file('sha256', $file['tmp_name']);
        $pdo  = dbConnect();

        $dup = $pdo->prepare('SELECT id, status FROM import_batches WHERE file_hash = :hash LIMIT 1');
        $dup->execute(['hash' => $hash]);
        $existing = $dup->fetch();
        if ($existing && $existing['status'] === 'completed') {
            echo json_encode(['status' => 'success', 'members' => ['skipped' => true, 'message' => 'File already imported.']]);
            exit;
        }

        // Simpan file ke temp supaya selamat walaupun request dah ditutup
        $stored = sys_get_temp_dir() . '/sasia_members_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $stored)) throw new RuntimeException('Failed to store uploaded file.');

        // Validate header + row pertama (cepat, baca 2 row sahaja)
        $peek = memberRows($stored, $ext, $delimiter);
        $peek->rewind();
        if (!$peek->valid()) throw new RuntimeException('File is empty.');
        $header = $peek->current();
        $peek->next();
        if (!$peek->valid()) throw new RuntimeException('File is empty.');
        $firstRow = $peek->current();
        unset($peek);

        $map = buildColumnMap($header);
        if ($map['member_code'] === null) throw new RuntimeException('Required columns missing. Header must include Member ID.');

        $companies = companyMap($pdo);
        $firstId   = trim((string)($firstRow[$map['member_code']] ?? ''));
        $companyId = $companies[companyCodeOf($firstId)] ?? null;
        if (!$companyId) throw new RuntimeException('Company code was not found in the companies table.');

        if ($existing) $pdo->prepare('UPDATE import_batches SET file_hash = NULL WHERE id = :id')->execute(['id' => $existing['id']]);

        $estimate = estimateRows($stored, $ext);
        $pdo->prepare("INSERT INTO import_batches (company_id, file_type, original_filename, file_hash, total_rows, status) VALUES (:c, 'MEMBERS', :f, :h, :t, 'processing')")
            ->execute(['c' => $companyId, 'f' => $file['name'], 'h' => $hash, 't' => $estimate]);
        $batchId = (int)$pdo->lastInsertId();

        $progress = ['status' => 'processing', 'total' => $estimate, 'processed' => 0, 'ok' => 0, 'failed' => 0, 'started' => time(), 'errors' => []];
        writeProgress($batchId, $progress);

        // ---- Balas browser sekarang, sambung proses di background ----
        $body = json_encode(['status' => 'queued', 'batch_id' => $batchId, 'total' => $estimate]);
        header('Connection: close');
        header('Content-Length: ' . strlen($body));
        echo $body;
        while (ob_get_level() > 0) ob_end_flush();
        flush();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        // Kalau fatal error / timeout, tandakan batch sebagai failed
        register_shutdown_function(function () use ($batchId, $stored) {
            $f = progressPath($batchId);
            $p = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
            if ($p && ($p['status'] ?? '') === 'processing') {
                $p['status'] = 'failed';
                $p['message'] = 'Import stopped unexpectedly (server error / memory / timeout).';
                writeProgress($batchId, $p);
                try { dbConnect()->prepare("UPDATE import_batches SET status = 'failed' WHERE id = :id")->execute(['id' => $batchId]); } catch (Throwable $e) {}
            }
            if ($stored && is_file($stored)) @unlink($stored);
        });

        runImport($pdo, $batchId, $stored, $ext, $delimiter, $companies, $progress);
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        if ($batchId) {
            // Sudah balas browser -> simpan error dalam progress
            writeProgress($batchId, ['status' => 'failed', 'message' => $error->getMessage(), 'errors' => []]);
            try { $pdo->prepare("UPDATE import_batches SET status = 'failed' WHERE id = :id")->execute(['id' => $batchId]); } catch (Throwable $e) {}
        } else {
            if ($stored && is_file($stored)) @unlink($stored);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $error->getMessage()]);
        }
    }
    if ($stored && is_file($stored)) @unlink($stored);
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

    <div class="upload-group">
      <label class="upload-label">CSV Delimiter (if CSV / TXT file)</label>
      <span class="upload-hint">Usually comma (,) or semicolon (;). Taip <strong>t</strong> untuk Tab</span>
      <input type="text" id="delimiter" class="delimiter-input" value="," placeholder="," maxlength="1">
    </div>

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

let importRunning = false;

// ============================================================
// Helpers
// ============================================================
function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}
const fmt = n => Number(n || 0).toLocaleString();
const sleep = ms => new Promise(r => setTimeout(r, ms));

// ============================================================
// Upload Dropzone Setup
// ============================================================
function setupDropZone(dropZone, fileInput, previewDiv) {
  dropZone.addEventListener('click', () => fileInput.click());
  dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
  dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
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
    const mb = (file.size / 1048576).toFixed(1);
    previewDiv.innerHTML = `
      <div class="file-preview">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>
        </svg>
        <span>${escapeHtml(file.name)} (${mb} MB)</span>
        <span class="file-preview-clear" onclick="document.getElementById('${fileInput.id}').value=''; document.getElementById('${fileInput.id}').dispatchEvent(new Event('change'));">✕</span>
      </div>`;
  } else {
    previewDiv.innerHTML = '';
  }
  updateUploadBtn();
}

function updateUploadBtn() {
  uploadBtn.disabled = importRunning || !membersFile.files.length;
}

setupDropZone(membersDropZone, membersFile, membersPreview);

// ============================================================
// Clear Button
// ============================================================
clearBtn.addEventListener('click', () => {
  if (importRunning) return;
  membersFile.value = '';
  membersPreview.innerHTML = '';
  updateUploadBtn();
  statusContainer.innerHTML = '';
  resultsCard.style.display = 'none';
  progressSection.style.display = 'none';
});

// ============================================================
// Upload (XHR supaya ada progress muat naik) + polling progress import
// ============================================================
function postFile(formData, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'upload_members.php');
    xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
    xhr.onerror = () => reject(new Error('Network error while uploading.'));
    xhr.onload = () => {
      let json;
      try { json = JSON.parse(xhr.responseText); }
      catch (e) { return reject(new Error('Server error ' + xhr.status + ': ' + xhr.responseText.substring(0, 200))); }
      if (xhr.status >= 400) return reject(new Error(json.message || ('Server error ' + xhr.status)));
      resolve(json);
    };
    xhr.send(formData);
  });
}

async function pollImport(batchId) {
  const t0 = Date.now();
  let fails = 0;
  while (true) {
    await sleep(1000);
    let p;
    try {
      const r = await fetch('upload_members.php?progress=' + batchId, { cache: 'no-store' });
      p = await r.json();
      fails = 0;
    } catch (e) {
      if (++fails >= 15) throw new Error('Lost connection to server (session expired?). Check Upload history.');
      continue;
    }
    if (p.status !== 'processing') return p;
    if (p.stale) throw new Error('Import seems to have stopped on the server.');

    const done = p.processed || 0, total = p.total || 0;
    const secs = Math.max(1, (Date.now() - t0) / 1000);
    const rate = done / secs;
    let pct = total ? Math.min(99, Math.round(done / total * 100)) : 0;
    let eta = (total && rate > 0) ? Math.max(0, Math.round((total - done) / rate)) : null;
    progressFill.style.width = (total ? 30 + pct * 0.7 : 50) + '%';
    progressText.textContent = `⚙️ Processing ${fmt(done)}${total ? ' / ~' + fmt(total) : ''} rows` +
      (total ? ` (${pct}%)` : '') + ` · ${fmt(Math.round(rate))} rows/s` +
      (eta !== null ? ` · ~${eta}s left` : '') +
      (p.failed ? ` · ${fmt(p.failed)} failed` : '');
  }
}

uploadBtn.addEventListener('click', async () => {
  importRunning = true;
  updateUploadBtn();
  statusContainer.innerHTML = '';
  resultsCard.style.display = 'none';
  progressSection.style.display = 'block';
  progressFill.style.width = '0%';
  progressText.textContent = '⏳ Uploading file...';

  const formData = new FormData();
  formData.append('members', membersFile.files[0]);
  if (delimiterInput.value) {
    formData.append('delimiter', delimiterInput.value.toLowerCase() === 't' ? '\t' : delimiterInput.value);
  }

  try {
    const queued = await postFile(formData, f => {
      progressFill.style.width = Math.round(f * 30) + '%';
      progressText.textContent = `⏳ Uploading file... ${Math.round(f * 100)}%`;
    });

    let result;
    if (queued.status === 'queued') {
      progressText.textContent = '⚙️ Starting import...';
      const p = await pollImport(queued.batch_id);
      if (p.status === 'failed' || p.status === 'unknown') {
        throw new Error(p.message || 'Import failed.');
      }
      result = { status: 'success', members: { total: p.total, inserted: p.inserted, updated: p.updated, failed: p.failed, errors: p.errors } };
    } else {
      result = queued; // contoh: file already imported
    }

    progressFill.style.width = '100%';
    progressText.textContent = '✅ Complete!';
    setTimeout(() => { progressSection.style.display = 'none'; showResults(result); }, 400);
  } catch (error) {
    progressSection.style.display = 'none';
    showAlert('❌ Error: ' + escapeHtml(error.message), 'error');
  } finally {
    importRunning = false;
    updateUploadBtn();
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
          <div class="stat-box"><div class="stat-num">${fmt(m.total)}</div><div class="stat-lbl">Total Rows</div></div>
          <div class="stat-box ok"><div class="stat-num">${fmt(m.inserted)}</div><div class="stat-lbl">New Members</div></div>
          <div class="stat-box warn"><div class="stat-num">${fmt(m.updated)}</div><div class="stat-lbl">Updated</div></div>
          <div class="stat-box bad"><div class="stat-num">${fmt(m.failed)}</div><div class="stat-lbl">Failed</div></div>
        </div>`;

      if (m.errors && m.errors.length) {
        html += '<div class="error-list"><strong>Failed rows (first 100):</strong><br>' +
          m.errors.map(e => escapeHtml(e)).join('<br>') + '</div>';
      }
    }
    resultsContent.innerHTML = html;

    membersFile.value = '';
    membersPreview.innerHTML = '';
    updateUploadBtn();
  } else {
    resultsContent.innerHTML = `<div class="alert alert-error">❌ ${escapeHtml(result.message || 'Upload failed')}</div>`;
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

updateUploadBtn();
</script>

</body>
</html>