<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid request. Please try again.';
    } elseif ($newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all password fields.';
    } elseif (strlen($newPassword) < 4) {
        $error = 'New password must be at least 4 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation password do not match.';
    } else {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $update = $pdo->prepare('UPDATE admin_users SET password = ? WHERE id = ?');
            $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$_SESSION['admin_id']]);
            $success = 'Password changed successfully.';
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'Unable to change password right now. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="./images/icon-sasia.png"/>
  <title>Change Password - S ASIA</title>
  <style>
    :root{--red:#e0202e;--ink:#202124;--white:#fff;--gray-100:#f1f3f5;--gray-300:#d9dde2;--gray-500:#6b7280;--gray-700:#374151;--topbar-h:64px;--sidebar-w:250px;--sidebar-w-collapsed:76px;}
    *{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:var(--ink);font-family:'Segoe UI',sans-serif}.layout{display:flex}.main{width:calc(100% - var(--sidebar-w));margin-left:var(--sidebar-w);padding:104px 32px 40px;transition:margin-left .25s ease}.page-header{margin-bottom:24px}.page-header h1{margin:0 0 6px;font-size:26px}.page-header p{margin:0;color:var(--gray-500);font-size:14px}.password-card{width:100%;max-width:560px;background:var(--white);border:1px solid var(--gray-100);border-radius:12px;padding:26px;box-shadow:0 5px 18px rgba(25,35,45,.05)}.field{margin-bottom:18px}.field label{display:block;margin-bottom:7px;font-size:13px;font-weight:700}.field input{width:100%;padding:11px 12px;border:1px solid var(--gray-300);border-radius:8px;font:inherit;font-size:14px}.field input:focus{outline:2px solid rgba(224,32,46,.18);border-color:var(--red)}.message{padding:11px 13px;border-radius:8px;margin-bottom:18px;font-size:13px}.message.error{background:#fff0f1;color:#b42318}.message.success{background:#ecfdf3;color:#087443}.btn-save{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:8px;background:var(--red);color:#fff;padding:11px 16px;font-weight:700;cursor:pointer}.btn-save:hover{background:#bd1724}.btn-save svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2}
    body.sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed);width:calc(100% - var(--sidebar-w-collapsed))}@media(max-width:900px){.main,body.sidebar-collapsed .main{width:100%;margin-left:0;padding:92px 20px 30px}.password-card{max-width:none}}
  </style>
</head>
<body>
<?php
$pageTitle = 'Change Password';
$navBasePath = './';
$adminUsername = $_SESSION['admin_username'] ?? '';
include __DIR__ . '/includes/topnav.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="layout">
  <main class="main">
    <header class="page-header">
      <h1>Change Password</h1>
      <p>Update your admin account password.</p>
    </header>
    <section class="password-card">
      <?php if ($error): ?><div class="message error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="message success" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="field"><label for="new_password">New Password</label><input type="password" id="new_password" name="new_password" minlength="4" autocomplete="new-password" required></div>
        <div class="field"><label for="confirm_password">Confirm New Password</label><input type="password" id="confirm_password" name="confirm_password" minlength="4" autocomplete="new-password" required></div>
        <button type="submit" class="btn-save"><svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Password</button>
      </form>
    </section>
  </main>
</div>
</body>
</html>