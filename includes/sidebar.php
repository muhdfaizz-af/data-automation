<?php
/**
 * Sidebar Navigation Component
 * Used in dashboard layout
 */

// ── Icon SVG Definitions ──
$icoHome='<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>';
$icoBox='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
$icoBundle='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>';
$icoSales='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';
$icoMembers='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
$icoImperson='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>';
$icoUpload='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
$icoLogout='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';

// ── Navigation Item Helper Function ──
$navBasePath = $navBasePath ?? '';
$activeNav = $activeNav ?? 'dashboard';

function navItem($href, $icon, $label, $active = false, $basePath = '') {
    $c = $active ? ' active' : '';
  return '<a href="' . $basePath . $href . '" class="nav-item' . $c . '" title="' . htmlspecialchars($label) . '"><span class="ni">' . $icon . '</span><span class="nav-label">' . $label . '</span></a>';
}
?>
<style>
  .sidebar{width:var(--sidebar-w);background:var(--white);border-right:1px solid var(--gray-100);position:fixed;top:var(--topbar-h);left:0;bottom:0;overflow-y:auto;overflow-x:hidden;padding:20px 14px;display:flex;flex-direction:column;gap:2px;z-index:100;transition:width .25s ease;}
  .sidebar-section-label{font-size:10px;font-weight:800;letter-spacing:1px;color:var(--gray-500);text-transform:uppercase;padding:12px 12px 6px;white-space:nowrap;}
  .nav-item{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:10px;font-size:14px;font-weight:600;color:var(--gray-700);transition:background .15s,color .15s;white-space:nowrap;text-decoration:none;}
  .nav-item .ni{width:18px;height:18px;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
  .nav-item .ni svg{width:18px;height:18px;}
  .nav-item:hover{background:var(--gray-100);color:var(--ink);}
  .nav-item.active{background:var(--red);color:#fff;box-shadow:0 6px 16px -6px rgba(224,32,46,.5);}
  .nav-item.active .ni svg{stroke:#fff !important;}
  .nav-group{display:flex;flex-direction:column;gap:4px;}
  .nav-parent{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:10px 12px;border:0;border-radius:10px;background:transparent;color:var(--gray-700);font:600 14px/1.2 'Segoe UI',sans-serif;text-align:left;cursor:pointer;transition:background .15s,color .15s;}
  .nav-parent:hover{background:var(--gray-100);color:var(--ink);}
  .nav-parent.active{background:var(--red);color:#fff;box-shadow:0 6px 16px -6px rgba(224,32,46,.5);}
  .nav-parent.active .nav-chev,
  .nav-parent.active .ni svg{color:#fff !important;stroke:#fff !important;}
  .nav-parent-content{display:flex;align-items:center;gap:11px;flex:1;min-width:0;}
  .nav-parent .ni{width:18px;height:18px;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
  .nav-parent .ni svg{width:18px;height:18px;}
  .nav-chev{width:18px;height:18px;display:flex;align-items:center;justify-content:center;transition:transform .2s ease;color:var(--gray-700);font-size:17px;line-height:1;font-weight:700;}
  .nav-group.open > .nav-parent .nav-chev{transform:rotate(180deg);}
  .nav-parent:hover .nav-chev{color:var(--ink);}
  .nav-parent.active .nav-chev{color:#fff;}
  .nav-group.open > .nav-parent .nav-chev{transform:rotate(180deg);}
  .nav-children{display:none;flex-direction:column;gap:4px;padding:2px 0 0 28px;}
  .nav-group.open > .nav-children{display:flex;}
  .nav-child{display:block;padding:8px 10px;border-radius:8px;font-size:13px;font-weight:600;color:var(--gray-700);text-decoration:none;transition:background .15s,color .15s;}
  .nav-child:hover{background:var(--gray-100);color:var(--ink);}
  .nav-child.active{background:rgba(224,32,46,.08);color:var(--red);}
  .nav-divider{height:1px;background:var(--gray-100);margin:8px 0;}
  .nav-logout{color:var(--red) !important;}
  .nav-logout:hover{background:#fff0f1 !important;}
  .nav-logout .ni svg{stroke:var(--red);}
  .sidebar-footer{margin-top:auto;padding:16px 12px 0;}
  .sf-badge{background:linear-gradient(135deg,var(--red),var(--red-dark));border-radius:var(--radius-md);padding:14px;color:#fff;white-space:nowrap;overflow:hidden;}
  .sf-title{font-size:13px;font-weight:800;margin-bottom:2px;}
  .sf-sub{font-size:11px;opacity:.85;}
  .sidebar-drawer{display:none;position:fixed;top:0;left:-280px;bottom:0;width:260px;background:#fff;z-index:400;transition:left .28s cubic-bezier(.4,0,.2,1);padding:20px 14px 80px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;box-shadow:4px 0 24px rgba(0,0,0,.12);}
  .sidebar-drawer.open{left:0;}
  .drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;}
  .drawer-overlay.open{display:block;}
  .drawer-header{display:flex;align-items:center;justify-content:space-between;padding-bottom:16px;border-bottom:1px solid var(--gray-100);margin-bottom:8px;}
  .drawer-close{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:var(--gray-100);}
  .drawer-close svg{width:16px;height:16px;stroke:var(--gray-700);}
  .sidebar-collapsed .sidebar{width:var(--sidebar-w-collapsed);}
  .sidebar-collapsed .sidebar .sidebar-section-label,
  .sidebar-collapsed .sidebar .nav-label,
  .sidebar-collapsed .sidebar .sidebar-footer{display:none;}
  .sidebar-collapsed .sidebar .nav-item{justify-content:center;padding:10px;}
  .sidebar-collapsed .sidebar .nav-item .ni{margin:0;}
  .sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed);}
  @media(max-width:900px){
    .sidebar{display:none;}
    .sidebar-drawer{display:flex;}
    .main{margin-left:0 !important;}
    body.sidebar-collapsed .main{margin-left:0 !important;}
  }
</style>

<!-- SIDEBAR (Desktop) -->
<aside class="sidebar" id="sidebarMain">
  <div class="sidebar-section-label">Main</div>
  <?= navItem('index.php', $icoHome, 'Dashboard', $activeNav === 'dashboard', $navBasePath) ?>
  
  <div class="sidebar-section-label">Reports</div>
  <div class="nav-group open" data-nav-id="salesperformance">
    <button type="button" class="nav-parent <?= $activeNav === 'salesperformance' ? 'active' : '' ?>" aria-expanded="true">
      <span class="nav-parent-content">
        <span class="ni"><?= $icoSales ?></span>
        <span class="nav-label">Sales Performance</span>
      </span>
      <span class="nav-chev">▾</span>
    </button>
    <div class="nav-children">
      <?= navItem('salesperformance/sales_comparison.php', $icoSales, 'Sales Comparison', $activeNav === 'salescomparison', $navBasePath) ?>
      <?= navItem('salesperformance/hub_comparison.php', $icoSales, 'Hub Comparison', $activeNav === 'salesbyhub', $navBasePath) ?>
      <?= navItem('salesperformance/sales_estimation.php', $icoSales, 'Sales Estimation', false, $navBasePath) ?>
      <?= navItem('salesperformance/asd_comparison.php', $icoSales, 'Active Agent & ASD', false, $navBasePath) ?>
      <?= navItem('salesperformance/topupper_product.php', $icoSales, 'Top Upper Product', false, $navBasePath) ?>
      <?= navItem('salesperformance/topbottom_product.php', $icoSales, 'Top Bottom Product', false, $navBasePath) ?>
      <?= navItem('salesperformance/reqruitment.php', $icoSales, 'Reqruitment', false, $navBasePath) ?>
    </div>
  </div>
  <?= navItem('products.php', $icoBox, 'Products', $activeNav === 'products', $navBasePath) ?>
  
  <div class="nav-divider"></div>
  <div class="sidebar-section-label">Tools</div>
  <?= navItem('upload_reports/index.php', $icoUpload, 'Upload Reports', $activeNav === 'upload', $navBasePath) ?>
  <?= navItem('upload_reports/manual_sales.php', $icoSales, 'Manual Sales', $activeNav === 'manual_sales', $navBasePath) ?>
  
  <div class="nav-divider"></div>
  <a href="<?= $navBasePath ?>logout.php" class="nav-item nav-logout" title="Logout"><span class="ni"><?= $icoLogout ?></span><span class="nav-label">Logout</span></a>
</aside>

<!-- DRAWER (Mobile) -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<aside class="sidebar-drawer" id="sidebarDrawer">
  <div class="drawer-header">
    <img src="<?= $navBasePath ?>images/logo-sasia.png" alt="S ASIA" style="height:26px;" onerror="this.style.display='none'">
    <!-- Butang toggle sidebar (contoh) -->
    <button type="button" onclick="toggleSidebar()" title="Toggle Sidebar" style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:var(--gray-100);border:0;cursor:pointer;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;">
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="6" x2="21" y2="6"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
    </button>
    <button class="drawer-close" onclick="closeDrawer()"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
  </div>
  <div class="sidebar-section-label">Main</div>
  <?= navItem('index.php', $icoHome, 'Dashboard', $activeNav === 'dashboard', $navBasePath) ?>
  
  <div class="sidebar-section-label">Reports</div>
  <div class="nav-group open" data-nav-id="salesperformance">
    <button type="button" class="nav-parent <?= $activeNav === 'salesperformance' ? 'active' : '' ?>" aria-expanded="true">
      <span class="nav-parent-content">
        <span class="ni"><?= $icoSales ?></span>
        <span class="nav-label">Sales Performance</span>
      </span>
      <span class="nav-chev">▾</span>
    </button>
    <div class="nav-children">
      <?= navItem('salesperformance/sales_comparison.php', $icoSales, 'Sales Comparison', $activeNav === 'salesperformance', $navBasePath) ?>
      <?= navItem('salesperformance/sales_comparison.php', $icoSales, 'Sales Estimation', false, $navBasePath) ?>
      <?= navItem('salesperformance/sales_comparison.php', $icoSales, 'Monthly Summary', false, $navBasePath) ?>
      <?= navItem('salesperformance/sales_comparison.php', $icoSales, 'Yearly Overview', false, $navBasePath) ?>
    </div>
  </div>
  <?= navItem('product-bundles.php', $icoBundle, 'Product Bundles', $activeNav === 'bundles', $navBasePath) ?>
  <?= navItem('sales.php', $icoSales, 'Sales Upload', $activeNav === 'sales', $navBasePath) ?>
  <?= navItem('members.php', $icoMembers, 'Members Upload', $activeNav === 'members', $navBasePath) ?>
  
  <div class="nav-divider"></div>
  <div class="sidebar-section-label">Tools</div>
  <?= navItem('upload_reports/index.php', $icoUpload, 'Upload Reports', $activeNav === 'upload', $navBasePath) ?>
  <?= navItem('upload_reports/manual_sales.php', $icoSales, 'Manual Sales', $activeNav === 'manual_sales', $navBasePath) ?>
  
  <div class="nav-divider"></div>
  <a href="<?= $navBasePath ?>logout.php" class="nav-item nav-logout"><span class="ni"><?= $icoLogout ?></span><span class="nav-label">Logout</span></a>
</aside>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const groups = document.querySelectorAll('.nav-group');

    groups.forEach(function (group) {
      const parent = group.querySelector('.nav-parent');
      if (!parent) return;

      parent.addEventListener('click', function () {
        const willOpen = !group.classList.contains('open');

        groups.forEach(function (item) {
          item.classList.remove('open');
          const btn = item.querySelector('.nav-parent');
          if (btn) btn.setAttribute('aria-expanded', 'false');
        });

        group.classList.toggle('open', willOpen);
        parent.setAttribute('aria-expanded', String(willOpen));
      });
    });
  });
</script>

<script>
  // ── Auto-expand sidebar bila mouse masuk kawasan sidebar ──
  (function () {
    const sidebarEl = document.getElementById('sidebarMain');
    if (!sidebarEl) return;

    let hoverExpanded = false;

    sidebarEl.addEventListener('mouseenter', function () {
      if (document.body.classList.contains('sidebar-collapsed')) {
        document.body.classList.remove('sidebar-collapsed');
        hoverExpanded = true; // tanda expand ni dari hover, bukan dari toggle manual
      }
    });

    sidebarEl.addEventListener('mouseleave', function () {
      if (hoverExpanded) {
        document.body.classList.add('sidebar-collapsed');
        hoverExpanded = false;
      }
    });
  })();

  // ── Function untuk butang open/close sidebar ──
  function toggleSidebar() {
    document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed'));
  }

  // ── Load balik state sidebar (collapsed/expand) bila page dibuka semula ──
  document.addEventListener('DOMContentLoaded', function () {
    const saved = localStorage.getItem('sidebarCollapsed');
    if (saved === 'true') {
      document.body.classList.add('sidebar-collapsed');
    }
  });
</script>

<script>
  // ── Simpan & restore state nav-group (contoh: Sales Performance) ──
  document.addEventListener('DOMContentLoaded', function () {
    const groups = document.querySelectorAll('.nav-group[data-nav-id]');

    groups.forEach(function (group) {
      const id = group.dataset.navId;
      const parent = group.querySelector('.nav-parent');
      const hasActiveChild = group.querySelector('.nav-item.active, .nav-parent.active');

      const saved = localStorage.getItem('navGroupOpen_' + id);

      let shouldOpen;
      if (saved !== null) {
        // ada preference user sebelum ni → guna itu
        shouldOpen = saved === 'true';
      } else {
        // takde preference lagi → buka hanya kalau page semasa dalam group ni
        shouldOpen = !!hasActiveChild;
      }

      group.classList.toggle('open', shouldOpen);
      if (parent) parent.setAttribute('aria-expanded', String(shouldOpen));
    });
  });

  // ── Update localStorage bila user click buka/tutup group ──
  document.addEventListener('DOMContentLoaded', function () {
    const groups = document.querySelectorAll('.nav-group[data-nav-id]');

    groups.forEach(function (group) {
      const parent = group.querySelector('.nav-parent');
      const id = group.dataset.navId;
      if (!parent) return;

      parent.addEventListener('click', function () {
        // delay sikit supaya baca state LEPAS toggle asal (script atas) jalan dulu
        setTimeout(function () {
          const isOpen = group.classList.contains('open');
          localStorage.setItem('navGroupOpen_' + id, String(isOpen));
        }, 0);
      });
    });
  });
</script>