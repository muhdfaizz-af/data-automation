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

function getDashboardData($pdo) {
    $d = ['total_members'=>0,'total_campaigns'=>0,'total_missions'=>0,'active_users'=>0];
    if (!$pdo) return $d;
    try {
        $d['total_members']   = (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
        $d['total_campaigns'] = (int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
        $d['total_missions']  = (int)$pdo->query('SELECT COUNT(*) FROM missions')->fetchColumn();
        $d['active_users']    = (int)$pdo->query('SELECT COUNT(DISTINCT member_id) FROM member_xp_logs WHERE DATE(created_at)=CURDATE()')->fetchColumn();
    } catch (Exception $e) {}
    return $d;
}

$dashboardData = null;
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
    if ($pdo) $dashboardData = getDashboardData($pdo);
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
.login-title{font-size:22px;font-weight:800;text-align:center;margin-bottom:4px;}
.login-sub{font-size:13px;color:var(--gray-500);text-align:center;margin-bottom:28px;font-weight:500;}
.fg{margin-bottom:16px;}
.fg label{display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--gray-700);}
.fg input{width:100%;padding:11px 14px;border:1.5px solid var(--gray-300);border-radius:10px;font-size:14px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--ink);outline:none;transition:border-color .2s,box-shadow .2s;}
.fg input:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(224,32,46,.1);}
.err-msg{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:16px;}
.btn-login{width:100%;padding:13px;background:var(--red);color:#fff;border-radius:30px;font-size:14.5px;font-weight:800;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .15s,box-shadow .15s;box-shadow:0 4px 14px rgba(224,32,46,.3);}
.btn-login:hover{background:var(--red-dark);}
.btn-login svg{width:16px;height:16px;stroke:white;fill:none;}

/* ── LAYOUT ── */
.layout{display:flex;margin-top:var(--topbar-h);}
.main{margin-left:var(--sidebar-w);flex:1;padding:28px 32px 48px;min-width:0;transition:margin-left .25s ease;}

/* ── PAGE HEADER ── */
.page-header{margin-bottom:24px;}
.page-header h1{font-size:24px;font-weight:800;margin-bottom:3px;}
.page-header p{font-size:13.5px;color:var(--gray-500);}

/* ── HERO BANNER ── */
.dash-hero{background:linear-gradient(135deg,var(--red) 0%,var(--red-dark) 60%,var(--red-darker) 100%);border-radius:var(--radius-lg);padding:28px 36px;margin-bottom:24px;position:relative;overflow:hidden;display:flex;align-items:center;min-height:130px;}
.dash-hero::before{content:'';position:absolute;right:160px;bottom:-30px;width:200px;height:200px;background:rgba(255,255,255,.05);border-radius:50%;}
.dash-hero-teal{position:absolute;right:0;bottom:0;width:140px;height:100px;background:var(--teal);border-radius:60px 0 var(--radius-lg) 0;opacity:.8;}
.dash-hero-teal::after{content:'';position:absolute;top:-25px;left:-25px;width:80px;height:80px;background:var(--teal);border-radius:50%;opacity:.5;}
.dash-hero-content{position:relative;z-index:2;}
.dash-hero-eyebrow{font-size:11px;font-weight:700;letter-spacing:1px;color:rgba(255,255,255,.75);text-transform:uppercase;margin-bottom:6px;}
.dash-hero h2{font-size:26px;font-weight:800;color:#fff;margin-bottom:4px;}
.dash-hero-sub{font-size:13px;color:rgba(255,255,255,.85);font-weight:500;}
.dash-hero-badge{position:absolute;right:20px;top:50%;transform:translateY(-50%);z-index:2;width:60px;height:60px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;}
.dash-hero-badge svg{width:32px;height:32px;fill:white;}

/* ── STATS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.stat-card{background:var(--white);border-radius:var(--radius-md);padding:20px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);display:flex;align-items:center;gap:14px;transition:transform .15s,box-shadow .15s;}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow);}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-icon svg{width:22px;height:22px;}
.si-red{background:rgba(224,32,46,.1);} .si-red svg{stroke:var(--red);}
.si-teal{background:rgba(0,180,180,.12);} .si-teal svg{stroke:var(--teal);}
.si-gold{background:rgba(245,166,35,.12);} .si-gold svg{stroke:var(--gold);}
.si-green{background:rgba(16,185,129,.12);} .si-green svg{stroke:var(--green);}
.stat-val{font-size:26px;font-weight:800;line-height:1;}
.stat-lbl{font-size:11.5px;color:var(--gray-500);font-weight:600;margin-top:3px;}

/* ── CARDS ── */
.card{background:var(--white);border-radius:var(--radius-lg);padding:24px;box-shadow:var(--shadow-card);border:1px solid var(--gray-100);}
.card-title{font-size:15.5px;font-weight:800;margin-bottom:16px;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;}

/* ── QUICK LINKS ── */
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
.quick-link-label{font-size:12.5px;font-weight:700;text-align:center;color:var(--ink);}

/* ── SYSINFO ── */
.sysinfo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.si-item{padding:14px 16px;background:var(--gray-100);border-radius:var(--radius-md);}
.si-lbl{font-size:10.5px;font-weight:800;color:var(--gray-500);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.si-val{font-size:13.5px;font-weight:700;color:var(--ink);}

/* ── DRAWER ── */
.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;opacity:0;transition:opacity .25s;}
.drawer-overlay.open{opacity:1;}
.sidebar-drawer{position:fixed;top:0;left:-280px;bottom:0;width:260px;background:#fff;z-index:400;transition:left .28s cubic-bezier(.4,0,.2,1);padding:20px 14px 80px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;box-shadow:4px 0 24px rgba(0,0,0,.12);}
.sidebar-drawer.open{left:0;}
.drawer-header{display:flex;align-items:center;justify-content:space-between;padding-bottom:16px;border-bottom:1px solid var(--gray-100);margin-bottom:8px;}
.drawer-close{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:var(--gray-100);border:none;cursor:pointer;}
.drawer-close svg{width:16px;height:16px;stroke:var(--gray-700);}

@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr);} .sysinfo-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:900px){
  .sidebar{display:none;}
  .main{margin-left:0;padding:20px;}
  body.sidebar-collapsed .main{margin-left:0;}
}
@media(max-width:600px){.stats-row{grid-template-columns:1fr 1fr;} .grid-2{grid-template-columns:1fr;} .sysinfo-grid{grid-template-columns:1fr;} .main{padding:16px 14px 40px;} .dash-hero{padding:20px 22px;} .dash-hero h2{font-size:20px;}}
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


<?php endif; ?>
</body>
</html>