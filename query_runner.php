<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fault_inject.php';

session_start_secure();
auth_check();

// Restricted to analyst and admin
if (!has_role('analyst', 'admin')) {
    header('Location: ' . APP_BASE . '/dashboard.php');
    exit;
}

$pageTitle = 'Query Runner';

// ── Fault hooks ──
FaultEngine::apply('global_always');
FaultEngine::apply('global_auth_check');
FaultEngine::apply('pre_query_runner_render'); // may spike CPU

$savedQueries = [
    'Flights in last 24h'             => "SELECT f.flight_number, ao.iata_code AS origin, ad.iata_code AS dest,\n  f.status, f.delay_minutes, f.passengers_boarded\nFROM flights f\nJOIN airports ao ON ao.airport_id = f.origin_airport_id\nJOIN airports ad ON ad.airport_id = f.dest_airport_id\nWHERE f.scheduled_dep >= DATE_SUB(NOW(), INTERVAL 24 HOUR)\nORDER BY f.scheduled_dep DESC",
    'Aircraft with open maintenance'  => "SELECT a.tail_number, CONCAT(a.manufacturer, ' ', a.model) AS aircraft,\n  ml.maintenance_type, ml.description, ml.status, ml.discrepancy_code\nFROM aircraft a\nJOIN maintenance_logs ml ON ml.aircraft_id = a.aircraft_id\nWHERE ml.status IN ('open','in_progress')\nORDER BY ml.start_date ASC",
    'Delay rate by delay reason'      => "SELECT delay_reason, COUNT(*) AS occurrences,\n  AVG(delay_minutes) AS avg_delay_min, MAX(delay_minutes) AS max_delay\nFROM flights\nWHERE delay_reason IS NOT NULL\nGROUP BY delay_reason\nORDER BY occurrences DESC",
    'Pilots with most flights (30d)'  => "SELECT CONCAT(p.first_name, ' ', p.last_name) AS pilot,\n  p.license_type, COUNT(f.flight_id) AS flights, SUM(f.distance_nm) AS nm\nFROM pilots p\nJOIN flights f ON f.captain_id = p.pilot_id\nWHERE f.scheduled_dep >= DATE_SUB(NOW(), INTERVAL 30 DAY)\nGROUP BY p.first_name, p.last_name, p.license_type\nORDER BY flights DESC",
    'Unresolved critical alerts'      => "SELECT alert_type, severity, message, created_at\nFROM system_alerts\nWHERE is_resolved = 0 AND severity IN ('critical','high')\nORDER BY created_at DESC",
    'Airport traffic summary'         => "SELECT ap.iata_code, ap.name, ap.city,\n  COUNT(DISTINCT f_dep.flight_id) AS departures,\n  COUNT(DISTINCT f_arr.flight_id) AS arrivals\nFROM airports ap\nLEFT JOIN flights f_dep ON f_dep.origin_airport_id = ap.airport_id\nLEFT JOIN flights f_arr ON f_arr.dest_airport_id = ap.airport_id\nGROUP BY ap.iata_code, ap.name, ap.city\nORDER BY departures + arrivals DESC",
];

$result    = null;
$columns   = [];
$execTime  = null;
$sqlInput  = '';
$queryError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sqlInput = trim($_POST['sql'] ?? '');

    if (empty($sqlInput)) {
        $queryError = 'Please enter a SQL query.';
    } elseif (!DB::isReadOnly($sqlInput)) {
        $queryError = 'Only SELECT queries are permitted in the Query Runner. INSERT, UPDATE, DELETE, DROP, and EXEC are blocked.';
    } else {
        try {
            $start    = microtime(true);
            $result   = DB::query($sqlInput);
            $execTime = round((microtime(true) - $start) * 1000, 1);
            if (!empty($result)) {
                $columns = array_keys($result[0]);
            }
        } catch (RuntimeException $e) {
            $queryError = 'Query error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <h1 class="page-title">Query Runner</h1>
  <p class="page-desc">Execute read-only SQL SELECT queries directly against the AviationDB database. <strong>Analyst &amp; Admin only.</strong></p>
</div>

<div class="query-layout">

  <!-- Saved Queries Sidebar -->
  <div class="saved-queries-panel">
    <h3 class="sq-title">SAVED QUERIES</h3>
    <ul class="sq-list">
      <?php foreach ($savedQueries as $name => $sql): ?>
      <li>
        <button class="sq-btn" onclick="loadQuery(<?= htmlspecialchars(json_encode($sql)) ?>)">
          <?= htmlspecialchars($name) ?>
        </button>
      </li>
      <?php endforeach; ?>
    </ul>
    <div class="sq-divider"></div>
    <div class="schema-info">
      <h4 class="si-title">AVAILABLE TABLES</h4>
      <ul class="si-list">
        <li><code>flights</code></li>
        <li><code>aircraft</code></li>
        <li><code>airports</code></li>
        <li><code>pilots</code></li>
        <li><code>maintenance_logs</code></li>
        <li><code>system_alerts</code></li>
        <li><code>users</code></li>
      </ul>
    </div>
  </div>

  <!-- Query Editor + Results -->
  <div class="query-main">
    <form method="POST" action="<?= APP_BASE ?>/query_runner.php">
      <div class="editor-wrap">
        <div class="editor-toolbar">
          <span class="editor-label">SQL EDITOR</span>
          <div class="editor-actions">
            <button type="button" class="btn-ghost sm" onclick="clearEditor()">Clear</button>
            <button type="submit" class="btn-primary sm">▶ Run Query</button>
          </div>
        </div>
        <textarea id="sqlEditor" name="sql" class="sql-editor" spellcheck="false"
                  rows="10" placeholder="SELECT * FROM flights WHERE status = 'airborne'..."><?= htmlspecialchars($sqlInput) ?></textarea>
      </div>
    </form>

    <!-- Query Error -->
    <?php if ($queryError): ?>
    <div class="db-error-banner"><?= htmlspecialchars($queryError) ?></div>
    <?php endif; ?>

    <!-- Results -->
    <?php if ($result !== null): ?>
    <div class="panel mt-4">
      <div class="panel-header">
        <h2 class="panel-title">Results</h2>
        <div class="panel-meta">
          <?= count($result) ?> rows &nbsp;·&nbsp; <?= $execTime ?>ms
        </div>
      </div>
      <div class="panel-body no-pad">
        <?php if (empty($result)): ?>
        <p class="empty-state" style="padding:2rem">Query returned 0 rows.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="data-table full-width">
          <thead>
            <tr>
              <?php foreach ($columns as $col): ?>
              <th><?= htmlspecialchars($col) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($result, 0, 500) as $row): ?>
            <tr>
              <?php foreach ($columns as $col): ?>
              <td class="mono-sm"><?= htmlspecialchars($row[$col] ?? '') ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($result) > 500): ?>
        <div class="result-truncated">⚠ Results truncated to 500 rows for display.</div>
        <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function loadQuery(sql) {
    document.getElementById('sqlEditor').value = sql;
    document.getElementById('sqlEditor').focus();
}
function clearEditor() {
    document.getElementById('sqlEditor').value = '';
    document.getElementById('sqlEditor').focus();
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
