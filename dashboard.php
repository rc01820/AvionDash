<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fault_inject.php';

session_start_secure();
auth_check();

$pageTitle = 'Dashboard';

// ── Fault hooks (global) ──
FaultEngine::apply('global_always');
FaultEngine::apply('global_auth_check');
FaultEngine::apply('pre_dashboard_render');

// ---- KPI Queries ----
$kpis = [];
try {
    $kpis['airborne']     = DB::scalar("SELECT COUNT(*) FROM flights WHERE status = 'airborne'");
    $kpis['scheduled']    = DB::scalar("SELECT COUNT(*) FROM flights WHERE status IN ('scheduled','boarding') AND scheduled_dep >= NOW()");
    $kpis['active_ac']    = DB::scalar("SELECT COUNT(*) FROM aircraft WHERE status = 'active'");
    $kpis['maintenance']  = DB::scalar("SELECT COUNT(*) FROM aircraft WHERE status = 'maintenance'");
    $kpis['open_alerts']  = DB::scalar("SELECT COUNT(*) FROM system_alerts WHERE is_resolved = 0");
    $kpis['critical_alerts'] = DB::scalar("SELECT COUNT(*) FROM system_alerts WHERE is_resolved = 0 AND severity = 'critical'");
    $kpis['delay_pct']    = DB::scalar("SELECT CAST(100.0 * SUM(CASE WHEN delay_minutes > 15 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) AS DECIMAL(5,1)) FROM flights WHERE scheduled_dep >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $kpis['pax_today']    = DB::scalar("SELECT IFNULL(SUM(passengers_boarded),0) FROM flights WHERE DATE(scheduled_dep) = CURDATE() AND status = 'landed'");

    // Airborne flights detail
    $airborne = DB::query("
        SELECT f.flight_number, ac.tail_number,
               CONCAT(ac.manufacturer, ' ', ac.model) AS aircraft,
               ao.iata_code AS origin, ad.iata_code AS dest,
               f.scheduled_dep, f.scheduled_arr,
               TIMESTAMPDIFF(MINUTE, NOW(), f.scheduled_arr) AS mins_to_arr,
               f.delay_minutes
        FROM flights f
        JOIN aircraft  ac ON ac.aircraft_id = f.aircraft_id
        JOIN airports  ao ON ao.airport_id  = f.origin_airport_id
        JOIN airports  ad ON ad.airport_id  = f.dest_airport_id
        WHERE f.status = 'airborne'
        ORDER BY f.scheduled_arr ASC");

    // ── Fault: N+1 pilot lookups and/or bad data on airborne rows ──
    FaultEngine::apply('post_airborne_query', $airborne);

    // Recent alerts
    $recentAlerts = DB::query("
        SELECT alert_type, severity, message, created_at, is_resolved
        FROM system_alerts
        ORDER BY created_at DESC");

    // On-time performance last 7 days
    $otpData = DB::query("
        SELECT DATE(scheduled_dep) AS flight_date,
               COUNT(*) AS total,
               SUM(CASE WHEN delay_minutes <= 15 THEN 1 ELSE 0 END) AS on_time
        FROM flights
        WHERE scheduled_dep >= DATE_SUB(NOW(), INTERVAL 6 DAY)
          AND status IN ('landed','airborne')
        GROUP BY DATE(scheduled_dep)
        ORDER BY flight_date ASC");

    $dbError = null;
} catch (RuntimeException $e) {
    $dbError = $e->getMessage();
    $kpis = array_fill_keys(['airborne','scheduled','active_ac','maintenance','open_alerts','critical_alerts','delay_pct','pax_today'], 'N/A');
    $airborne = $recentAlerts = $otpData = [];
}

require_once __DIR__ . '/includes/layout_header.php';
?>

<?php if ($dbError): ?>
<div class="db-error-banner">⚠ Database Error: <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<div class="page-header">
  <h1 class="page-title">Operations Dashboard</h1>
  <p class="page-desc">Live overview of fleet status, flight activity, and operational alerts.</p>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card highlight">
    <div class="kpi-label">AIRBORNE NOW</div>
    <div class="kpi-value"><?= $kpis['airborne'] ?></div>
    <div class="kpi-sub">Flights in air</div>
    <div class="kpi-pulse"></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">DEPARTING SOON</div>
    <div class="kpi-value"><?= $kpis['scheduled'] ?></div>
    <div class="kpi-sub">Scheduled / boarding</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">ACTIVE AIRCRAFT</div>
    <div class="kpi-value"><?= $kpis['active_ac'] ?></div>
    <div class="kpi-sub"><?= $kpis['maintenance'] ?> in maintenance</div>
  </div>
  <div class="kpi-card <?= ($kpis['critical_alerts'] > 0) ? 'kpi-danger' : '' ?>">
    <div class="kpi-label">OPEN ALERTS</div>
    <div class="kpi-value"><?= $kpis['open_alerts'] ?></div>
    <div class="kpi-sub"><?= $kpis['critical_alerts'] ?> critical</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">DELAY RATE (24H)</div>
    <div class="kpi-value"><?= $kpis['delay_pct'] ?? '0.0' ?>%</div>
    <div class="kpi-sub">Flights >15 min late</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">PAX TODAY</div>
    <div class="kpi-value"><?= number_format($kpis['pax_today']) ?></div>
    <div class="kpi-sub">Passengers boarded</div>
  </div>
</div>

<!-- Live Flights + Alerts Row -->
<div class="two-col-layout">

  <!-- Airborne Flights -->
  <div class="panel">
    <div class="panel-header">
      <h2 class="panel-title">
        <span class="live-dot"></span> Live Airborne Flights
      </h2>
      <a href="<?= APP_BASE ?>/flights.php" class="panel-link">Full Report →</a>
    </div>
    <div class="panel-body">
      <?php if (empty($airborne)): ?>
      <p class="empty-state">No flights currently airborne.</p>
      <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>FLIGHT</th><th>AIRCRAFT</th><th>ROUTE</th><th>ETA</th><th>DELAY</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($airborne as $f): ?>
          <tr>
            <td><code class="flight-code"><?= htmlspecialchars($f['flight_number']) ?></code></td>
            <td><span class="mono-sm"><?= htmlspecialchars($f['tail_number']) ?></span></td>
            <td>
              <span class="airport-tag"><?= htmlspecialchars($f['origin']) ?></span>
              <svg viewBox="0 0 16 16" class="route-arrow"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
              <span class="airport-tag"><?= htmlspecialchars($f['dest']) ?></span>
            </td>
            <td class="mono-sm">
              <?php $m = (int)$f['mins_to_arr'];
                echo $m > 0 ? "{$m}m" : '<span class="status-chip landed">Arriving</span>'; ?>
            </td>
            <td>
              <?php if ($f['delay_minutes'] > 0): ?>
                <span class="delay-badge"><?= (int)$f['delay_minutes'] ?>m</span>
              <?php else: ?>
                <span class="on-time-badge">On Time</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Alerts -->
  <div class="panel">
    <div class="panel-header">
      <h2 class="panel-title">Recent Alerts</h2>
      <a href="<?= APP_BASE ?>/alerts.php" class="panel-link">All Alerts →</a>
    </div>
    <div class="panel-body">
      <?php if (empty($recentAlerts)): ?>
      <p class="empty-state">No alerts.</p>
      <?php else: ?>
      <ul class="alert-list">
        <?php foreach ($recentAlerts as $a): ?>
        <li class="alert-item sev-<?= htmlspecialchars($a['severity']) ?> <?= $a['is_resolved'] ? 'resolved' : '' ?>">
          <div class="alert-header-row">
            <span class="alert-type"><?= strtoupper(str_replace('_', ' ', $a['alert_type'])) ?></span>
            <span class="alert-sev sev-<?= $a['severity'] ?>"><?= strtoupper($a['severity']) ?></span>
            <?php if ($a['is_resolved']): ?><span class="resolved-tag">RESOLVED</span><?php endif; ?>
          </div>
          <p class="alert-msg"><?= htmlspecialchars($a['message']) ?></p>
          <span class="alert-time"><?= htmlspecialchars($a['created_at']) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- OTP Chart (7-day) -->
<div class="panel mt-4">
  <div class="panel-header">
    <h2 class="panel-title">On-Time Performance — Last 7 Days</h2>
  </div>
  <div class="panel-body">
    <?php if (!empty($otpData)): ?>
    <div class="bar-chart-wrap">
      <?php foreach ($otpData as $day): ?>
        <?php
          $total  = max(1, (int)$day['total']);
          $ot     = (int)$day['on_time'];
          $pct    = round(100 * $ot / $total);
          $dt     = new DateTime($day['flight_date']);
          $label  = $dt->format('M j');
          $color  = $pct >= 80 ? 'var(--green)' : ($pct >= 60 ? 'var(--amber)' : 'var(--red)');
        ?>
        <div class="bar-col">
          <div class="bar-label-top"><?= $pct ?>%</div>
          <div class="bar-track">
            <div class="bar-fill" style="height:<?= $pct ?>%; background:<?= $color ?>;"></div>
          </div>
          <div class="bar-label-bot"><?= $label ?></div>
          <div class="bar-sub"><?= $total ?> flt</div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="empty-state">No historical flight data available.</p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
LIMIT 6