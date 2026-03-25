<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fault_inject.php';

session_start_secure();
auth_check();

$pageTitle = 'Aircraft Status';

// ── Fault hooks ──
FaultEngine::apply('global_always');
FaultEngine::apply('global_auth_check');
FaultEngine::apply('pre_aircraft_render'); // may throw 500

try {
    $aircraft = DB::query("
        SELECT a.aircraft_id, a.tail_number, a.manufacturer, a.model,
               a.aircraft_type, a.seat_capacity, a.max_range_nm,
               a.year_manufactured, a.status, a.total_hours,
               ap.iata_code AS base_airport, ap.city AS base_city,
               COUNT(DISTINCT f.flight_id) AS flights_30d,
               IFNULL(SUM(f.distance_nm), 0) AS nm_30d,
               IFNULL(AVG(CAST(f.delay_minutes AS DECIMAL(10,2))), 0) AS avg_delay,
               (SELECT COUNT(*) FROM maintenance_logs ml
                WHERE ml.aircraft_id = a.aircraft_id AND ml.status IN ('open','in_progress')) AS open_mx
        FROM aircraft a
        JOIN airports ap ON ap.airport_id = a.base_airport_id
        LEFT JOIN flights f ON f.aircraft_id = a.aircraft_id
            AND f.scheduled_dep >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY a.aircraft_id, a.tail_number, a.manufacturer, a.model,
                 a.aircraft_type, a.seat_capacity, a.max_range_nm,
                 a.year_manufactured, a.status, a.total_hours,
                 ap.iata_code, ap.city
        ORDER BY a.status ASC, a.tail_number ASC");

    $mxLogs = DB::query("
        SELECT ml.log_id, ml.maintenance_type, ml.description, ml.technician,
               ml.start_date, ml.end_date, ml.status, ml.cost_usd, ml.discrepancy_code,
               a.tail_number
        FROM maintenance_logs ml
        JOIN aircraft a ON a.aircraft_id = ml.aircraft_id
        ORDER BY ml.start_date DESC");

    $dbError = null;
} catch (RuntimeException $e) {
    $dbError = $e->getMessage();
    $aircraft = $mxLogs = [];
}

require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <h1 class="page-title">Aircraft Status</h1>
  <p class="page-desc">Fleet overview, utilization metrics, and maintenance records.</p>
</div>

<?php if ($dbError): ?>
<div class="db-error-banner">⚠ <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<!-- Fleet Summary Strip -->
<?php
$active = array_filter($aircraft, fn($r) => $r['status'] === 'active');
$mx     = array_filter($aircraft, fn($r) => $r['status'] === 'maintenance');
$ret    = array_filter($aircraft, fn($r) => $r['status'] === 'retired');
?>
<div class="kpi-grid tight">
  <div class="kpi-card"><div class="kpi-label">TOTAL FLEET</div><div class="kpi-value"><?= count($aircraft) ?></div></div>
  <div class="kpi-card highlight"><div class="kpi-label">ACTIVE</div><div class="kpi-value"><?= count($active) ?></div></div>
  <div class="kpi-card kpi-warn"><div class="kpi-label">IN MAINTENANCE</div><div class="kpi-value"><?= count($mx) ?></div></div>
  <div class="kpi-card"><div class="kpi-label">RETIRED</div><div class="kpi-value"><?= count($ret) ?></div></div>
</div>

<!-- Aircraft Table -->
<div class="panel mt-4">
  <div class="panel-header"><h2 class="panel-title">Fleet Registry</h2></div>
  <div class="panel-body no-pad">
    <div class="table-scroll">
    <table class="data-table full-width">
      <thead>
        <tr>
          <th>TAIL #</th><th>AIRCRAFT</th><th>TYPE</th><th>SEATS</th>
          <th>RANGE (NM)</th><th>YEAR</th><th>STATUS</th><th>BASE</th>
          <th>TOTAL HRS</th><th>FLIGHTS (30D)</th><th>NM (30D)</th>
          <th>AVG DELAY</th><th>OPEN MX</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($aircraft as $ac): ?>
        <tr>
          <td><code class="flight-code"><?= htmlspecialchars($ac['tail_number']) ?></code></td>
          <td><?= htmlspecialchars($ac['manufacturer'] . ' ' . $ac['model']) ?></td>
          <td><span class="type-badge type-<?= htmlspecialchars($ac['aircraft_type']) ?>"><?= htmlspecialchars(ucfirst($ac['aircraft_type'])) ?></span></td>
          <td class="mono-sm"><?= $ac['seat_capacity'] ?: '—' ?></td>
          <td class="mono-sm"><?= number_format($ac['max_range_nm']) ?></td>
          <td class="mono-sm"><?= $ac['year_manufactured'] ?></td>
          <td><span class="status-chip <?= htmlspecialchars($ac['status']) ?>"><?= strtoupper($ac['status']) ?></span></td>
          <td><span class="airport-tag"><?= htmlspecialchars($ac['base_airport']) ?></span></td>
          <td class="mono-sm"><?= number_format($ac['total_hours'], 1) ?></td>
          <td class="mono-sm"><?= (int)$ac['flights_30d'] ?></td>
          <td class="mono-sm"><?= number_format($ac['nm_30d']) ?></td>
          <td class="mono-sm"><?= round($ac['avg_delay'], 1) ?>m</td>
          <td>
            <?php if ($ac['open_mx'] > 0): ?>
            <span class="delay-badge"><?= (int)$ac['open_mx'] ?></span>
            <?php else: ?>
            <span class="on-time-badge">0</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- Maintenance Logs -->
<div class="panel mt-4">
  <div class="panel-header"><h2 class="panel-title">Maintenance Log</h2></div>
  <div class="panel-body no-pad">
    <div class="table-scroll">
    <table class="data-table full-width">
      <thead>
        <tr>
          <th>TAIL #</th><th>TYPE</th><th>DESCRIPTION</th><th>TECHNICIAN</th>
          <th>START</th><th>END</th><th>STATUS</th><th>DISCREPANCY</th><th>COST (USD)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($mxLogs as $mx): ?>
        <tr>
          <td><code class="flight-code"><?= htmlspecialchars($mx['tail_number']) ?></code></td>
          <td><span class="mx-type-badge"><?= htmlspecialchars(ucfirst($mx['maintenance_type'])) ?></span></td>
          <td class="text-sm" style="max-width:280px"><?= htmlspecialchars($mx['description']) ?></td>
          <td class="text-sm"><?= htmlspecialchars($mx['technician']) ?></td>
          <td class="mono-sm"><?= substr($mx['start_date'], 0, 10) ?></td>
          <td class="mono-sm"><?= $mx['end_date'] ? substr($mx['end_date'], 0, 10) : '—' ?></td>
          <td><span class="status-chip <?= $mx['status'] === 'completed' ? 'landed' : ($mx['status'] === 'in_progress' ? 'airborne' : 'scheduled') ?>"><?= strtoupper($mx['status']) ?></span></td>
          <td class="mono-sm"><?= $mx['discrepancy_code'] ? htmlspecialchars($mx['discrepancy_code']) : '—' ?></td>
          <td class="mono-sm"><?= $mx['cost_usd'] ? '$' . number_format($mx['cost_usd'], 0) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
