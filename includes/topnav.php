<?php
/**
 * Top navigation component
 */

$navBasePath = $navBasePath ?? '';
$pageTitle = $pageTitle ?? '';
$showMobileMenu = $showMobileMenu ?? true;
$adminUsername = $adminUsername ?? '';
?>
<style>
  .topbar{height:var(--topbar-h);background:var(--white);border-bottom:1px solid var(--gray-100);display:flex;align-items:center;padding:0 24px;position:fixed;top:0;left:0;right:0;z-index:200;gap:16px;box-shadow:var(--shadow,none);}
  .topbar-logo{display:flex;align-items:center;gap:10px;width:var(--sidebar-w);flex-shrink:0;transition:width .25s ease;}
  .topbar-logo img{height:32px;width:auto;}
  .topbar-brand-text{font-weight:800;font-size:13px;letter-spacing:.5px;color:var(--red);}
  .topbar-divider{width:1px;height:28px;background:var(--gray-100);}
  .topbar-page-title{font-size:15px;font-weight:700;}
  .topbar-right{margin-left:auto;display:flex;align-items:center;gap:12px;}
  .admin-menu{position:relative;}
  .admin-chip{display:flex;align-items:center;gap:8px;background:var(--gray-100);border:0;border-radius:30px;padding:6px 12px 6px 6px;cursor:pointer;text-align:left;transition:background .15s;}
  .admin-chip:hover,.admin-chip[aria-expanded="true"]{background:var(--gray-300);}
  .admin-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--red),#8B0000);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;}
  .admin-name{font-size:13px;font-weight:700;}
  .admin-role{font-size:10px;color:var(--gray-500);}
  .admin-chevron{width:14px;height:14px;margin-left:3px;stroke:var(--gray-500);fill:none;stroke-width:2;transition:transform .2s;}
  .admin-chip[aria-expanded="true"] .admin-chevron{transform:rotate(180deg);}
  .admin-dropdown{display:none;position:absolute;top:calc(100% + 8px);right:0;width:190px;padding:6px;background:var(--white);border:1px solid var(--gray-100);border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,.12);z-index:210;}
  .admin-dropdown.open{display:block;}
  .admin-dropdown a{display:flex;align-items:center;gap:9px;padding:10px 11px;border-radius:7px;color:var(--ink);font-size:12.5px;font-weight:600;text-decoration:none;}
  .admin-dropdown a:hover{background:var(--gray-100);}
  .admin-dropdown svg{width:15px;height:15px;stroke:var(--gray-700);fill:none;stroke-width:2;}
  .btn-logout-top{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;font-size:12.5px;font-weight:700;background:transparent;border:2px solid #fecaca;color:var(--red);cursor:pointer;transition:all .15s;}
  .btn-logout-top:hover{background:#fee2e2;}
  .btn-logout-top svg{width:13px;height:13px;stroke:var(--red);fill:none;}
  .sidebar-toggle-btn{width:36px;height:36px;border-radius:9px;background:var(--gray-100);display:flex;align-items:center;justify-content:center;border:none;cursor:pointer;flex-shrink:0;transition:background .15s;margin:0 8px;}
  .sidebar-toggle-btn:hover{background:var(--gray-300);}
  .sidebar-toggle-btn svg{width:16px;height:16px;stroke:var(--ink);fill:none;stroke-width:2;transition:transform .25s ease;}
  body.sidebar-collapsed .sidebar-toggle-btn svg{transform:rotate(180deg);}
  .hamburger-btn{display:none;}
  body.sidebar-collapsed .topbar-logo{width:var(--sidebar-w-collapsed);}
  body.sidebar-collapsed .topbar-logo .topbar-brand-text{display:none;}
  @media(max-width:900px){
    .topbar-logo{width:auto;}
    <?php if ($showMobileMenu): ?>
    .hamburger-btn{display:flex;align-items:center;justify-content:center;width:36px;height:36px;cursor:pointer;background:transparent;border-radius:8px;border:0;}
    .hamburger-btn:hover{background:var(--gray-100);}
    .hamburger-btn svg{width:20px;height:20px;stroke:var(--ink);}
    <?php endif; ?>
  }
</style>

<header class="topbar">
  <div class="topbar-logo">
    <img src="<?= htmlspecialchars($navBasePath) ?>images/logo-sasia.png" alt="S ASIA" onerror="this.style.display='none'">
    <span class="topbar-brand-text">ADMIN</span>
  </div>
  <button class="sidebar-toggle-btn" id="sidebarToggleBtn" onclick="toggleSidebarOnDesktop()" title="Collapse / expand sidebar" aria-label="Collapse or expand sidebar">
    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  </button>
  <?php if ($showMobileMenu): ?>
  <button class="hamburger-btn" onclick="openDrawer()" title="Open menu">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <?php endif; ?>
  <div class="topbar-divider"></div>
  <span class="topbar-page-title"><?= htmlspecialchars($pageTitle) ?></span>
  <div class="topbar-right">
    <div class="admin-menu">
      <button type="button" class="admin-chip" id="adminMenuButton" aria-expanded="false" aria-controls="adminDropdown">
        <div class="admin-avatar"><?= htmlspecialchars(strtoupper(substr($adminUsername, 0, 2))) ?></div>
        <div>
          <div class="admin-name"><?= htmlspecialchars($adminUsername) ?></div>
          <div class="admin-role">Top Management</div>
        </div>
        <svg class="admin-chevron" viewBox="0 0 24 24" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="admin-dropdown" id="adminDropdown" role="menu">
        <a href="<?= htmlspecialchars($navBasePath) ?>change_password.php" role="menuitem">
          <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
          Change Password
        </a>
      </div>
    </div>
    <a href="<?= htmlspecialchars($navBasePath) ?>logout.php" class="btn-logout-top">
      <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</header>
<script>
(function () {
  const button = document.getElementById('adminMenuButton');
  const dropdown = document.getElementById('adminDropdown');
  if (!button || !dropdown) return;

  function closeAdminMenu() {
    button.setAttribute('aria-expanded', 'false');
    dropdown.classList.remove('open');
  }

  button.addEventListener('click', function () {
    const isOpen = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', String(!isOpen));
    dropdown.classList.toggle('open', !isOpen);
  });

  document.addEventListener('click', function (event) {
    if (!event.target.closest('.admin-menu')) closeAdminMenu();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeAdminMenu();
  });
}());
</script>
