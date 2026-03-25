<?php
// ============================================================
// chaos.php — Fault Injection Control Panel
// Admin-only page to arm / disarm monitoring test scenarios
// ============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fault_inject.php';

session_start_secure();
auth_check();

// Admin only
if (!has_role('admin')) {
    header('Location: ' . APP_BASE . '/dashboard.php');
    exit;
}

$pageTitle = 'Chaos Control';
$feedback  = null;

// ── Handle API-style toggle requests (AJAX or form POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $faultId = $_POST['fault_id'] ?? '';

    // Validate fault ID
    if (!array_key_exists($faultId, FaultEngine::$catalogue) && $action !== 'reset_all' && $action !== 'arm_all') {
        http_response_code(400);
        if (!empty($_POST['ajax'])) { echo json_encode(['error' => 'invalid fault_id']); exit; }
    }

    switch ($action) {
        case 'toggle':
            $newState = FaultEngine::toggle($faultId);
            if (!empty($_POST['ajax'])) {
                echo json_encode(['id' => $faultId, 'active' => $newState, 'count' => FaultEngine::activeCount()]);
                exit;
            }
            $feedback = ($newState ? '🟠 ARMED' : '✅ DISARMED') . ': ' . FaultEngine::$catalogue[$faultId]['label'];
            break;

        case 'enable':
            FaultEngine::enable($faultId);
            if (!empty($_POST['ajax'])) {
                echo json_encode(['id' => $faultId, 'active' => true, 'count' => FaultEngine::activeCount()]);
                exit;
            }
            break;

        case 'disable':
            FaultEngine::disable($faultId);
            if (!empty($_POST['ajax'])) {
                echo json_encode(['id' => $faultId, 'active' => false, 'count' => FaultEngine::activeCount()]);
                exit;
            }
            break;

        case 'reset_all':
            $state = [];
            foreach (FaultEngine::$catalogue as $id => $_) { $state[$id] = false; }
            FaultEngine::saveState($state);
            FaultEngine::init(); // reload
            if (!empty($_POST['ajax'])) { echo json_encode(['reset' => true, 'count' => 0]); exit; }
            $feedback = '✅ All faults disarmed.';
            break;

        case 'arm_all':
            $state = [];
            foreach (FaultEngine::$catalogue as $id => $_) { $state[$id] = true; }
            FaultEngine::saveState($state);
            FaultEngine::init();
            if (!empty($_POST['ajax'])) { echo json_encode(['armed_all' => true, 'count' => count(FaultEngine::$catalogue)]); exit; }
            $feedback = '🚨 All faults ARMED.';
            break;
    }
    header('Location: ' . APP_BASE . '/chaos.php' . ($feedback ? '?msg=' . urlencode($feedback) : ''));
    exit;
}

if (isset($_GET['msg'])) $feedback = urldecode($_GET['msg']);

$state   = FaultEngine::allState();
$active  = FaultEngine::activeCount();

// Group catalogue by category
$grouped = [];
foreach (FaultEngine::$catalogue as $id => $def) {
    $grouped[$def['category']][$id] = $def;
}

$categoryMeta = [
    'database'      => ['label' => 'Database',      'color' => '#38bdf8', 'icon' => '🗄'],
    'web'           => ['label' => 'Web / HTTP',     'color' => '#fb923c', 'icon' => '🌐'],
    'application'   => ['label' => 'Application',   'color' => '#a78bfa', 'icon' => '⚙'],
    'observability' => ['label' => 'Observability',  'color' => '#f59e0b', 'icon' => '📡'],
];

require_once __DIR__ . '/includes/layout_header.php';
?>

<style>
/* ── Chaos Panel Specific Styles ── */
.chaos-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 24px;
}

.chaos-title-block {}
.chaos-title {
  font-family: var(--font-head);
  font-size: 28px;
  font-weight: 700;
  letter-spacing: 1px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.chaos-title .skull { font-size: 24px; }
.chaos-subtitle { color: var(--text-secondary); font-size: 13px; margin-top: 4px; }

.chaos-status-bar {
  background: var(--bg-panel);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 12px 20px;
  display: flex;
  align-items: center;
  gap: 20px;
}

.cstatus-label {
  font-family: var(--font-head);
  font-size: 10px;
  letter-spacing: 1.5px;
  color: var(--text-muted);
}

.cstatus-count {
  font-family: var(--font-mono);
  font-size: 28px;
  font-weight: 500;
  line-height: 1;
  color: var(--text-primary);
  transition: color 0.3s;
}
.cstatus-count.armed { color: var(--red); text-shadow: 0 0 12px var(--red); }

.cstatus-of {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--text-muted);
}

