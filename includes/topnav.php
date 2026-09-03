<?php
/**
 * Top navigation component
 */

$navBasePath = $navBasePath ?? '';
$pageTitle = $pageTitle ?? '';
$showMobileMenu = $showMobileMenu ?? false;
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
  .admin-chip{display:flex;align-items:center;gap:8px;background:var(--gray-100);border-radius:30px;padding:6px 14px 6px 6px;}
  .admin-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--red),#8B0000);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;}
  .admin-name{font-size:13px;font-weight:700;}
  .admin-role{font-size:10px;color:var(--gray-500);}
  .btn-logout-top{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;font-size:12.5px;font-weight:700;background:transparent;border:2px solid #fecaca;color:var(--red);cursor:pointer;transition:all .15s;}
  .btn-logout-top:hover{background:#fee2e2;}
  .btn-logout-top svg{width:13px;height:13px;stroke:var(--red);fill:none;}
  .sidebar-toggle-btn{width:36px;height:36px;border-radius:9px;background:var(--gray-100);display:flex;align-items:center;justify-content:center;border:none;cursor:pointer;flex-shrink:0;transition:background .15s;margin:0 8px;}
  .sidebar-toggle-btn:hover{background:var(--gray-300);}
  .sidebar-toggle-btn svg{width:16px;height:16px;stroke:var(--ink);fill:none;stroke-width:2;transition:transform .25s ease;}
  body.sidebar-collapsed .sidebar-toggle-btn svg{transform:rotate(180deg);}
  body.sidebar-collapsed .topbar-logo{width:var(--sidebar-w-collapsed);}
  body.sidebar-collapsed .topbar-logo .topbar-brand-text{display:none;}
  @media(max-width:900px){
    .topbar-logo{width:auto;}
    <?php if ($showMobileMenu): ?>
    .hamburger-btn{display:flex;align-items:center;justify-content:center;width:36px;height:36px;cursor:pointer;background:transparent;border-radius:8px;border:0;}
    .hamburger-btn:hover{background:var(--gray-100);}
    .hamburger-btn svg{width:20px;height:20px;stroke:var(--ink);}
    <?php endif; ?>
    .sidebar-toggle-btn{display:none;}
  }
</style>

<header class="topbar">
  <div class="topbar-logo">
    <img src="<?= htmlspecialchars($navBasePath) ?>images/logo-sasia.png" alt="S ASIA" onerror="this.style.display='none'">
    <span class="topbar-brand-text">ADMIN</span>
  </div>
  <button class="sidebar-toggle-btn" id="sidebarToggleBtn" onclick="toggleSidebarOnDesktop()" title="Collapse / expand sidebar">
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
    <div class="admin-chip">
      <div class="admin-avatar"><?= htmlspecialchars(strtoupper(substr($adminUsername, 0, 2))) ?></div>
      <div>
        <div class="admin-name"><?= htmlspecialchars($adminUsername) ?></div>
        <div class="admin-role">Top Management</div>
      </div>
    </div>
    <a href="<?= htmlspecialchars($navBasePath) ?>logout.php" class="btn-logout-top">
      <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</header>
