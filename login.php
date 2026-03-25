<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();

if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_BASE . '/dashboard.php');
    exit;
}

$error  = '';
$reason = $_GET['reason'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (auth_login($username, $password)) {
        header('Location: ' . APP_BASE . '/dashboard.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}

$stats = ['airborne' => 0, 'airports' => 0, 'fleet' => 0, 'alerts' => 0];
try {
    $stats['airborne'] = (int) DB::scalar("SELECT COUNT(*) FROM flights WHERE status='airborne'");
    $stats['airports'] = (int) DB::scalar("SELECT COUNT(*) FROM airports");
    $stats['fleet']    = (int) DB::scalar("SELECT COUNT(*) FROM aircraft WHERE status='active'");
    $stats['alerts']   = (int) DB::scalar("SELECT COUNT(*) FROM system_alerts WHERE is_resolved=0");
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In | AvionDash</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@300;400;600;700&family=JetBrains+Mono:wght@300;400;500&family=Barlow:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/style.css">
  <style>
    body { flex-direction: row; align-items: stretch; min-height: 100vh; overflow: hidden; }

    /* ── Left panel ── */
    .lp-left {
      width: 400px; flex-shrink: 0;
      background: var(--bg-surface);
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      justify-content: space-between;
      padding: 48px 40px;
      position: relative; overflow: hidden;
    }
    .lp-left::before {
      content: ''; position: absolute; inset: 0;
      background-image:
        linear-gradient(rgba(56,189,248,0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(56,189,248,0.03) 1px, transparent 1px);
      background-size: 36px 36px; pointer-events: none;
    }
    .radar-wrap {
      position: absolute; top: 120px; left: 50%;
      transform: translateX(-50%); pointer-events: none; z-index: 0;
    }
    .radar-ring {
      position: absolute; border-radius: 50%;
      border: 1px solid rgba(56,189,248,0.07);
      top: 50%; left: 50%;
      transform: translate(-50%,-50%);
      animation: rpulse 4s ease-in-out infinite;
    }
    .r1 { width: 150px; height: 150px; animation-delay: 0s; }
    .r2 { width: 320px; height: 320px; animation-delay: 1.3s; }
    .r3 { width: 540px; height: 540px; animation-delay: 2.6s; }
    @keyframes rpulse { 0%,100%{opacity:.6} 50%{opacity:.08} }

    .lp-brand { position: relative; z-index: 1; }
    .lp-logo { width: 48px; height: 48px; display: block; margin-bottom: 14px; }
    .lp-wordmark {
      font-family: var(--font-head); font-size: 36px;
      font-weight: 700; letter-spacing: 3px; color: var(--text-primary); line-height: 1;
    }
    .lp-wordmark span { color: var(--accent); }
    .lp-tagline {
      font-family: var(--font-head); font-size: 9px;
      letter-spacing: 3px; color: var(--text-muted); margin-top: 6px;
    }
    .lp-desc {
      margin-top: 16px; font-size: 12px;
      color: var(--text-secondary); line-height: 1.7; max-width: 290px;
    }

    .lp-stats { position: relative; z-index: 1; }
    .lp-stats-lbl {
      font-family: var(--font-head); font-size: 9px;
      letter-spacing: 2px; color: var(--text-muted); margin-bottom: 10px;
    }
    .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .stat-tile {
      background: var(--bg-elevated); border: 1px solid var(--border);
      border-radius: var(--radius-md); padding: 12px 14px;
    }
    .stat-tile .val {
      font-family: var(--font-mono); font-size: 22px;
      font-weight: 500; color: var(--accent); line-height: 1;
    }
    .stat-tile .lbl {
      font-family: var(--font-head); font-size: 9px;
      letter-spacing: 1.5px; color: var(--text-muted); margin-top: 4px;
    }
    .stat-tile.warn .val { color: var(--amber); }
    .lp-ver {
      font-family: var(--font-head); font-size: 9px;
      letter-spacing: 1px; color: var(--text-muted); margin-top: 16px;
    }

    /* ── Right panel ── */
    .lp-right {
      flex: 1; display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      background: var(--bg-base); padding: 48px 32px;
    }
    .lp-card { width: 100%; max-width: 340px; }
    .lp-card-title {
      font-family: var(--font-head); font-size: 20px;
      font-weight: 600; letter-spacing: 1px; color: var(--text-primary);
    }
    .lp-card-sub {
      font-size: 12px; color: var(--text-muted); margin-top: 3px; margin-bottom: 24px;
    }

    .alert-banner {
      padding: 10px 14px; border-radius: var(--radius-sm);
      font-size: 12px; margin-bottom: 16px; border-left: 3px solid;
    }
    .alert-banner.error   { background:rgba(239,68,68,.1);  border-color:var(--red);   color:#fca5a5; }
    .alert-banner.warning { background:rgba(245,158,11,.1); border-color:var(--amber); color:#fcd34d; }
    .alert-banner.info    { background:rgba(56,189,248,.1); border-color:var(--accent);color:var(--accent); }

    .lp-form { display: flex; flex-direction: column; gap: 14px; }
    .form-group { display: flex; flex-direction: column; gap: 5px; }
    .form-group label {
      font-family: var(--font-head); font-size: 9px;
      letter-spacing: 1.5px; color: var(--text-muted); font-weight: 600;
    }
    .form-group input {
      background: var(--bg-elevated); border: 1px solid var(--border);
      border-radius: var(--radius-sm); color: var(--text-primary);
      font-family: var(--font-body); font-size: 13px;
      padding: 10px 12px; outline: none; width: 100%;
      transition: border-color 150ms ease, box-shadow 150ms ease;
    }
    .form-group input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(56,189,248,0.08);
    }
    .form-group input::placeholder { color: var(--text-muted); }

    .lp-btn {
      margin-top: 4px; background: var(--accent); color: var(--bg-base);
      border: none; border-radius: var(--radius-sm);
      font-family: var(--font-head); font-weight: 700;
      font-size: 13px; letter-spacing: 2px;
      padding: 11px 20px; cursor: pointer; width: 100%;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: background 150ms ease;
    }
    .lp-btn:hover { background: var(--accent-2); }
    .lp-btn svg { width: 15px; height: 15px; fill: var(--bg-base); }

    .demo-creds {
      margin-top: 24px; background: var(--bg-elevated);
      border: 1px solid var(--border); border-radius: var(--radius-md);
      padding: 14px 16px;
    }
    .demo-creds-lbl {
      font-family: var(--font-head); font-size: 9px;
      letter-spacing: 2px; color: var(--text-muted); margin-bottom: 10px;
    }
    .cred-row {
      display: flex; align-items: center;
      justify-content: space-between;
      padding: 5px 0; border-bottom: 1px solid var(--border);
      font-size: 11px; color: var(--text-secondary);
    }
    .cred-row:last-child { border-bottom: none; padding-bottom: 0; }
    .cred-row code {
      font-family: var(--font-mono); font-size: 10px;
      color: var(--accent); background: var(--bg-panel);
      padding: 2px 6px; border-radius: 2px;
    }
    .cred-role {
      font-family: var(--font-head); font-size: 9px;
      letter-spacing: 1px; color: var(--text-muted);
    }

    @media (max-width: 760px) {
      body { flex-direction: column; overflow: auto; }
      .lp-left { width: 100%; padding: 28px 20px; border-right: none; border-bottom: 1px solid var(--border); }
      .radar-wrap { display: none; }
      .stats-grid { grid-template-columns: repeat(4,1fr); }
      .lp-right { padding: 28px 20px; }
    }
  </style>
</head>
<body>

  <!-- ── LEFT PANEL ─────────────────────────────────────────────────── -->
  <div class="lp-left">
    <div class="radar-wrap">
      <div class="radar-ring r1"></div>
      <div class="radar-ring r2"></div>
      <div class="radar-ring r3"></div>
    </div>

    <div class="lp-brand">
      <svg class="lp-logo" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M24 6L42 38H6L24 6Z" fill="none" stroke="#38BDF8" stroke-width="2"/>
        <path d="M14 38L24 14L34 38" fill="none" stroke="#38BDF8" stroke-width="1.2" opacity="0.4"/>
        <circle cx="24" cy="30" r="4" fill="#38BDF8"/>
      </svg>
      <div class="lp-wordmark">AVION<span>DASH</span></div>
      <div class="lp-tagline">AVIATION OPERATIONS MONITOR</div>
      <p class="lp-desc">
        Real-time fleet tracking, flight operations, maintenance management, and
        operational alerts — purpose-built as a Datadog monitoring demonstration platform.
      </p>
    </div>

    <div class="lp-stats">
      <div class="lp-stats-lbl">LIVE SYSTEM STATUS</div>
      <div class="stats-grid">
        <div class="stat-tile">
          <div class="val"><?= $stats['airborne'] ?></div>
          <div class="lbl">AIRBORNE</div>
        </div>
        <div class="stat-tile">
          <div class="val"><?= $stats['fleet'] ?></div>
          <div class="lbl">ACTIVE FLEET</div>
        </div>
        <div class="stat-tile">
          <div class="val"><?= $stats['airports'] ?></div>
          <div class="lbl">AIRPORTS</div>
        </div>
        <div class="stat-tile <?= $stats['alerts'] > 0 ? 'warn' : '' ?>">
          <div class="val"><?= $stats['alerts'] ?></div>
          <div class="lbl">OPEN ALERTS</div>
        </div>
      </div>
      <div class="lp-ver">AvionDash v<?= APP_VERSION ?> &nbsp;·&nbsp; RHEL · Apache · MariaDB</div>
    </div>
  </div>

  <!-- ── RIGHT PANEL ────────────────────────────────────────────────── -->
  <div class="lp-right">
    <div class="lp-card">

      <div class="lp-card-title">SYSTEM ACCESS</div>
      <div class="lp-card-sub">Enter your credentials to continue</div>

      <?php if ($reason === 'timeout'): ?>
        <div class="alert-banner warning">Session expired — please sign in again.</div>
      <?php elseif ($reason === 'logout'): ?>
        <div class="alert-banner info">Signed out successfully.</div>
      <?php elseif ($reason === 'session_error'): ?>
        <div class="alert-banner error">Session error — please sign in again.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert-banner error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form class="lp-form" method="POST" action="<?= APP_BASE ?>/login.php" autocomplete="on">
        <div class="form-group">
          <label for="username">USERNAME</label>
          <input type="text" id="username" name="username"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 autocomplete="username" spellcheck="false"
                 placeholder="Enter username" required autofocus>
        </div>
        <div class="form-group">
          <label for="password">PASSWORD</label>
          <input type="password" id="password" name="password"
                 autocomplete="current-password"
                 placeholder="Enter password" required>
        </div>
        <button type="submit" class="lp-btn">
          SIGN IN
          <svg viewBox="0 0 24 24"><path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/></svg>
        </button>
      </form>

      <div class="demo-creds">
        <div class="demo-creds-lbl">DEMO CREDENTIALS</div>
        <div class="cred-row">
          <span><code>admin</code> / <code>password</code></span>
          <span class="cred-role">ADMIN</span>
        </div>
        <div class="cred-row">
          <span><code>analyst</code> / <code>password</code></span>
          <span class="cred-role">ANALYST</span>
        </div>
        <div class="cred-row">
          <span><code>viewer</code> / <code>password</code></span>
          <span class="cred-role">VIEWER</span>
        </div>
      </div>

    </div>
  </div>

</body>
</html>
