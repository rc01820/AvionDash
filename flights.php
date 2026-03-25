<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fault_inject.php';

session_start_secure();
auth_check();

$pageTitle = 'Flight Operations';

// ── Fault hooks ──
FaultEngine::apply('global_always');
FaultEngine::apply('global_auth_check');

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$dateFilter   = $_GET['date']   ?? date('Y-m-d');
$search       = trim($_GET['q'] ?? '');

$statusOptions = ['all','scheduled','boarding','airborne','landed','cancelled','diverted'];
if (!in_array($statusFilter, $statusOptions)) $statusFilter = 'all';

try {
    $sql = "
        SELECT f.flight_id, f.flight_number, f.status, f.delay_minutes, f.delay_reason,
               f.passengers_boarded, f.fuel_used_lbs, f.distance_nm,
               f.scheduled_dep, f.actual_dep, f.scheduled_arr, f.actual_arr,
               ac.tail_number, CONCAT(ac.manufacturer, ' ', ac.model) AS aircraft_model,
               ao.iata_code AS origin_code, ao.name AS origin_name,
               ad.iata_code AS dest_code,   ad.name AS dest_name,
               CONCAT(p.first_name, ' ', p.last_name) AS captain
        FROM flights f
        JOIN aircraft ac ON ac.aircraft_id = f.aircraft_id
        JOIN airports ao ON ao.airport_id  = f.origin_airport_id
        JOIN airports ad ON ad.airport_id  = f.dest_airport_id
        JOIN pilots   p  ON p.pilot_id     = f.captain_id
        WHERE DATE(f.scheduled_dep) = ?
    ";
    $params = [$dateFilter];

    if ($statusFilter !== 'all') {
        $sql    .= " AND f.status = ?";
        $params[] = $statusFilter;
    }
    if ($search !== '') {
        $sql    .= " AND (f.flight_number LIKE ? OR ac.tail_number LIKE ? OR ao.iata_code LIKE ? OR ad.iata_code LIKE ?)";
        $like = "%{$search}%";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    $sql .= " ORDER BY f.scheduled_dep ASC";

    // ── Fault: slow query / missing index (modifies SQL before execution) ──
    FaultEngine::apply('pre_flights_query', $sql);

    $flights  = DB::query($sql, $params);

    // ── Fault: corrupt passenger data after query ──
    FaultEngine::apply('post_flights_data', $flights);
    $dbError  = null;
} catch (RuntimeException $e) {
    $dbError = $e->getMessage();
    $flights = [];
}

function statusChip(string $status): string {
    return '<span class="status-chip ' . htmlspecialchars($status) . '">'
         . strtoupper($status) . '</span>';
}

require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <h1 class="page-title">Flight Operations</h1>
  <p class="page-desc">All flights with real-time status, delay tracking, and operational data.</p>
</div>

<!-- Filters Bar -->
<div class="filter-bar">
  <form method="GET" action="<?= APP_BASE ?>/flights.php" class="filter-form">
    <div class="filter-group">
      <label>DATE</label>
      <input type="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">
    </div>
    <div class="filter-group">
      <label>STATUS</label>
      <select name="status">
        <?php foreach ($statusOptions as $s): ?>
        <option value="<?= $s ?>" <?= $s === $statusFilter ? 'selected' : '' ?>><?= strtoupper($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-group">
      <label>SEARCH</label>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Flight #, tail, airport...">
    </div>
    <button type="submit" class="btn-primary">Apply</button>
    <a href="<?= APP_BASE ?>/flights.php" class="btn-ghost">Reset</a>
  </form>
  <div class="filter-count"><?= count($flights) ?> flights found</div>
</div>

<?php if ($dbError): ?>
<div class="db-error-banner">⚠ Database Error: <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<div class="panel">
  <div class="panel-body no-pad">
    <?php if (empty($flights)): ?>
    <p class="empty-state" style="padding:2rem">No flights match your filters for <?= htmlspecialchars($dateFilter) ?>.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table full-width">
      <thead>
        <tr>
          <th>FLIGHT</th>
          <th>STATUS</th>
          <th>AIRCRAFT</th>
          <th>ROUTE</th>
          <th>SCHED DEP</th>
          <th>ACTUAL DEP</th>
          <th>SCHED ARR</th>
          <th>ACTUAL ARR</th>
          <th>DELAY</th>
          <th>PAX</th>
          <th>DIST (NM)</th>
          <th>CAPTAIN</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($flights as $f): ?>
        <tr class="row-status-<?= htmlspecialchars($f['status']) ?>">
          <td><code class="flight-code"><?= htmlspecialchars($f['flight_number']) ?></code></td>
          <td><?= statusChip($f['status']) ?></td>
          <td>
            <span class="mono-sm"><?= htmlspecialchars($f['tail_number']) ?></span><br>
            <span class="text-muted xs"><?= htmlspecialchars($f['aircraft_model']) ?></span>
          </td>
          <td>
            <span class="airport-tag"><?= htmlspecialchars($f['origin_code']) ?></span>
            <svg viewBox="0 0 16 16" class="route-arrow"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
            <span class="airport-tag"><?= htmlspecialchars($f['dest_code']) ?></span>
          </td>
          <td class="mono-sm"><?= substr($f['scheduled_dep'] ?? '', 11, 5) ?></td>
          <td class="mono-sm"><?= $f['actual_dep'] ? substr($f['actual_dep'], 11, 5) : '—' ?></td>
          <td class="mono-sm"><?= substr($f['scheduled_arr'] ?? '', 11, 5) ?></td>
          <td class="mono-sm"><?= $f['actual_arr'] ? substr($f['actual_arr'], 11, 5) : '—' ?></td>
          <td>
            <?php if ((int)$f['delay_minutes'] > 0): ?>
              <span class="delay-badge"><?= (int)$f['delay_minutes'] ?>m</span>
              <?php if ($f['delay_reason']): ?>
              <span class="tooltip" title="<?= htmlspecialchars($f['delay_reason']) ?>">ⓘ</span>
              <?php endif; ?>
            <?php elseif ($f['status'] === 'landed'): ?>
              <span class="on-time-badge">On Time</span>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="mono-sm"><?= $f['passengers_boarded'] ?? '—' ?></td>
          <td class="mono-sm"><?= number_format($f['distance_nm']) ?></td>
          <td class="text-sm"><?= htmlspecialchars($f['captain']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