.chaos-bulk-btns {
  display: flex;
  gap: 8px;
  margin-left: auto;
}

.btn-arm-all {
  background: rgba(239,68,68,0.15);
  border: 1px solid rgba(239,68,68,0.4);
  color: var(--red);
  border-radius: var(--radius-sm);
  font-family: var(--font-head);
  font-size: 12px;
  letter-spacing: 0.8px;
  padding: 7px 16px;
  cursor: pointer;
  transition: all 0.15s;
}
.btn-arm-all:hover { background: rgba(239,68,68,0.25); }

.btn-disarm-all {
  background: rgba(34,197,94,0.1);
  border: 1px solid rgba(34,197,94,0.3);
  color: var(--green);
  border-radius: var(--radius-sm);
  font-family: var(--font-head);
  font-size: 12px;
  letter-spacing: 0.8px;
  padding: 7px 16px;
  cursor: pointer;
  transition: all 0.15s;
}
.btn-disarm-all:hover { background: rgba(34,197,94,0.2); }

/* ── Category Sections ── */
.chaos-category { margin-bottom: 24px; }

.cat-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--border);
}

.cat-icon { font-size: 16px; }

.cat-label {
  font-family: var(--font-head);
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 1px;
}

.cat-count {
  font-family: var(--font-mono);
  font-size: 10px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  padding: 1px 6px;
  border-radius: 10px;
  color: var(--text-muted);
  margin-left: auto;
}

/* ── Fault Cards ── */
.fault-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
  gap: 12px;
}

.fault-card {
  background: var(--bg-panel);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 16px;
  position: relative;
  overflow: hidden;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.fault-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: var(--border-light);
  transition: background 0.3s;
}

.fault-card.active {
  border-color: rgba(239,68,68,0.4);
  box-shadow: 0 0 16px rgba(239,68,68,0.08), inset 0 0 40px rgba(239,68,68,0.03);
}

.fault-card.active::before { background: var(--red); }

.fault-card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.fault-card-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.fault-icon { font-size: 18px; line-height: 1; }

.fault-label {
  font-family: var(--font-head);
  font-size: 15px;
  font-weight: 600;
  letter-spacing: 0.3px;
  color: var(--text-primary);
}

.fault-hook-tag {
  font-family: var(--font-mono);
  font-size: 9px;
  background: var(--bg-elevated);
  color: var(--text-muted);
  border: 1px solid var(--border);
  padding: 1px 5px;
  border-radius: 2px;
  letter-spacing: 0.5px;
  display: block;
  margin-top: 2px;
}

/* Toggle Switch */
.toggle-switch {
  position: relative;
  width: 48px;
  height: 26px;
  flex-shrink: 0;
}

.toggle-switch input { display: none; }

.toggle-slider {
  position: absolute;
  inset: 0;
  background: var(--bg-elevated);
  border: 1px solid var(--border-light);
  border-radius: 13px;
  cursor: pointer;
  transition: all 0.25s;
}

.toggle-slider::before {
  content: '';
  position: absolute;
  width: 18px; height: 18px;
  background: var(--text-muted);
  border-radius: 50%;
  top: 3px; left: 3px;
  transition: all 0.25s;
}

.toggle-switch input:checked + .toggle-slider {
  background: rgba(239,68,68,0.2);
  border-color: rgba(239,68,68,0.5);
}

.toggle-switch input:checked + .toggle-slider::before {
  background: var(--red);
  transform: translateX(22px);
  box-shadow: 0 0 8px var(--red);
}

/* Fault description rows */
.fault-desc {
  font-size: 12px;
  color: var(--text-secondary);
  line-height: 1.5;
  margin-bottom: 10px;
}

.fault-meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  border-top: 1px solid rgba(30,45,69,0.6);
  padding-top: 10px;
  margin-top: 4px;
}

.fault-meta-row {
  display: flex;
  gap: 8px;
  font-size: 11px;
  align-items: flex-start;
}

.fmr-key {
  font-family: var(--font-head);
  font-size: 9px;
  letter-spacing: 1px;
  color: var(--text-muted);
  font-weight: 600;
  white-space: nowrap;
  width: 55px;
  padding-top: 1px;
  flex-shrink: 0;
}

.fmr-val {
  color: var(--text-secondary);
  line-height: 1.4;
}

.fmr-val.effect  { color: var(--amber); }
.fmr-val.detects { color: var(--accent); }

