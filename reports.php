<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fault_inject.php';

session_start_secure();
auth_check();

$pageTitle    = 'Reports';
$activeReport = $_GET['report'] ?? 'delay_summary';

// ── Fault hooks: sleep + connection leak fire BEFORE queries ──
FaultEngine::apply('global_always');
FaultEngine::apply('global_auth_check');
FaultEngine::apply('pre_reports_render');

$reports = [
    'delay_summary'       => 'Delay Summary (30 Days)',
    'aircraft_utilization'=> 'Aircraft Utilization',
    'pilot_activity'      => 'Pilot Activity Log',
    'route_performance'   => 'Route Performance',
    'maintenance_costs'   => 'Maintenance Costs',
    'fleet_type_breakdown'=> 'Fleet Type Breakdown',
];

$rows    = [];
$columns = [];
$dbError = null;

try {
    switch ($activeReport) {

        case 'delay_summary':
            $rows = DB::query("
                SELECT DATE(scheduled_dep) AS flight_date,
                       COUNT(*) AS total_flights,
                       SUM(CASE WHEN delay_minutes > 15 THEN 1 ELSE 0 END) AS delayed_flights,
                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancellations,
                       CAST(AVG(CAST(delay_minutes AS DECIMAL(10,2))) AS DECIMAL(5,1)) AS avg_delay_min,
                       MAX(delay_minutes) AS max_delay_min,
                       SUM(delay_minutes) AS total_delay_min
                FROM flights
                WHERE scheduled_dep >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(scheduled_dep)
                ORDER BY flight_date DESC");
            $columns = ['flight_date','total_flights','delayed_flights','cancellations','avg_delay_min','max_delay_min','total_delay_min'];
            break;

        case 'aircraft_utilization':
            $rows = DB::query("
                SELECT a.tail_number,
                       CONCAT(a.manufacturer, ' ', a.model) AS aircraft,
                       a.aircraft_type, a.status, a.total_hours,
                       COUNT(f.flight_id) AS flights_30d,
                       IFNULL(SUM(f.distance_nm), 0) AS nm_30d,
                       IFNULL(SUM(f.passengers_boarded), 0) AS pax_30d,
                       CAST(IFNULL(AVG(CAST(f.delay_minutes AS DECIMAL(10,2))),0) AS DECIMAL(5,1)) AS avg_delay
                FROM aircraft a
                LEFT JOIN flights f ON f.aircraft_id = a.aircraft_id
                    AND f.scheduled_dep >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY a.tail_number, a.manufacturer, a.model, a.aircraft_type, a.status, a.total_hours
                ORDER BY flights_30d DESC");
            $columns = ['tail_number','aircraft','aircraft_type','status','total_hours','flights_30d','nm_30d','pax_30d','avg_delay'];
            break;

        case 'pilot_activity':
            $rows = DB::query("
                SELECT p.employee_id, CONCAT(p.first_name, ' ', p.last_name) AS pilot_name,
                       p.license_type, p.total_hours, p.status,
                       COUNT(f.flight_id) AS flights_30d,
                       IFNULL(SUM(f.distance_nm),0) AS nm_commanded,
                       IFNULL(SUM(f.passengers_boarded),0) AS pax_carried,
                       CAST(IFNULL(AVG(CAST(f.delay_minutes AS DECIMAL(10,2))),0) AS DECIMAL(5,1)) AS avg_delay
                FROM pilots p
                LEFT JOIN flights f ON f.captain_id = p.pilot_id
                    AND f.scheduled_dep >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY p.employee_id, p.first_name, p.last_name, p.license_type, p.total_hours, p.status
                ORDER BY flights_30d DESC");
            $columns = ['employee_id','pilot_name','license_type','total_hours','status','flights_30d','nm_commanded','pax_carried','avg_delay'];
            break;

        case 'route_performance':
            $rows = DB::query("
                SELECT CONCAT(ao.iata_code, '-', ad.iata_code) AS route,
                       CONCAT(ao.city, ' → ', ad.city) AS cities,
                       COUNT(*) AS total_flights,
                       SUM(CASE WHEN delay_minutes > 15 THEN 1 ELSE 0 END) AS delayed,
                       CAST(100.0 * SUM(CASE WHEN delay_minutes <= 15 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) AS DECIMAL(5,1)) AS otp_pct,
                       CAST(AVG(CAST(delay_minutes AS DECIMAL(10,2))) AS DECIMAL(5,1)) AS avg_delay,
                       IFNULL(SUM(passengers_boarded),0) AS total_pax,
                       AVG(f.distance_nm) AS avg_nm
                FROM flights f
                JOIN airports ao ON ao.airport_id = f.origin_airport_id
                JOIN airports ad ON ad.airport_id = f.dest_airport_id
                GROUP BY ao.iata_code, ad.iata_code, ao.city, ad.city
                HAVING COUNT(*) > 0
                ORDER BY total_flights DESC");
            $columns = ['route','cities','total_flights','delayed','otp_pct','avg_delay','total_pax','avg_nm'];
            break;

        case 'maintenance_costs':
            $rows = DB::query("
                SELECT a.tail_number, CONCAT(a.manufacturer, ' ', a.model) AS aircraft,
                       ml.maintenance_type,
                       COUNT(*) AS event_count,
                       SUM(ml.cost_usd) AS total_cost,
                       AVG(ml.cost_usd) AS avg_cost,
                       MAX(ml.cost_usd) AS max_cost,
                       SUM(CASE WHEN ml.status = 'open' OR ml.status = 'in_progress' THEN 1 ELSE 0 END) AS open_events
                FROM maintenance_logs ml
                JOIN aircraft a ON a.aircraft_id = ml.aircraft_id
                GROUP BY a.tail_number, a.manufacturer, a.model, ml.maintenance_type
                ORDER BY total_cost DESC");
            $columns = ['tail_number','aircraft','maintenance_type','event_count','total_cost','avg_cost','max_cost','open_events'];
            break;

        case 'fleet_type_breakdown':
            $rows = DB::query("
                SELECT a.aircraft_type,
                       COUNT(*) AS fleet_count,
                       AVG(a.seat_capacity) AS avg_seats,
                       AVG(a.max_range_nm) AS avg_range_nm,
                       AVG(a.total_hours) AS avg_hours,
                       SUM(CASE WHEN a.status = 'active' THEN 1 ELSE 0 END) AS active_count,
                       SUM(CASE WHEN a.status = 'maintenance' THEN 1 ELSE 0 END) AS mx_count
                FROM aircraft a
                GROUP BY a.aircraft_type
                ORDER BY fleet_count DESC");
            $columns = ['aircraft_type','fleet_count','avg_seats','avg_range_nm','avg_hours','active_count','mx_count'];
            break;
    }
} catch (RuntimeException $e) {
    $dbError = $e->getMessage();
}

require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <h1 class="page-title">Reports</h1>
  <p class="page-desc">Pre-built operational reports drawn live from the database.</p>
</div>

<!-- Report Selector -->
<div class="report-tabs">
  <?php foreach ($reports as $key => $label): ?>
  <a href="<?= APP_BASE ?>/reports.php?report=<?= $key ?>"
     class="report-tab <?= $activeReport === $key ? 'active' : '' ?>">
     <?= htmlspecialchars($label) ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($dbError): ?>
<div class="db-error-banner">⚠ <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<div class="panel mt-4">
  <div class="panel-header">
    <h2 class="panel-title"><?= htmlspecialchars($reports[$activeReport] ?? '') ?></h2>
    <span class="panel-meta"><?= count($rows) ?> rows</span>
  </div>
  <div class="panel-body no-pad">
    <?php if (empty($rows)): ?>
    <p class="empty-state" style="padding:2rem">No data returned for this report.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table full-width">
      <thead>
        <tr>
          <?php foreach ($columns as $col): ?>
          <th><?= strtoupper(str_replace('_', ' ', $col)) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <?php foreach ($columns as $col): ?>
          <td class="mono-sm"><?php
            $val = $row[$col] ?? '—';
            if ($val === null || $val === '') $val = '—';
            if (is_float($val) || (is_string($val) && str_contains($col, 'cost'))) {
                if (str_contains($col, 'cost') && is_numeric($val)) {
                    echo '$' . number_format((float)$val, 0);
                } else {
                    echo htmlspecialchars((string)$val);
                }
            } else {
                echo htmlspecialchars((string)$val);
            }
          ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
