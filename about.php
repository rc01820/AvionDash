<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
auth_check();

$pageTitle = 'About & Help';
require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <h1 class="page-title">About AvionDash</h1>
  <p class="page-desc">Aviation Operations Monitor — Datadog Monitoring Demo Platform</p>
</div>

<div class="two-col-layout static-layout">

  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">What Is This Application?</h2></div>
    <div class="panel-body prose">
      <p>AvionDash is a multi-tier aviation operations web application designed as a <strong>Datadog monitoring demo platform</strong>. It demonstrates how to monitor a real-world PHP web application connected to a Microsoft SQL Server database, hosted on IIS (Windows Server).</p>
      <p>The application simulates an airline operations center, tracking live flight status, aircraft fleet health, airport routing, crew assignments, maintenance logs, and operational alerts.</p>
      <p>All data in this demo is <strong>synthetic and fictional</strong>, generated to exercise various database query patterns, application tiers, and error scenarios that are relevant to monitoring.</p>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">Technology Stack</h2></div>
    <div class="panel-body prose">
      <ul class="info-list">
        <li><strong>Web Server:</strong> IIS 10 on Windows Server 2022</li>
        <li><strong>Language:</strong> PHP 8.2 (via FastCGI / PHP Manager for IIS)</li>
        <li><strong>Database:</strong> Microsoft SQL Server 2022 (local)</li>
        <li><strong>PHP DB Extension:</strong> sqlsrv (Microsoft PHP Drivers for SQL Server)</li>
        <li><strong>Auth:</strong> Server-side PHP sessions + bcrypt password hashing</li>
        <li><strong>Monitoring Target:</strong> Datadog Agent (Windows), APM, DB Monitoring, Log Management</li>
      </ul>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">Application Pages</h2></div>
    <div class="panel-body prose">
      <ul class="info-list">
        <li><strong>Dashboard</strong> — Live KPIs, airborne flights, alerts summary, 7-day OTP chart</li>
        <li><strong>Flight Operations</strong> — Filterable flight table with status, delay tracking, full schedule</li>
        <li><strong>Aircraft Status</strong> — Fleet registry, utilization, open maintenance records</li>
        <li><strong>Airports & Routes</strong> — Airport directory, top routes, traffic volumes</li>
        <li><strong>Alerts</strong> — Operational alert board with severity levels and resolve actions</li>
        <li><strong>Reports</strong> — Six pre-built canned SQL reports (delay, utilization, pilot activity, etc.)</li>
        <li><strong>Query Runner</strong> — Live SELECT query tool for analysts and admins</li>
        <li><strong>About / Help</strong> — This page (static)</li>
      </ul>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">User Roles</h2></div>
    <div class="panel-body prose">
      <ul class="info-list">
        <li><strong>viewer</strong> — Read-only access to all dashboard and report pages</li>
        <li><strong>analyst</strong> — All viewer access + Query Runner + can resolve alerts</li>
        <li><strong>admin</strong> — Full access to all features</li>
      </ul>
      <p style="margin-top:1rem">Demo credentials: <code>admin</code> / <code>password</code> &nbsp;|&nbsp; <code>analyst</code> / <code>password</code> &nbsp;|&nbsp; <code>viewer</code> / <code>password</code></p>
    </div>
  </div>

  <div class="panel" style="grid-column: 1 / -1">
    <div class="panel-header"><h2 class="panel-title">What to Monitor with Datadog</h2></div>
    <div class="panel-body prose">
      <div class="monitoring-grid">
        <div class="mon-card">
          <h4>Infrastructure</h4>
          <ul>
            <li>IIS Worker Process CPU / Memory</li>
            <li>Windows Server disk I/O and network</li>
            <li>SQL Server service availability</li>
            <li>PHP-CGI worker pool saturation</li>
          </ul>
        </div>
        <div class="mon-card">
          <h4>APM Traces</h4>
          <ul>
            <li>PHP page response times per route</li>
            <li>DB query latency by stored procedure</li>
            <li>Session lookup performance</li>
            <li>Error rate by page (4xx / 5xx)</li>
          </ul>
        </div>
        <div class="mon-card">
          <h4>Database Monitoring</h4>
          <ul>
            <li>Query execution plans and slow queries</li>
            <li>Index scan vs. seek ratios</li>
            <li>Connection pool usage</li>
            <li>Lock waits and blocking queries</li>
          </ul>
        </div>
        <div class="mon-card">
          <h4>Log Management</h4>
          <ul>
            <li>IIS access logs (HTTP status, latency)</li>
            <li>PHP error log (exceptions, DB failures)</li>
            <li>SQL Server errorlog</li>
            <li>Windows Event Log (app pool recycles)</li>
          </ul>
        </div>
        <div class="mon-card">
          <h4>Synthetic Monitoring</h4>
          <ul>
            <li>Login page availability check</li>
            <li>Dashboard load time SLA</li>
            <li>DB query round-trip health check</li>
            <li>Alert page API response verification</li>
          </ul>
        </div>
        <div class="mon-card">
          <h4>Business Metrics</h4>
          <ul>
            <li>Flights airborne count over time</li>
            <li>Alert spike detection</li>
            <li>Query runner usage by role</li>
            <li>Login failure rate monitoring</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