/* Status indicator on card */
.fault-status-badge {
  position: absolute;
  top: 10px; right: 62px;
  font-family: var(--font-head);
  font-size: 9px;
  letter-spacing: 1.2px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 2px;
  opacity: 0;
  transition: opacity 0.2s;
}
.fault-card.active .fault-status-badge {
  background: rgba(239,68,68,0.15);
  color: var(--red);
  border: 1px solid rgba(239,68,68,0.3);
  opacity: 1;
}

/* Severity pill */
.sev-chip {
  font-family: var(--font-head);
  font-size: 9px;
  letter-spacing: 0.8px;
  padding: 1px 5px;
  border-radius: 2px;
}
.sev-chip.critical { background: rgba(239,68,68,0.12); color: var(--red); }
.sev-chip.high     { background: rgba(251,146,60,0.12); color: var(--orange); }
.sev-chip.medium   { background: rgba(245,158,11,0.12); color: var(--amber); }
.sev-chip.low      { background: rgba(34,197,94,0.12);  color: var(--green); }

/* Feedback banner */
.feedback-flash {
  padding: 10px 16px;
  background: rgba(56,189,248,0.08);
  border: 1px solid rgba(56,189,248,0.2);
  border-radius: var(--radius-sm);
  color: var(--accent);
  font-size: 13px;
  margin-bottom: 16px;
}

/* Warning stripe at top when faults active */
.armed-warning {
  background: repeating-linear-gradient(
    45deg,
    rgba(239,68,68,0.08),
    rgba(239,68,68,0.08) 10px,
    transparent 10px,
    transparent 20px
  );
  border: 1px solid rgba(239,68,68,0.2);
  border-radius: var(--radius-sm);
  padding: 10px 16px;
  margin-bottom: 16px;
  font-size: 12px;
  color: #fca5a5;
  display: flex;
  align-items: center;
  gap: 8px;
}
.armed-warning.hidden { display: none; }

/* Quick scenario presets */
.preset-bar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 20px;
  padding: 12px;
  background: var(--bg-panel);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  align-items: center;
}

.preset-label {
  font-family: var(--font-head);
  font-size: 9px;
  letter-spacing: 1.5px;
  color: var(--text-muted);
  font-weight: 600;
  white-space: nowrap;
}

.preset-btn {
  background: var(--bg-elevated);
  border: 1px solid var(--border-light);
  color: var(--text-secondary);
  border-radius: var(--radius-sm);
  font-family: var(--font-head);
  font-size: 11px;
  letter-spacing: 0.5px;
  padding: 5px 12px;
  cursor: pointer;
  transition: all 0.15s;
}
.preset-btn:hover {
  border-color: var(--accent);
  color: var(--accent);
  background: rgba(56,189,248,0.05);
}

/* Activity log */
.chaos-log {
  background: var(--bg-base);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 12px 14px;
  height: 120px;
  overflow-y: auto;
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--text-muted);
  margin-top: 16px;
}

.log-entry { line-height: 1.8; }
.log-entry .ts { color: var(--text-muted); margin-right: 6px; }
.log-entry .arm   { color: var(--red); }
.log-entry .disarm { color: var(--green); }
.log-entry .reset  { color: var(--accent); }
</style>

<?php if ($feedback): ?>
<div class="feedback-flash"><?= htmlspecialchars($feedback) ?></div>
<?php endif; ?>

<div id="armedWarning" class="armed-warning <?= $active === 0 ? 'hidden' : '' ?>">
  ⚠ <strong><?= $active ?> fault<?= $active !== 1 ? 's' : '' ?> currently ARMED</strong> —
  Application behaviour is degraded. Monitoring alerts should be triggering.
</div>

<!-- Header -->
<div class="chaos-header">
  <div class="chaos-title-block">
    <div class="chaos-title">
      <span class="skull">☠</span> Chaos Control Panel
    </div>
    <p class="chaos-subtitle">
      Arm fault scenarios to generate detectable anomalies for monitoring validation.
      All faults can be toggled without restarting IIS or the application.
    </p>
  </div>

  <div class="chaos-status-bar">
    <div>
      <div class="cstatus-label">ACTIVE FAULTS</div>
      <div class="cstatus-count <?= $active > 0 ? 'armed' : '' ?>" id="activeCount"><?= $active ?></div>
    </div>
    <div class="cstatus-of">/ <?= count(FaultEngine::$catalogue) ?></div>
    <div class="chaos-bulk-btns">
      <button class="btn-arm-all" onclick="bulkAction('arm_all')">☠ ARM ALL</button>
      <button class="btn-disarm-all" onclick="bulkAction('reset_all')">✓ DISARM ALL</button>
    </div>
  </div>
