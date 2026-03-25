<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fault_inject.php';

session_start_secure();
auth_check();

$pageTitle = 'System Alerts';

// ── Fault hooks ──
FaultEngine::apply('global_always');
FaultEngine::apply('global_auth_check');
FaultEngine::apply('pre_alerts_render'); // may insert cascade alerts

// Resolve action (admin/analyst only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_id']) && has_role('analyst','admin')) {
    $id = (int)$_POST['resolve_id'];
    try {
        DB::query("UPDATE system_alerts SET is_resolved = 1, resolved_at = NOW() WHERE alert_id = ?", [$id]);
        header('Location: ' . APP_BASE . '/alerts.php?resolved=1');
        exit;
    } catch (RuntimeException $e) {}
}

$showResolved = isset($_GET['show_resolved']) ? 1 : 0;

try {
    $sql     = $showResolved
        ? "SELECT * FROM system_alerts ORDER BY created_at DESC"
        : "SELECT * FROM system_alerts WHERE is_resolved = 0 ORDER BY created_at DESC";
    $alerts  = DB::query($sql);
    $dbError = null;
} catch (RuntimeException $e) {
    $dbError = $e->getMessage();
    $alerts  = [];
}

$severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
usort($alerts, fn($a, $b) =>
    ($severityOrder[$a['severity']] ?? 9) <=> ($severityOrder[$b['severity']] ?? 9)
);

require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <h1 class="page-title">System Alerts</h1>
  <p class="page-desc">Operational alerts across flights, aircraft, weather, and maintenance.</p>
</div>

<?php if (isset($_GET['resolved'])): ?>
<div class="alert-banner info">Alert resolved successfully.</div>
<?php endif; ?>

<div class="filter-bar">
  <a href="<?= APP_BASE ?>/alerts.php<?= $showResolved ? '' : '?show_resolved=1' ?>" class="btn-ghost">
    <?= $showResolved ? 'Hide Resolved' : 'Show Resolved' ?>
  </a>
  <div class="filter-count"><?= count($alerts) ?> alert<?= count($alerts) !== 1 ? 's' : '' ?></div>
</div>

<?php if ($dbError): ?>
<div class="db-error-banner">⚠ <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<div class="alerts-grid">
<?php if (empty($alerts)): ?>
  <p class="empty-state" style="padding:2rem">No open alerts. System is nominal.</p>
<?php else: ?>
  <?php foreach ($alerts as $a): ?>
  <div class="alert-card sev-<?= htmlspecialchars($a['severity']) ?> <?= $a['is_resolved'] ? 'resolved' : '' ?>">
    <div class="alert-card-header">
      <div class="alert-meta">
        <span class="alert-type-tag"><?= strtoupper(str_replace('_',' ', $a['alert_type'])) ?></span>
        <span class="sev-pill sev-<?= $a['severity'] ?>"><?= strtoupper($a['severity']) ?></span>
        <?php if ($a['is_resolved']): ?>
        <span class="resolved-tag">✓ RESOLVED</span>
        <?php endif; ?>
      </div>
      <div class="alert-id">#<?= $a['alert_id'] ?></div>
    </div>
    <p class="alert-card-msg"><?= htmlspecialchars($a['message']) ?></p>
    <div class="alert-card-footer">
      <span class="alert-time"><?= htmlspecialchars($a['created_at']) ?></span>
      <?php if (!$a['is_resolved'] && has_role('analyst', 'admin')): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="resolve_id" value="<?= (int)$a['alert_id'] ?>">
        <button type="submit" class="btn-sm-resolve">Mark Resolved</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
