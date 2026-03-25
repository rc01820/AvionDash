<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
auth_check();

$pageTitle = 'Airports & Routes';

try {
    $airports = DB::query("
        SELECT a.airport_id, a.icao_code, a.iata_code, a.name, a.city, a.country,
               a.elevation_ft, a.timezone, a.is_hub,
               dep.dep_count, arr.arr_count,
               IFNULL(dep.dep_count,0) + IFNULL(arr.arr_count,0) AS total_ops
        FROM airports a
        LEFT JOIN (
            SELECT origin_airport_id, COUNT(*) AS dep_count
            FROM flights
            WHERE scheduled_dep >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY origin_airport_id
        ) dep ON dep.origin_airport_id = a.airport_id
        LEFT JOIN (
            SELECT dest_airport_id, COUNT(*) AS arr_count
            FROM flights
            WHERE scheduled_dep >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY dest_airport_id
        ) arr ON arr.dest_airport_id = a.airport_id
        ORDER BY total_ops DESC");

    $topRoutes = DB::query("
        SELECT ao.iata_code AS origin, ao.city AS origin_city,
               ad.iata_code AS dest,   ad.city AS dest_city,
               COUNT(*)               AS flights,
               AVG(f.distance_nm)     AS avg_nm,
               AVG(f.delay_minutes)   AS avg_delay,
               SUM(f.passengers_boarded) AS total_pax
        FROM flights f
        JOIN airports ao ON ao.airport_id = f.origin_airport_id
        JOIN airports ad ON ad.airport_id = f.dest_airport_id
        GROUP BY ao.iata_code, ao.city, ad.iata_code, ad.city
        ORDER BY flights DESC");

    $dbError = null;
} catch (RuntimeException $e) {
    $dbError  = $e->getMessage();
    $airports = $topRoutes = [];
}

require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <h1 class="page-title">Airports & Routes</h1>
  <p class="page-desc">Airport directory and top route activity over the last 30 days.</p>
</div>

<?php if ($dbError): ?>
<div class="db-error-banner">⚠ <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<div class="two-col-layout">

<!-- Airport Directory -->
<div class="panel" style="grid-column: 1 / -1">
  <div class="panel-header"><h2 class="panel-title">Airport Directory</h2></div>
  <div class="panel-body no-pad">
    <div class="table-scroll">
    <table class="data-table full-width">
      <thead>
        <tr>
          <th>ICAO</th><th>IATA</th><th>NAME</th><th>CITY</th><th>COUNTRY</th>
          <th>ELEV (FT)</th><th>TIMEZONE</th><th>HUB</th>
          <th>DEP (30D)</th><th>ARR (30D)</th><th>TOTAL OPS</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($airports as $ap): ?>
        <tr>
          <td><code class="mono-sm"><?= htmlspecialchars($ap['icao_code']) ?></code></td>
          <td><span class="airport-tag"><?= htmlspecialchars($ap['iata_code']) ?></span></td>
          <td class="text-sm"><?= htmlspecialchars($ap['name']) ?></td>
          <td><?= htmlspecialchars($ap['city']) ?></td>
          <td><?= htmlspecialchars($ap['country']) ?></td>
          <td class="mono-sm"><?= number_format($ap['elevation_ft']) ?></td>
          <td class="mono-sm text-sm"><?= htmlspecialchars($ap['timezone']) ?></td>
          <td><?= $ap['is_hub'] ? '<span class="hub-badge">HUB</span>' : '—' ?></td>
          <td class="mono-sm"><?= (int)($ap['dep_count'] ?? 0) ?></td>
          <td class="mono-sm"><?= (int)($ap['arr_count'] ?? 0) ?></td>
          <td class="mono-sm"><strong><?= (int)($ap['total_ops'] ?? 0) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- Top Routes -->
<div class="panel" style="grid-column: 1 / -1">
  <div class="panel-header"><h2 class="panel-title">Top 10 Routes — Last 30 Days</h2></div>
  <div class="panel-body no-pad">
    <div class="table-scroll">
    <table class="data-table full-width">
      <thead>
        <tr>
          <th>RANK</th><th>ROUTE</th><th>CITIES</th><th>FLIGHTS</th>
          <th>AVG DIST (NM)</th><th>AVG DELAY (MIN)</th><th>TOTAL PAX</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($topRoutes as $i => $r): ?>
        <tr>
          <td class="mono-sm">#<?= $i + 1 ?></td>
          <td>
            <span class="airport-tag"><?= htmlspecialchars($r['origin']) ?></span>
            <svg viewBox="0 0 16 16" class="route-arrow"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
            <span class="airport-tag"><?= htmlspecialchars($r['dest']) ?></span>
          </td>
          <td class="text-sm"><?= htmlspecialchars($r['origin_city']) ?> → <?= htmlspecialchars($r['dest_city']) ?></td>
          <td class="mono-sm"><strong><?= (int)$r['flights'] ?></strong></td>
          <td class="mono-sm"><?= number_format((int)$r['avg_nm']) ?></td>
          <td class="mono-sm"><?= round($r['avg_delay'] ?? 0, 1) ?></td>
          <td class="mono-sm"><?= $r['total_pax'] ? number_format($r['total_pax']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
LIMIT 10