</div>

<!-- Quick Presets -->
<div class="preset-bar">
  <span class="preset-label">QUICK SCENARIOS</span>
  <button class="preset-btn" onclick="applyPreset(['slow_flights_query','slow_page_reports'])">
    🐢 Slow Everything
  </button>
  <button class="preset-btn" onclick="applyPreset(['page_500_aircraft','auth_flap'])">
    💥 Error Storm
  </button>
  <button class="preset-btn" onclick="applyPreset(['n_plus_one_pilots','missing_index_scan','connection_pool_exhaust'])">
    🗄 DB Pressure
  </button>
  <button class="preset-btn" onclick="applyPreset(['log_flood','alert_cascade','bad_data_passengers'])">
    📊 Data Chaos
  </button>
  <button class="preset-btn" onclick="applyPreset(['memory_leak_dashboard','cpu_spike_query_runner'])">
    🔥 Resource Abuse
  </button>
</div>

<!-- Fault Cards grouped by category -->
<?php foreach ($grouped as $category => $faults): ?>
<?php $meta = $categoryMeta[$category] ?? ['label' => ucfirst($category), 'color' => '#fff', 'icon' => '●']; ?>

<div class="chaos-category">
  <div class="cat-header">
    <span class="cat-icon"><?= $meta['icon'] ?></span>
    <span class="cat-label" style="color:<?= $meta['color'] ?>"><?= $meta['label'] ?></span>
    <span class="cat-count">
      <?= count(array_filter($faults, fn($f) => $state[$f['id']] ?? false)) ?>/<?= count($faults) ?> armed
    </span>
  </div>

  <div class="fault-grid">
  <?php foreach ($faults as $id => $def): ?>
  <?php $isActive = (bool)($state[$id] ?? false); ?>
  <div class="fault-card <?= $isActive ? 'active' : '' ?>" id="card-<?= $id ?>">
    <span class="fault-status-badge">◉ ARMED</span>

    <div class="fault-card-top">
      <div class="fault-card-title">
        <span class="fault-icon"><?= $def['icon'] ?></span>
        <div>
          <div class="fault-label"><?= htmlspecialchars($def['label']) ?></div>
          <div style="display:flex; gap:5px; margin-top:3px; align-items:center">
            <span class="sev-chip <?= $def['severity'] ?>"><?= strtoupper($def['severity']) ?></span>
            <code class="fault-hook-tag"><?= htmlspecialchars($def['hook']) ?></code>
          </div>
        </div>
      </div>

      <!-- Toggle Switch -->
      <label class="toggle-switch" title="Toggle fault">
        <input type="checkbox"
               <?= $isActive ? 'checked' : '' ?>
               onchange="toggleFault('<?= $id ?>', this)">
        <span class="toggle-slider"></span>
      </label>
    </div>

    <p class="fault-desc"><?= htmlspecialchars($def['description']) ?></p>

    <div class="fault-meta">
      <div class="fault-meta-row">
        <span class="fmr-key">EFFECT</span>
        <span class="fmr-val effect"><?= htmlspecialchars($def['effect']) ?></span>
      </div>
      <div class="fault-meta-row">
        <span class="fmr-key">DETECTS</span>
        <span class="fmr-val detects"><?= htmlspecialchars($def['detects']) ?></span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<!-- Activity Log -->
<div class="panel mt-4">
  <div class="panel-header">
    <h2 class="panel-title">Session Activity Log</h2>
    <button class="btn-ghost sm" onclick="document.getElementById('chaosLog').innerHTML=''">Clear</button>
  </div>
  <div class="chaos-log" id="chaosLog">
    <div class="log-entry"><span class="ts"><?= date('H:i:s') ?></span><span class="reset">Chaos panel loaded. <?= $active ?> fault(s) currently armed.</span></div>
  </div>
</div>

<script>
const APP_BASE = '<?= APP_BASE ?>';
// ── Core toggle function (AJAX) ──
async function toggleFault(faultId, checkbox) {
  const card = document.getElementById('card-' + faultId);
  const arming = checkbox.checked;

  try {
    const res  = await postFaultAction('toggle', faultId);
    const data = await res.json();

    if (data.saved === false) {
      // Write to disk failed — revert the UI and show a persistent error
      checkbox.checked = !arming;
      showSaveError();
      logEntry(faultId, 'disarm', `⚠ SAVE FAILED: ${faultId} — check storage/ permissions`);
      return;
    }

    updateCard(card, data.active);
    updateGlobalCount(data.count);
    logEntry(faultId, data.active ? 'arm' : 'disarm',
      data.active ? `ARMED: ${faultId}` : `Disarmed: ${faultId}`);
  } catch (e) {
    checkbox.checked = !arming; // Revert
    console.error('Toggle failed', e);
  }
}

