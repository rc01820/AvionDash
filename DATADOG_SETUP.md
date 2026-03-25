# AvionDash — Datadog Setup Guide

Complete step-by-step instructions for configuring every Datadog integration, monitor, dashboard, and alert for the AvionDash demo platform.

---

## Table of Contents

1. [Agent Installation & Universal Tags](#1-agent-installation--universal-tags)
2. [Apache Integration](#2-apache-integration)
3. [MariaDB Integration & Database Monitoring](#3-mariadb-integration--database-monitoring)
4. [PHP APM Tracer](#4-php-apm-tracer)
5. [RUM Browser SDK](#5-rum-browser-sdk)
6. [Synthetic Tests](#6-synthetic-tests)
7. [Custom Metrics](#7-custom-metrics)
8. [Log Management](#8-log-management)
9. [Monitors — APM](#9-monitors--apm)
10. [Monitors — Infrastructure / Metric](#10-monitors--infrastructure--metric)
11. [Monitors — Log](#11-monitors--log)
12. [Monitors — Anomaly](#12-monitors--anomaly)
13. [Monitors — Change Alert](#13-monitors--change-alert)
14. [Monitors — RUM](#14-monitors--rum)
15. [Monitors — Synthetic](#15-monitors--synthetic)
16. [Monitors — Composite](#16-monitors--composite)
17. [Dashboards](#17-dashboards)
18. [SLOs](#18-slos)
19. [Monitor–to–Fault Quick Reference](#19-monitortofault-quick-reference)

---

## 1. Agent Installation & Universal Tags

### Install the Agent

```bash
DD_API_KEY="YOUR_DD_API_KEY" DD_SITE="datadoghq.com" \
  bash -c "$(curl -L https://s3.amazonaws.com/dd-agent-bootstrap/scripts/install_script_agent7.sh)"
```

### Configure `/etc/datadog-agent/datadog.yaml`

```yaml
api_key: YOUR_DD_API_KEY_HERE
site:    datadoghq.com
hostname: aviondash-server

tags:
  - env:demo
  - service:aviondash
  - app:aviondash
  - host:aviondash-server
  - team:ops
  - version:1.0.0
  - os:rhel
  - webserver:apache
  - database:mariadb

logs_enabled: true

apm_config:
  enabled: true

dogstatsd_config:
  listen_port: 8125
```

```bash
systemctl restart datadog-agent
sudo datadog-agent status
```

---

## 2. Apache Integration

### Enable mod_status

Create `/etc/httpd/conf.d/status.conf`:

```apache
<Location "/server-status">
    SetHandler server-status
    Require local
</Location>
```

```bash
systemctl reload httpd
curl -s http://localhost/server-status?auto | head -5
```

### Create `/etc/datadog-agent/conf.d/apache.d/conf.yaml`

```yaml
init_config:

instances:
  - apache_status_url: http://localhost/server-status?auto
    tags:
      - env:demo
      - service:aviondash
      - tier:web
      - component:apache
      - app:aviondash

logs:
  - type: file
    path: /var/log/httpd/aviondash_access.log
    source: apache
    service: aviondash-web
    log_processing_rules:
      - type: multi_line
        name: new_access_log_line
        pattern: \d+\.\d+\.\d+\.\d+
    tags:
      - env:demo
      - tier:web
      - component:apache
      - app:aviondash

  - type: file
    path: /var/log/httpd/aviondash_error.log
    source: apache
    service: aviondash-web
    tags:
      - env:demo
      - tier:web
      - component:apache

  - type: file
    path: /var/log/aviondash/php_errors.log
    source: php
    service: aviondash-web
    tags:
      - env:demo
      - tier:web
      - component:php
      - app:aviondash

  - type: file
    path: /var/log/aviondash/php_slow.log
    source: php
    service: aviondash-web
    tags:
      - env:demo
      - tier:web
      - component:php
      - log_type:slow_log
```

```bash
systemctl restart datadog-agent
sudo datadog-agent check apache
```

---

## 3. MariaDB Integration & Database Monitoring

### Create the Datadog MariaDB user

```sql
-- Run in MariaDB as root
CREATE USER 'datadog'@'localhost' IDENTIFIED BY 'DatadogDBMPass1!';
GRANT REPLICATION CLIENT ON *.*          TO 'datadog'@'localhost';
GRANT PROCESS ON *.*                     TO 'datadog'@'localhost';
GRANT SELECT ON performance_schema.*     TO 'datadog'@'localhost';
GRANT SELECT ON aviationdb.*             TO 'datadog'@'localhost';
FLUSH PRIVILEGES;
```

### Create `/etc/datadog-agent/conf.d/mysql.d/conf.yaml`

```yaml
init_config:

instances:
  - host: localhost
    port: 3306
    username: datadog
    password: 'DatadogDBMPass1!'
    database: aviationdb
    connector: pymysql

    # Database Monitoring — requires performance_schema = ON in MariaDB
    dbm: true
    query_metrics:
      enabled: true
    query_samples:
      enabled: true
    query_activity:
      enabled: true

    tags:
      - env:demo
      - service:aviondash
      - tier:database
      - component:mariadb
      - app:aviondash

    # ── Custom business metrics ──────────────────────────────────────────
    custom_queries:

      # aviation.flights.airborne — flights currently in the air
      - query: >
          SELECT
            SUM(status = 'airborne') AS airborne,
            COUNT(*) AS total_today
          FROM flights
          WHERE DATE(scheduled_dep) = CURDATE()
        columns:
          - name: aviation.flights.airborne
            type: gauge
          - name: aviation.flights.total_today
            type: gauge
        tags:
          - env:demo
          - service:aviondash
          - app:aviondash

      # aviation.alerts.open_count — unresolved operational alerts
      - query: >
          SELECT COUNT(*) AS open_alerts
          FROM system_alerts
          WHERE is_resolved = 0
        columns:
          - name: aviation.alerts.open_count
            type: gauge
        tags:
          - env:demo
          - service:aviondash

      # aviation.pax.today — passengers boarded on today's completed flights
      - query: >
          SELECT IFNULL(SUM(passengers_boarded), 0) AS pax
          FROM flights
          WHERE DATE(scheduled_dep) = CURDATE()
            AND status = 'landed'
        columns:
          - name: aviation.pax.today
            type: gauge
        tags:
          - env:demo
          - service:aviondash
          - app:aviondash

      # aviation.flights.delay_rate — percentage of flights delayed >15 min today
      - query: >
          SELECT
            ROUND(100.0 * SUM(delay_minutes > 15) / NULLIF(COUNT(*), 0), 1) AS delay_rate
          FROM flights
          WHERE DATE(scheduled_dep) = CURDATE()
        columns:
          - name: aviation.flights.delay_rate
            type: gauge
        tags:
          - env:demo
          - service:aviondash
```

```bash
systemctl restart datadog-agent
sudo datadog-agent check mysql
# Verify custom metrics appear in Datadog → Metrics Explorer → aviation.*
```

---

## 4. PHP APM Tracer

### Install

```bash
curl -LO https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php
php datadog-setup.php --php-bin=all
```

### Configure — append to `/etc/php.d/99-aviondash.ini`

```ini
[ddtrace]
extension = ddtrace.so

; Service identity
datadog.service        = aviondash
datadog.env            = demo
datadog.version        = 1.0.0

; Inject trace_id into PHP error logs for log-to-trace correlation
datadog.logs_injection = true

; Tag every span with app-level context
datadog.global_tags    = app:aviondash,tier:web,component:php,os:rhel

datadog.trace.enabled  = 1
datadog.trace.debug    = 0

; Capture DB query text in spans (required for DBM correlation)
datadog.trace.db_client_split_by_instance = true
```

```bash
systemctl restart php-fpm
php -m | grep ddtrace        # should list ddtrace
```

### Verify in Datadog

1. Load `http://your-server/aviondash/dashboard.php` a few times
2. Go to **APM → Traces**
3. You should see `GET /aviondash/dashboard.php` traces with `mysql.query` child spans

---

## 5. RUM Browser SDK

### Step 1 — Create a RUM Application

1. Go to **UX Monitoring → Real User Monitoring → New Application**
2. Name: `AvionDash`  
3. Application type: **Browser**
4. Enable **Session Replay** and **Frustration Signals**
5. Copy the generated `clientToken` and `applicationId`

### Step 2 — Add to `includes/layout_header.php`

Add the following **before `</head>`**:

```html
<script src="https://www.datadoghq-browser-agent.com/datadog-rum.js"
        type="text/javascript"></script>
<script>
  window.DD_RUM && DD_RUM.init({
    clientToken:             'pub_REPLACE_WITH_YOUR_CLIENT_TOKEN',
    applicationId:           'REPLACE_WITH_YOUR_APPLICATION_ID',
    site:                    'datadoghq.com',
    service:                 'aviondash',
    env:                     'demo',
    version:                 '1.0.0',
    sessionSampleRate:       100,
    sessionReplaySampleRate: 100,
    trackResources:          true,
    trackLongTasks:          true,
    trackUserInteractions:   true,
    trackFrustrations:       true,
    defaultPrivacyLevel:     'mask-user-input',
  });

  // Tag sessions with the current page name
  window.DD_RUM && DD_RUM.setGlobalContextProperty(
    'page',
    '<?= htmlspecialchars(basename($_SERVER["PHP_SELF"], ".php")) ?>'
  );

  // Tag sessions with all currently armed fault IDs
  <?php if (class_exists('FaultEngine')) {
    foreach (array_keys(array_filter(FaultEngine::allState())) as $fid) {
      echo "window.DD_RUM && DD_RUM.setGlobalContextProperty('fault.{$fid}', 'true');\n";
    }
  } ?>

  window.DD_RUM && window.DD_RUM.startSessionReplayRecording();
</script>
```

### Step 3 — Verify

1. Browse to `http://your-server/aviondash/` and navigate a few pages
2. In Datadog: **UX Monitoring → Real User Monitoring → AvionDash**
3. Page views should appear within 30 seconds

---

## 6. Synthetic Tests

### Test 1 — Login Flow (Browser Test)

**Path:** Synthetics → New Test → Browser Test

| Field | Value |
|---|---|
| Name | AvionDash Login Flow |
| URL | `http://your-server/aviondash/` |
| Locations | At least 1 private or public location |
| Frequency | Every 1 minute |
| Alert threshold | Alert if 2 of the last 5 test runs fail |

**Steps to record:**
1. Navigate to `/aviondash/`
2. Assert: URL contains `/login.php`
3. Fill `#username` with `admin`
4. Fill `#password` with `password`
5. Click the **SIGN IN** button
6. Assert: URL contains `/dashboard.php`
7. Assert: element `.kpi-grid` is present
8. Assert: text `AIRBORNE NOW` is visible

**Detects:** `auth_flap` (redirects randomly fail), `page_500_aircraft` (if dashboard is broken)

---

### Test 2 — Health Check Endpoint (API Test)

**Path:** Synthetics → New Test → API Test → HTTP

| Field | Value |
|---|---|
| Name | AvionDash Health Check |
| URL | `http://your-server/aviondash/health.php` |
| Method | GET |
| Frequency | Every 1 minute |
| Alert threshold | Alert if 2 of the last 3 test runs fail |

**Assertions:**
- Response status code = 200
- Response body contains `"status":"ok"`
- Response time < 3000ms

**Detects:** `health_check_flap` (alternates 200/503)

---

### Test 3 — Reports Page Load Time (API Test)

**Path:** Synthetics → New Test → API Test → HTTP

| Field | Value |
|---|---|
| Name | AvionDash Reports Page SLA |
| URL | `http://your-server/aviondash/reports.php` |
| Method | GET |
| Frequency | Every 2 minutes |
| Alert threshold | Alert if 1 of the last 3 test runs fails |

**Assertions:**
- Response status code = 200
- Response time < 7000ms

**Detects:** `slow_page_reports` (sleep 8s), `slow_third_party` (curl blocks 5s)

---

### Test 4 — Flights Page Content (API Test)

**Path:** Synthetics → New Test → API Test → HTTP

| Field | Value |
|---|---|
| Name | AvionDash Flights Page Content Check |
| URL | `http://your-server/aviondash/flights.php` |
| Method | GET |
| Frequency | Every 2 minutes |

**Assertions:**
- Response status code = 200
- Response time < 5000ms
- Response body does NOT contain `Query execution failed`

**Detects:** `slow_flights_query` (>4s), `page_500_aircraft` if flight page also breaks

---

## 7. Custom Metrics

The custom metrics are configured via the `custom_queries` section in the MariaDB integration (Section 3). After restarting the Agent, verify they appear:

1. Go to **Metrics → Explorer**
2. Search for `aviation.` in the metric search field
3. You should see four metrics:

| Metric | Type | Normal Value |
|---|---|---|
| `aviation.flights.airborne` | gauge | 2–5 |
| `aviation.flights.total_today` | gauge | 8–15 |
| `aviation.alerts.open_count` | gauge | 3–10 |
| `aviation.pax.today` | gauge | 300–800 |
| `aviation.flights.delay_rate` | gauge | 0–30 |

**If metrics are not appearing:**
```bash
sudo datadog-agent check mysql | grep -A 5 "custom_queries"
# Look for errors in the check output
```

---

## 8. Log Management

### Verify logs are flowing

1. Load a few pages in the app
2. Go to **Logs → Live Tail**
3. Filter by `service:aviondash-web`
4. You should see Apache access log entries

### Create Log Indexes and Pipelines

**Path:** Logs → Configuration → Pipelines → New Pipeline

Create a pipeline for AvionDash PHP logs:

- **Filter:** `source:php service:aviondash-web`
- **Processor 1** — Grok Parser named `PHP Error Parser`:
  ```
  php_error \[%{date("dd-MMM-yyyy HH:mm:ss"):timestamp}\] PHP %{word:level}: %{data:message} in %{notSpace:file} on line %{integer:line}
  ```
- **Processor 2** — Status Remapper: map `level` to log status

Create a pipeline for Apache access logs:

- **Filter:** `source:apache service:aviondash-web`
- **Processor 1** — URL Parser on `http.url`
- **Processor 2** — User-Agent Parser on `http.useragent`

---

## 9. Monitors — APM

### Monitor 1 — Flights Page Latency

**Path:** Monitors → New Monitor → APM

| Field | Value |
|---|---|
| Monitor name | AvionDash — Flights Page p95 Latency |
| Service | `aviondash` |
| Resource | `GET /aviondash/flights.php` |
| Metric | `p95 latency` |
| Alert threshold | > 3000 ms |
| Warning threshold | > 1500 ms |
| Evaluation window | Last 5 minutes |
| Message | `Flights page p95 latency is {{value}}ms. Possible cause: slow_flights_query fault armed. Check APM traces for SLEEP() in mysql.query span. @pagerduty-aviondash` |
| Tags | `env:demo`, `service:aviondash`, `fault:slow_flights_query` |

**Detects:** `slow_flights_query`

---

### Monitor 2 — Reports Page Latency

**Path:** Monitors → New Monitor → APM

| Field | Value |
|---|---|
| Monitor name | AvionDash — Reports Page p90 Latency |
| Service | `aviondash` |
| Resource | `GET /aviondash/reports.php` |
| Metric | `p90 latency` |
| Alert threshold | > 5000 ms |
| Warning threshold | > 2000 ms |
| Evaluation window | Last 5 minutes |
| Message | `Reports page p90 latency is {{value}}ms. Causes: slow_page_reports (PHP sleep 8s) or slow_third_party (curl 5s). Check APM trace span type — mysql.query vs http-client.` |
| Tags | `env:demo`, `service:aviondash`, `fault:slow_page_reports` |

**Detects:** `slow_page_reports`, `slow_third_party`

---

### Monitor 3 — Aircraft Page Error Rate

**Path:** Monitors → New Monitor → APM

| Field | Value |
|---|---|
| Monitor name | AvionDash — Aircraft Page Error Rate |
| Service | `aviondash` |
| Resource | `GET /aviondash/aircraft.php` |
| Metric | `Error rate` |
| Alert threshold | > 5% |
| Warning threshold | > 1% |
| Evaluation window | Last 5 minutes |
| Message | `Aircraft page error rate is {{value}}%. page_500_aircraft fault likely armed — RuntimeException is being thrown before output. Check PHP error log.` |
| Tags | `env:demo`, `service:aviondash`, `fault:page_500_aircraft` |

**Detects:** `page_500_aircraft`

---

### Monitor 4 — Query Runner p99 Latency

**Path:** Monitors → New Monitor → APM

| Field | Value |
|---|---|
| Monitor name | AvionDash — Query Runner p99 Latency |
| Service | `aviondash` |
| Resource | `GET /aviondash/query_runner.php` |
| Metric | `p99 latency` |
| Alert threshold | > 4000 ms |
| Warning threshold | > 2000 ms |
| Evaluation window | Last 5 minutes |
| Message | `Query Runner p99 latency is {{value}}ms — cpu_spike_query_runner fault likely armed. Check system.cpu.user on aviondash-server.` |
| Tags | `env:demo`, `service:aviondash`, `fault:cpu_spike_query_runner` |

**Detects:** `cpu_spike_query_runner`

---

## 10. Monitors — Infrastructure / Metric

### Monitor 5 — Host CPU Spike

**Path:** Monitors → New Monitor → Metric

| Field | Value |
|---|---|
| Monitor name | AvionDash — Host CPU Spike |
| Metric | `system.cpu.user` |
| Scope | `host:aviondash-server` |
| Aggregation | `avg by host` |
| Alert threshold | > 85 |
| Warning threshold | > 60 |
| Evaluation window | Last 5 minutes |
| Message | `Host CPU user time is {{value}}% on {{host.name}}. Fault likely: cpu_spike_query_runner (3s math loop per request). Check APM traces for Query Runner.` |
| Tags | `env:demo`, `service:aviondash`, `fault:cpu_spike_query_runner` |

**Detects:** `cpu_spike_query_runner`

---

### Monitor 6 — Host Memory Exhaustion

**Path:** Monitors → New Monitor → Metric

| Field | Value |
|---|---|
| Monitor name | AvionDash — Host Memory Low |
| Metric | `system.mem.pct_usable` |
| Scope | `host:aviondash-server` |
| Aggregation | `avg by host` |
| Alert threshold | < 0.15 |
| Warning threshold | < 0.30 |
| Evaluation window | Last 10 minutes |
| Message | `Free memory on {{host.name}} is {{value}}%. memory_leak_dashboard fault may be armed — each Dashboard load allocates 64MB without freeing. PHP-FPM workers may restart.` |
| Tags | `env:demo`, `service:aviondash`, `fault:memory_leak_dashboard` |

**Detects:** `memory_leak_dashboard`

---

### Monitor 7 — Disk I/O Utilisation

**Path:** Monitors → New Monitor → Metric

| Field | Value |
|---|---|
| Monitor name | AvionDash — Disk I/O Spike |
| Metric | `system.io.util` |
| Scope | `host:aviondash-server` |
| Aggregation | `avg by host,device` |
| Alert threshold | > 70 |
| Warning threshold | > 40 |
| Evaluation window | Last 5 minutes |
| Message | `Disk I/O utilisation is {{value}}% on {{host.name}}. disk_io_spike fault may be armed — writes/reads a 50MB temp file on every Dashboard load.` |
| Tags | `env:demo`, `service:aviondash`, `fault:disk_io_spike` |

**Detects:** `disk_io_spike`

---

### Monitor 8 — MariaDB Connection Count Rising

**Path:** Monitors → New Monitor → Metric

| Field | Value |
|---|---|
| Monitor name | AvionDash — DB Connection Count High |
| Metric | `mysql.net.connections` |
| Scope | `host:aviondash-server` |
| Aggregation | `max by host` |
| Alert threshold | > 90 |
| Warning threshold | > 60 |
| Evaluation window | Last 5 minutes |
| Message | `MariaDB connection count is {{value}} on {{host.name}}. connection_pool_exhaust fault may be armed — opens 12 extra PDO connections per Reports page load without closing them.` |
| Tags | `env:demo`, `service:aviondash`, `fault:connection_pool_exhaust` |

**Detects:** `connection_pool_exhaust`

---

### Monitor 9 — Open Alert Count High

**Path:** Monitors → New Monitor → Metric

| Field | Value |
|---|---|
| Monitor name | AvionDash — Open Alert Count High |
| Metric | `aviation.alerts.open_count` |
| Scope | `service:aviondash` |
| Aggregation | `max` |
| Alert threshold | > 50 |
| Warning threshold | > 25 |
| Evaluation window | Last 5 minutes |
| Message | `Open alert count is {{value}}. alert_cascade fault may be armed — inserts 20 rows into system_alerts on every Alerts page load. Run CALL sp_ResetChaosAlerts(); to clean up.` |
| Tags | `env:demo`, `service:aviondash`, `fault:alert_cascade` |

**Detects:** `alert_cascade`

---

### Monitor 10 — Airborne Count Drops to Zero

**Path:** Monitors → New Monitor → Metric

| Field | Value |
|---|---|
| Monitor name | AvionDash — No Airborne Flights (Data Loss) |
| Metric | `aviation.flights.airborne` |
| Scope | `service:aviondash` |
| Aggregation | `min` |
| Alert threshold | < 1 for 15 minutes |
| Evaluation window | Last 15 minutes |
| Message | `aviation.flights.airborne has been 0 for 15 minutes during business hours. Possible cause: exception_silencer fault armed — DB errors are silently swallowed, returning empty results. APM error rate will appear normal.` |
| Tags | `env:demo`, `service:aviondash`, `fault:exception_silencer` |

**Detects:** `exception_silencer`

---

### Monitor 11 — DB Rows Sent Spike

**Path:** Monitors → New Monitor → Metric

| Field | Value |
|---|---|
| Monitor name | AvionDash — DB Rows Sent Spike |
| Metric | `mysql.performance.rows_sent` |
| Scope | `host:aviondash-server` |
| Aggregation | `avg by host` |
| Alert threshold | > 5000 per second |
| Evaluation window | Last 5 minutes |
| Message | `MariaDB rows_sent rate is {{value}}/s. large_result_no_limit fault may be armed — the LIMIT clause was removed, returning all historical rows on the Reports page.` |
| Tags | `env:demo`, `service:aviondash`, `fault:large_result_no_limit` |

**Detects:** `large_result_no_limit`

---

### Monitor 12 — Custom Metrics Usage Spike

**Path:** Monitors → New Monitor → Metric

| Field | Value |
|---|---|
| Monitor name | AvionDash — Custom Metrics Cardinality Bomb |
| Metric | `datadog.estimated_usage.metrics.custom` |
| Scope | `*` |
| Aggregation | `max` |
| Alert threshold | (set based on your current baseline + 500) |
| Evaluation window | Last 10 minutes |
| Message | `Custom metric count has spiked. high_cardinality_tags fault is likely armed — emitting a new metric series with a unique UUID request_id tag on every page load. Disable fault immediately to stop the bleed.` |
| Tags | `env:demo`, `service:aviondash`, `fault:high_cardinality_tags` |

**Detects:** `high_cardinality_tags`

---

## 11. Monitors — Log

### Monitor 13 — PHP Warning Flood

**Path:** Monitors → New Monitor → Logs

| Field | Value |
|---|---|
| Monitor name | AvionDash — PHP Warning Log Flood |
| Search query | `source:php status:warn service:aviondash-web` |
| Aggregation | `count` |
| Group by | (none) |
| Alert threshold | > 200 in 5 minutes |
| Warning threshold | > 50 in 5 minutes |
| Evaluation window | 5 minutes |
| Message | `PHP WARNING log count is {{value}} in the last 5 minutes. log_flood fault is likely armed — writes 75 WARNING entries per page request across all pages.` |
| Tags | `env:demo`, `service:aviondash`, `fault:log_flood` |

**Detects:** `log_flood`

---

### Monitor 14 — PHP RuntimeException

**Path:** Monitors → New Monitor → Logs

| Field | Value |
|---|---|
| Monitor name | AvionDash — PHP RuntimeException Detected |
| Search query | `source:php service:aviondash-web "RuntimeException"` |
| Aggregation | `count` |
| Alert threshold | > 3 in 5 minutes |
| Warning threshold | > 0 in 5 minutes |
| Evaluation window | 5 minutes |
| Message | `A PHP RuntimeException has been logged {{value}} times. page_500_aircraft fault is likely armed — the aircraft.php page throws an unhandled exception producing HTTP 500 responses.` |
| Tags | `env:demo`, `service:aviondash`, `fault:page_500_aircraft` |

**Detects:** `page_500_aircraft`

---

### Monitor 15 — Apache HTTP 500 Spike

**Path:** Monitors → New Monitor → Logs

| Field | Value |
|---|---|
| Monitor name | AvionDash — Apache HTTP 500 Spike |
| Search query | `source:apache service:aviondash-web @http.status_code:500` |
| Aggregation | `count` |
| Alert threshold | > 5 in 5 minutes |
| Warning threshold | > 1 in 5 minutes |
| Evaluation window | 5 minutes |
| Message | `Apache is returning {{value}} HTTP 500 responses. Check: page_500_aircraft fault on /aviondash/aircraft.php.` |
| Tags | `env:demo`, `service:aviondash`, `fault:page_500_aircraft` |

**Detects:** `page_500_aircraft`

---

### Monitor 16 — Abnormal 302 Redirect Rate (Session Flap)

**Path:** Monitors → New Monitor → Logs

| Field | Value |
|---|---|
| Monitor name | AvionDash — Abnormal Session Redirects |
| Search query | `source:apache service:aviondash-web @http.status_code:302 @http.url:*login.php*` |
| Aggregation | `count` |
| Alert threshold | > 30 in 5 minutes |
| Warning threshold | > 10 in 5 minutes |
| Evaluation window | 5 minutes |
| Message | `{{value}} redirects to login.php in 5 minutes — auth_flap fault is likely armed. Sessions are being randomly invalidated on 40% of page loads.` |
| Tags | `env:demo`, `service:aviondash`, `fault:auth_flap` |

**Detects:** `auth_flap`

---

### Monitor 17 — PHP Out of Memory

**Path:** Monitors → New Monitor → Logs

| Field | Value |
|---|---|
| Monitor name | AvionDash — PHP Memory Exhausted |
| Search query | `source:php service:aviondash-web "Allowed memory size"` |
| Aggregation | `count` |
| Alert threshold | > 0 in 5 minutes |
| Evaluation window | 5 minutes |
| Message | `PHP has run out of memory. memory_leak_dashboard fault is likely armed — each Dashboard request allocates 64MB of arrays without freeing them. PHP-FPM worker restarted.` |
| Tags | `env:demo`, `service:aviondash`, `fault:memory_leak_dashboard` |

**Detects:** `memory_leak_dashboard`

---

## 12. Monitors — Anomaly

### Monitor 18 — Passenger Count Anomaly

**Path:** Monitors → New Monitor → Anomaly

| Field | Value |
|---|---|
| Monitor name | AvionDash — Passenger Count Business Anomaly |
| Metric | `aviation.pax.today` |
| Scope | `service:aviondash` |
| Algorithm | `agile` |
| Deviations | 3 |
| Direction | Above only |
| Evaluation window | Last 5 minutes |
| Recovery window | Last 15 minutes |
| Message | `aviation.pax.today is anomalously high — {{value}} vs expected baseline. bad_data_passengers fault is likely armed — all passenger counts are being multiplied by 847 in the application layer. Database data is clean.` |
| Tags | `env:demo`, `service:aviondash`, `fault:bad_data_passengers` |

**Detects:** `bad_data_passengers`

---

### Monitor 19 — DB Query Duration Anomaly

**Path:** Monitors → New Monitor → Anomaly

| Field | Value |
|---|---|
| Monitor name | AvionDash — DB Query Duration Anomaly |
| Metric | `trace.mysql.query.duration` |
| Scope | `service:aviondash, env:demo` |
| Algorithm | `agile` |
| Deviations | 3 |
| Direction | Above only |
| Evaluation window | Last 10 minutes |
| Message | `Database query duration is anomalously high. Possible faults: slow_flights_query (SLEEP 4s injected), n_plus_one_pilots (burst of queries), missing_index_scan (full table scan).` |
| Tags | `env:demo`, `service:aviondash` |

**Detects:** `slow_flights_query`, `n_plus_one_pilots`, `missing_index_scan`

---

### Monitor 20 — Log Ingestion Rate Anomaly

**Path:** Monitors → New Monitor → Anomaly

| Field | Value |
|---|---|
| Monitor name | AvionDash — Log Ingestion Volume Spike |
| Metric | `datadog.estimated_usage.logs.ingested_bytes` |
| Scope | `service:aviondash-web` |
| Algorithm | `agile` |
| Deviations | 3 |
| Direction | Above only |
| Evaluation window | Last 10 minutes |
| Message | `Log ingestion volume is anomalously high for aviondash-web. log_flood fault is likely armed — 75 PHP WARNING entries are written per page request.` |
| Tags | `env:demo`, `service:aviondash`, `fault:log_flood` |

**Detects:** `log_flood`

---

### Monitor 21 — Disk Write Bytes Anomaly

**Path:** Monitors → New Monitor → Anomaly

| Field | Value |
|---|---|
| Monitor name | AvionDash — Disk Write Anomaly |
| Metric | `system.disk.write_bytes` |
| Scope | `host:aviondash-server` |
| Algorithm | `agile` |
| Deviations | 3 |
| Direction | Above only |
| Evaluation window | Last 5 minutes |
| Message | `Disk write bytes are anomalously high on {{host.name}}. disk_io_spike fault may be armed — a 50MB temp file is written and read on every Dashboard page load.` |
| Tags | `env:demo`, `service:aviondash`, `fault:disk_io_spike` |

**Detects:** `disk_io_spike`

---

## 13. Monitors — Change Alert

### Monitor 22 — DB Connection Count Rapid Rise

**Path:** Monitors → New Monitor → Metric → Change Alert

| Field | Value |
|---|---|
| Monitor name | AvionDash — DB Connections Rapid Rise |
| Metric | `mysql.net.connections` |
| Scope | `host:aviondash-server` |
| Change type | `Change` |
| Change window | 5 minutes |
| Alert threshold | Change > 20 |
| Message | `MariaDB connection count increased by {{value}} in 5 minutes. connection_pool_exhaust fault is likely armed — each Reports page load opens 12 extra connections and never closes them.` |
| Tags | `env:demo`, `service:aviondash`, `fault:connection_pool_exhaust` |

**Detects:** `connection_pool_exhaust`

---

### Monitor 23 — Open Alert Count Rapid Growth

**Path:** Monitors → New Monitor → Metric → Change Alert

| Field | Value |
|---|---|
| Monitor name | AvionDash — Open Alerts Rapid Growth |
| Metric | `aviation.alerts.open_count` |
| Scope | `service:aviondash` |
| Change type | `Change` |
| Change window | 5 minutes |
| Alert threshold | Change > 15 |
| Message | `Open alert count increased by {{value}} in 5 minutes. alert_cascade fault is likely armed — 20 critical alerts are inserted on every Alerts page load. Disarm fault and run: CALL sp_ResetChaosAlerts();` |
| Tags | `env:demo`, `service:aviondash`, `fault:alert_cascade` |

**Detects:** `alert_cascade`

---

### Monitor 24 — Custom Metric Count Change

**Path:** Monitors → New Monitor → Metric → Change Alert

| Field | Value |
|---|---|
| Monitor name | AvionDash — Custom Metric Series Spike |
| Metric | `datadog.estimated_usage.metrics.custom` |
| Scope | `*` |
| Change type | `Change` |
| Change window | 5 minutes |
| Alert threshold | Change > 500 |
| Message | `Custom metric series count increased by {{value}} in 5 minutes. high_cardinality_tags fault is likely armed — a new metric series with a unique UUID tag is emitted on every page load.` |
| Tags | `env:demo`, `service:aviondash`, `fault:high_cardinality_tags` |

**Detects:** `high_cardinality_tags`

---

## 14. Monitors — RUM

### Monitor 25 — Page Load Time Degradation

**Path:** Monitors → New Monitor → Real User Monitoring

| Field | Value |
|---|---|
| Monitor name | AvionDash — RUM Page Load Time |
| Search query | `service:aviondash env:demo` |
| Measure | `Loading time (p75)` |
| Group by | `@view.name` |
| Alert threshold | > 4000 ms |
| Warning threshold | > 2000 ms |
| Evaluation window | Last 15 minutes |
| Message | `RUM p75 loading time for {{@view.name}} is {{value}}ms. User experience is significantly degraded. Check APM for slow spans on this page.` |
| Tags | `env:demo`, `service:aviondash` |

**Detects:** `slow_page_reports`, `slow_flights_query`, `slow_third_party`, `cpu_spike_query_runner`

---

### Monitor 26 — Core Web Vital LCP Failure

**Path:** Monitors → New Monitor → Real User Monitoring

| Field | Value |
|---|---|
| Monitor name | AvionDash — RUM LCP Poor |
| Search query | `service:aviondash env:demo` |
| Measure | `Largest Contentful Paint (p75)` |
| Group by | `@view.name` |
| Alert threshold | > 4000 ms (Poor) |
| Warning threshold | > 2500 ms (Needs Improvement) |
| Evaluation window | Last 15 minutes |
| Message | `LCP is {{value}}ms on {{@view.name}} — classified as Poor (>4s). Real users are experiencing significant page render delays.` |
| Tags | `env:demo`, `service:aviondash` |

**Detects:** `slow_page_reports`, `slow_third_party`

---

### Monitor 27 — Rage Clicks (Frustration Signal)

**Path:** Monitors → New Monitor → Real User Monitoring

| Field | Value |
|---|---|
| Monitor name | AvionDash — RUM Rage Clicks |
| Search query | `service:aviondash env:demo @type:action @action.frustration.type:rage_click` |
| Measure | `count` |
| Alert threshold | > 10 in 10 minutes |
| Evaluation window | Last 10 minutes |
| Message | `{{value}} rage click events detected. Users are repeatedly clicking elements. Possible causes: bad_data_passengers (wrong PAX numbers prompt refreshing), timezone_corruption (wrong dates), or page_500_aircraft (blank page).` |
| Tags | `env:demo`, `service:aviondash` |

**Detects:** `bad_data_passengers`, `timezone_corruption`, `page_500_aircraft`

---

## 15. Monitors — Synthetic

### Monitor 28 — Login Flow Synthetic

> Created automatically when you set up the Synthetic test in Section 6.

| Field | Value |
|---|---|
| Monitor name | AvionDash — Login Synthetic Flow |
| Alert threshold | 2 of last 5 test runs failed |
| Message | `AvionDash login flow synthetic test is failing. auth_flap fault may be armed (40% session invalidation). Check Apache logs for 302 redirects to /login.php from authenticated pages.` |

**Detects:** `auth_flap`

---

### Monitor 29 — Health Check Synthetic

> Created automatically when you set up the Synthetic test in Section 6.

| Field | Value |
|---|---|
| Monitor name | AvionDash — Health Check Endpoint |
| Alert threshold | 2 of last 3 test runs failed |
| Message | `Health check endpoint is returning non-200 responses. health_check_flap fault may be armed — alternates between 200 and 503 on every other request. Run: rm -f /tmp/aviondash_health_counter` |

**Detects:** `health_check_flap`

---

### Monitor 30 — Reports Page SLA

> Created automatically when you set up the Synthetic test in Section 6.

| Field | Value |
|---|---|
| Monitor name | AvionDash — Reports Page SLA |
| Alert threshold | 1 of last 3 test runs failed |
| Message | `Reports page is exceeding 7-second SLA. Causes: slow_page_reports (PHP sleep 8s) or slow_third_party (curl to non-responsive endpoint).` |

**Detects:** `slow_page_reports`, `slow_third_party`

---

## 16. Monitors — Composite

### Monitor 31 — Database Tier Degraded

**Path:** Monitors → New Monitor → Composite

| Field | Value |
|---|---|
| Monitor name | AvionDash — Database Tier Degraded (Composite) |
| Formula | `(Monitor 18 [DB Anomaly]) OR (Monitor 22 [Connections Rising]) OR (Monitor 8 [Connections High]) OR (Monitor 11 [Rows Sent Spike])` |
| Message | `Multiple database signals indicate a DB-tier fault is active. Check: connection_pool_exhaust, missing_index_scan, n_plus_one_pilots, large_result_no_limit. Open Datadog DBM for query-level analysis.` |
| Tags | `env:demo`, `service:aviondash`, `tier:database` |

---

### Monitor 32 — Application Experience Degraded

**Path:** Monitors → New Monitor → Composite

| Field | Value |
|---|---|
| Monitor name | AvionDash — Application Experience Degraded (Composite) |
| Formula | `(Monitor 25 [RUM Page Load]) OR (Monitor 3 [Aircraft 500]) OR (Monitor 1 [Flights Latency]) OR (Monitor 27 [Rage Clicks])` |
| Message | `User experience is degraded — one or more RUM or APM signals are alerting simultaneously. Check the AvionDash Operations Dashboard in Datadog for a full picture.` |
| Tags | `env:demo`, `service:aviondash` |

---

## 17. Dashboards

### Create the AvionDash Operations Dashboard

**Path:** Dashboards → New Dashboard → Name: `AvionDash Operations`

Add the following widgets:

#### Row 1 — Business KPIs (Query Value widgets)

| Widget | Metric | Title |
|---|---|---|
| Query Value | `aviation.flights.airborne` | Airborne Now |
| Query Value | `aviation.alerts.open_count` | Open Alerts |
| Query Value | `aviation.pax.today` | PAX Today |
| Query Value | `aviation.flights.delay_rate` | Delay Rate % |

Set conditional formatting on **Open Alerts**: green if < 10, yellow if < 30, red if ≥ 30.

#### Row 2 — APM Latency (Timeseries widgets)

| Widget | Metric | Filter |
|---|---|---|
| Timeseries | `p95:trace.web.request.duration` | `service:aviondash resource_name:GET /aviondash/flights.php` |
| Timeseries | `p90:trace.web.request.duration` | `service:aviondash resource_name:GET /aviondash/reports.php` |
| Timeseries | `trace.web.request.errors` | `service:aviondash` |

#### Row 3 — Infrastructure (Timeseries widgets)

| Widget | Metric | Filter |
|---|---|---|
| Timeseries | `system.cpu.user` | `host:aviondash-server` |
| Timeseries | `system.mem.pct_usable` | `host:aviondash-server` |
| Timeseries | `mysql.net.connections` | `host:aviondash-server` |

#### Row 4 — Log Volume (Timeseries widget)

| Widget | Query | Title |
|---|---|---|
| Timeseries | `source:php service:aviondash-web status:warn` count | PHP Warnings per Minute |

#### Row 5 — Monitor Status (Monitor Summary widget)

- **Filter:** `tag:service:aviondash`
- Show all monitor states

#### Row 6 — Custom Metrics Timeseries

| Widget | Metric | Title |
|---|---|---|
| Timeseries | `aviation.alerts.open_count` | Alert Count Over Time |
| Timeseries | `aviation.pax.today` | PAX Today Over Time |

---

## 18. SLOs

### SLO 1 — Login Flow Availability

**Path:** Service Level Objectives → New SLO → Monitor Based

| Field | Value |
|---|---|
| Name | AvionDash Login Availability |
| Monitor | AvionDash — Login Synthetic Flow (Monitor 28) |
| 7-day target | 99% |
| 30-day target | 99.5% |
| Description | Synthetic login test must pass — measures real user ability to authenticate |

---

### SLO 2 — Reports Page SLA

**Path:** Service Level Objectives → New SLO → Monitor Based

| Field | Value |
|---|---|
| Name | AvionDash Reports Page SLA |
| Monitor | AvionDash — Reports Page SLA (Monitor 30) |
| 7-day target | 99% |
| 30-day target | 99.5% |
| Description | Reports page must load in under 7 seconds |

---

### SLO 3 — Application Error Rate

**Path:** Service Level Objectives → New SLO → Metric Based

| Field | Value |
|---|---|
| Name | AvionDash Application Error Rate |
| Good events | `trace.web.request.hits{service:aviondash,env:demo} - trace.web.request.errors{service:aviondash,env:demo}` |
| Total events | `trace.web.request.hits{service:aviondash,env:demo}` |
| 7-day target | 99% |
| 30-day target | 99.5% |

---

## 19. Monitor–to–Fault Quick Reference

| # | Monitor Name | Type | Fault Detected |
|---|---|---|---|
| 1 | Flights Page p95 Latency | APM | `slow_flights_query` |
| 2 | Reports Page p90 Latency | APM | `slow_page_reports`, `slow_third_party` |
| 3 | Aircraft Page Error Rate | APM | `page_500_aircraft` |
| 4 | Query Runner p99 Latency | APM | `cpu_spike_query_runner` |
| 5 | Host CPU Spike | Metric | `cpu_spike_query_runner` |
| 6 | Host Memory Low | Metric | `memory_leak_dashboard` |
| 7 | Disk I/O Spike | Metric | `disk_io_spike` |
| 8 | DB Connection Count High | Metric | `connection_pool_exhaust` |
| 9 | Open Alert Count High | Metric | `alert_cascade` |
| 10 | No Airborne Flights | Metric | `exception_silencer` |
| 11 | DB Rows Sent Spike | Metric | `large_result_no_limit` |
| 12 | Custom Metrics Spike | Metric | `high_cardinality_tags` |
| 13 | PHP Warning Flood | Log | `log_flood` |
| 14 | PHP RuntimeException | Log | `page_500_aircraft` |
| 15 | Apache HTTP 500 Spike | Log | `page_500_aircraft` |
| 16 | Abnormal 302 Redirects | Log | `auth_flap` |
| 17 | PHP Out of Memory | Log | `memory_leak_dashboard` |
| 18 | Passenger Count Anomaly | Anomaly | `bad_data_passengers` |
| 19 | DB Query Duration Anomaly | Anomaly | `slow_flights_query`, `n_plus_one_pilots`, `missing_index_scan` |
| 20 | Log Ingestion Anomaly | Anomaly | `log_flood` |
| 21 | Disk Write Bytes Anomaly | Anomaly | `disk_io_spike` |
| 22 | DB Connections Rapid Rise | Change | `connection_pool_exhaust` |
| 23 | Open Alerts Rapid Growth | Change | `alert_cascade` |
| 24 | Custom Metric Series Spike | Change | `high_cardinality_tags` |
| 25 | RUM Page Load Degradation | RUM | Multiple |
| 26 | RUM LCP Poor | RUM | `slow_page_reports`, `slow_third_party` |
| 27 | RUM Rage Clicks | RUM | `bad_data_passengers`, `timezone_corruption` |
| 28 | Login Synthetic | Synthetic | `auth_flap` |
| 29 | Health Check Synthetic | Synthetic | `health_check_flap` |
| 30 | Reports Page SLA | Synthetic | `slow_page_reports`, `slow_third_party` |
| 31 | DB Tier Degraded | Composite | All DB faults |
| 32 | App Experience Degraded | Composite | All web/app faults |

---

## Faults With No Direct Infrastructure Monitor

The following faults can only be detected through **content-level or business metric monitors** — a key demonstration point:

| Fault | Why Infrastructure Misses It | What Detects It |
|---|---|---|
| `exception_silencer` | DB errors return empty arrays — no PHP errors, no 500s, APM error rate = 0% | Monitor 10 (aviation.flights.airborne drops to 0) + Synthetic content assertion |
| `timezone_corruption` | All server metrics are normal — the data is wrong only at the display layer | Synthetic content assertion (wrong date in HTML) + RUM rage clicks |
| `bad_data_passengers` | DB is clean, PHP executes without errors, APM shows no slow spans | Monitor 18 (Passenger anomaly) + Synthetic content check for absurd numbers |
| `n_plus_one_pilots` | Page loads slightly slow but within normal thresholds on a small dataset | APM trace burst pattern (16 identical spans vs 1) + DBM query count |

These four faults are specifically designed to anchor a conversation about **why observability requires multiple signal types** — and why infrastructure monitoring alone is insufficient.
