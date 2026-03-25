<?php
// ============================================================
// layout_header.php — Shared page header + navigation
// ============================================================
$user    = current_user();
$current = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="app-base" content="<?= APP_BASE ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'AvionDash') ?> | AvionDash</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@300;400;600;700&family=JetBrains+Mono:wght@300;400;500&family=Barlow:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/style.css">
</head>
<body>

<!-- Sidebar Navigation -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">
      <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M18 4L32 28H4L18 4Z" fill="none" stroke="#38BDF8" stroke-width="1.5"/>
        <path d="M10 28L18 10L26 28" fill="none" stroke="#38BDF8" stroke-width="1" opacity="0.4"/>
        <circle cx="18" cy="22" r="3" fill="#38BDF8"/>
      </svg>
    </div>
    <div class="logo-text">
      <span class="logo-name">AVION<span>DASH</span></span>
      <span class="logo-sub">OPERATIONS MONITOR</span>
    </div>
  </div>

  <div class="nav-section-label">MAIN</div>
  <ul class="nav-links">
    <li class="<?= $current === 'dashboard.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/dashboard.php">
        <svg viewBox="0 0 24 24"><path d="M4 5h16v2H4zM4 11h10v2H4zM4 17h16v2H4z" opacity=".4"/><rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/></svg>
        Dashboard
      </a>
    </li>
    <li class="<?= $current === 'flights.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/flights.php">
        <svg viewBox="0 0 24 24"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5L21 16z"/></svg>
        Flight Operations
      </a>
    </li>
    <li class="<?= $current === 'aircraft.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/aircraft.php">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M2 12h4M18 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
        Aircraft Status
      </a>
    </li>
    <li class="<?= $current === 'airports.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/airports.php">
        <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 010-5 2.5 2.5 0 010 5z"/></svg>
        Airports & Routes
      </a>
    </li>
    <li class="<?= $current === 'alerts.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/alerts.php">
        <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
        Alerts
        <?php
        try {
            $openAlerts = DB::scalar("SELECT COUNT(*) FROM dbo.system_alerts WHERE is_resolved = 0");
            if ($openAlerts > 0): ?>
        <span class="badge-alert"><?= $openAlerts ?></span>
        <?php endif;
        } catch(Exception $e) {} ?>
      </a>
    </li>

    <div class="nav-section-label">ANALYSIS</div>
    <li class="<?= $current === 'reports.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/reports.php">
        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
        Reports
      </a>
    </li>
    <?php if (has_role('analyst', 'admin')): ?>
    <li class="<?= $current === 'query_runner.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/query_runner.php">
        <svg viewBox="0 0 24 24"><path d="M20 17.17L18.83 16H4V4h16v13.17zM20 2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h14l4 4V4c0-1.1-.9-2-2-2z"/></svg>
        Query Runner
      </a>
    </li>
    <?php endif; ?>

    <div class="nav-section-label">INFO</div>
    <li class="<?= $current === 'about.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/about.php">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
        About / Help
      </a>
    </li>
    <?php if (has_role('admin')): ?>
    <?php
    // Show active fault count in nav
    $faultCount = 0;
    try {
        require_once dirname(__DIR__) . '/includes/fault_inject.php';
        $faultCount = FaultEngine::activeCount();
    } catch (Throwable $e) {}
    ?>
    <li class="<?= $current === 'chaos.php' ? 'active' : '' ?>">
      <a href="<?= APP_BASE ?>/chaos.php" style="<?= $faultCount > 0 ? 'color:var(--red)' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        Chaos Control
        <?php if ($faultCount > 0): ?>
        <span class="badge-alert" style="background:var(--red)"><?= $faultCount ?></span>
        <?php endif; ?>
      </a>
    </li>
    <?php endif; ?>
  </ul>

  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
      <div class="user-details">
        <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
        <span class="user-role"><?= strtoupper($user['role']) ?></span>
      </div>
    </div>
    <a href="<?= APP_BASE ?>/logout.php" class="logout-btn" title="Sign Out">
      <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
    </a>
  </div>
</nav>

<!-- Main Content Area -->
<main class="main-content">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" onclick="toggleSidebar()">
        <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
      </button>
      <div class="page-breadcrumb">
        <span class="breadcrumb-app">AvionDash</span>
        <svg viewBox="0 0 16 16" class="breadcrumb-sep"><path d="M6 4l4 4-4 4"/></svg>
        <span class="breadcrumb-page"><?= htmlspecialchars($pageTitle ?? 'Page') ?></span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="live-clock" id="liveClock"></div>
      <div class="status-dot active" title="DB Connected"></div>
    </div>
  </div>
  <div class="page-content">