function showSaveError() {
  let banner = document.getElementById('save-error-banner');
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'save-error-banner';
    banner.style.cssText = 'background:rgba(239,68,68,.15);border:1px solid #ef4444;border-left:4px solid #ef4444;' +
      'color:#fca5a5;padding:12px 16px;margin-bottom:16px;border-radius:4px;font-size:13px;';
    banner.innerHTML = '<strong>⚠ Storage write failed</strong> — fault state cannot be saved to disk.<br>' +
      '<code style="font-size:11px;opacity:.8">storage/faults.json</code> is not writable by the Apache process.<br><br>' +
      'Run on the server:<br>' +
      '<code style="font-size:11px;display:block;margin-top:6px;background:rgba(0,0,0,.3);padding:6px 8px;border-radius:3px;">' +
      'chown apache:apache /var/www/html/aviondash/storage/faults.json<br>' +
      'chmod 660 /var/www/html/aviondash/storage/faults.json<br>' +
      'semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/aviondash/storage(/.*)?"<br>' +
      'restorecon -Rv /var/www/html/aviondash/storage/' +
      '</code>';
    const container = document.querySelector('.chaos-container') || document.body;
    container.insertBefore(banner, container.firstChild);
  }
  banner.style.display = 'block';
}

async function bulkAction(action) {
  try {
    const res  = await postFaultAction(action, '');
    const data = await res.json();
    updateGlobalCount(data.count ?? 0);

    // Update all cards
    document.querySelectorAll('.fault-card').forEach(card => {
      const isActive = action === 'arm_all';
      updateCard(card, isActive);
      const cb = card.querySelector('input[type=checkbox]');
      if (cb) cb.checked = isActive;
    });

    logEntry('BULK', action === 'arm_all' ? 'arm' : 'reset',
      action === 'arm_all' ? '☠ ALL FAULTS ARMED' : '✓ All faults disarmed');
  } catch(e) {
    console.error('Bulk action failed', e);
  }
}

async function applyPreset(faultIds) {
  // First disarm all, then arm the preset list
  await postFaultAction('reset_all', '').catch(()=>{});

  document.querySelectorAll('.fault-card').forEach(card => {
    updateCard(card, false);
    const cb = card.querySelector('input[type=checkbox]');
    if (cb) cb.checked = false;
  });

  let count = 0;
  for (const id of faultIds) {
    const res  = await postFaultAction('enable', id);
    const data = await res.json();
    const card = document.getElementById('card-' + id);
    if (card) {
      updateCard(card, true);
      const cb = card.querySelector('input[type=checkbox]');
      if (cb) cb.checked = true;
    }
    count = data.count;
  }

  updateGlobalCount(count);
  logEntry('PRESET', 'arm', `Preset applied: [${faultIds.join(', ')}]`);
}

function updateCard(card, active) {
  if (!card) return;
  if (active) {
    card.classList.add('active');
  } else {
    card.classList.remove('active');
  }
}

function updateGlobalCount(count) {
  const el = document.getElementById('activeCount');
  if (el) {
    el.textContent = count;
    el.className = 'cstatus-count' + (count > 0 ? ' armed' : '');
  }
  const warn = document.getElementById('armedWarning');
  if (warn) {
    if (count > 0) {
      warn.classList.remove('hidden');
      const strong = warn.querySelector('strong');
      if (strong) strong.textContent = `${count} fault${count !== 1 ? 's' : ''} currently ARMED`;
    } else {
      warn.classList.add('hidden');
    }
  }
}

function logEntry(id, type, msg) {
  const log = document.getElementById('chaosLog');
  if (!log) return;
  const now = new Date();
  const ts  = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')}`;
  const div = document.createElement('div');
  div.className = 'log-entry';
  div.innerHTML = `<span class="ts">${ts}</span><span class="${type}">${msg}</span>`;
  log.prepend(div);
  // Keep max 50 entries
  while (log.children.length > 50) log.removeChild(log.lastChild);
}

function postFaultAction(action, faultId) {
  const body = new URLSearchParams({ action, fault_id: faultId, ajax: '1' });
  return fetch(APP_BASE + '/chaos.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString()
  });
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
