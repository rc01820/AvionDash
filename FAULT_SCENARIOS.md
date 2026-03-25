# AvionDash — Fault Injection Scenarios

The Chaos Control Panel (`/aviondash/chaos.php`, admin only) lets you arm and disarm **20 fault injection scenarios** on demand using toggle switches. No application restart is needed — fault state is persisted to `storage/faults.json` and takes effect on the next page request.

---

## How It Works

1. Log in as `admin` / `password`
2. Navigate to `/aviondash/chaos.php` (visible in the sidebar under the ☠ icon)
3. Click any toggle switch to arm or disarm a fault
4. Load the affected page in another tab to trigger the fault
5. Observe the signal in Datadog
6. Click the toggle again to disarm

Fault state persists across browser sessions and server restarts until explicitly changed.

### Platform-Specific SQL Syntax

| Windows (sqlsrv) | Linux (MariaDB/PDO) |
|---|---|
| `WAITFOR DELAY '0:0:04'` | `AND SLEEP(4)=0` in WHERE clause |
| `WITH(INDEX(0))` force scan | `IGNORE INDEX (idx_flights_status)` |
| `sqlsrv_connect()` loop | `new PDO()` loop |

All PHP-level faults (memory, CPU, log flood, etc.) are identical across both platforms.

---

## Preset Combinations

Click a preset button to disarm all current faults and arm a specific combination in one click.

| Preset | Faults Armed | Best Used For |
|---|---|---|
| 🐢 **Slow Everything** | `slow_flights_query`, `slow_page_reports` | APM latency monitors, Synthetic timeouts, RUM LCP failures |
| 💥 **Error Storm** | `page_500_aircraft`, `auth_flap`, `health_check_flap` | HTTP 500 monitors, session flap logs, Synthetic flapping |
| 🗄 **DB Pressure** | `n_plus_one_pilots`, `missing_index_scan`, `connection_pool_exhaust` | MariaDB DBM: query plans, slow log, connection counts |
| 📊 **Data Chaos** | `log_flood`, `alert_cascade`, `bad_data_passengers`, `timezone_corruption` | Log monitors, business metric anomaly, content assertion failures |
| 🔥 **Resource Abuse** | `memory_leak_dashboard`, `cpu_spike_query_runner`, `disk_io_spike` | Infrastructure: CPU, memory, and disk I/O all firing at once |
| 🌐 **Dependency Faults** | `slow_third_party`, `exception_silencer` | Demonstrates what infrastructure monitoring alone misses |
| 📈 **Observability Gap** | `high_cardinality_tags`, `exception_silencer`, `timezone_corruption` | Monitoring blind spots: cardinality, silent failures, wrong data |

---

## All 20 Fault Scenarios

---

### 🐢 1. `slow_flights_query` — Slow Flights Query

| | |
|---|---|
| **Category** | Database |
| **Severity** | High |
| **Affected Page** | `/aviondash/flights.php` |
| **SQL Mechanism** | `AND SLEEP(4)=0` appended to the WHERE clause |

**What it does:**
Injects a 4-second MariaDB `SLEEP()` into every flight list query. The Flights Operations page jumps from ~80ms to over 4 seconds.

**What you see in the browser:**
The Flights page shows a spinning loading indicator for 4+ seconds before any content appears.

**Datadog detects:**
- APM trace for `GET /flights.php` shows a `mysql.query` span consuming 4 seconds
- MariaDB slow query log captures the `SLEEP()` call (threshold: 2s)
- Apache access log `%D` field shows 4,000,000+ microseconds
- APM p95 latency monitor breaches 3-second threshold

**Demo tip:**
Arm the fault → load `/aviondash/flights.php` → open Datadog APM → Traces → sort by Duration. The 4-second trace appears at the top. Expand the trace waterfall to show the `mysql.query` span consuming 90% of the request time.

---

### 🔁 2. `n_plus_one_pilots` — N+1 Pilot Lookups

| | |
|---|---|
| **Category** | Database |
| **Severity** | Medium |
| **Affected Page** | `/aviondash/dashboard.php` |
| **SQL Mechanism** | One `SELECT` per flight row instead of a JOIN |

**What it does:**
Replaces a single JOIN query with N individual SELECTs for pilot name lookups on the Dashboard airborne flights table. 15 airborne flights = 16 DB queries instead of 1.

**What you see in the browser:**
The Dashboard loads slightly slower than normal. The data is correct — the problem is entirely invisible to the user.

**Datadog detects:**
- APM trace shows a burst of 15–20 identical `mysql.query` spans with short individual durations
- DB Monitoring query count rate metric spikes
- Datadog Watchdog may surface the query volume anomaly

**Demo tip:**
Arm the fault → load the Dashboard → open APM → Traces → click the Dashboard trace → expand all spans. Show the audience the repeated identical `SELECT ... pilots` spans side by side. This is the most visual demo of an N+1 anti-pattern.

---

### 🔍 3. `missing_index_scan` — Full Table Scan (No Index)

| | |
|---|---|
| **Category** | Database |
| **Severity** | Medium |
| **Affected Page** | `/aviondash/flights.php` |
| **SQL Mechanism** | `IGNORE INDEX (idx_flights_status)` on the flights table |

**What it does:**
Appends `IGNORE INDEX (idx_flights_status)` to the flights query, forcing MariaDB to abandon the status index and perform a full table scan instead of an index seek.

**What you see in the browser:**
The Flights page may load slightly slower. No visible error — the impact is in the database engine.

**Datadog detects:**
- DB Monitoring execution plan shows `type: ALL` (full scan) instead of `type: ref`
- MariaDB `Handler_read_rnd_next` counter spikes — indicates rows read without index
- `mysql.performance.qcache_hits` drops
- Slow query log may capture it if scan is slow enough

**Demo tip:**
Arm the fault → load `/aviondash/flights.php` a few times → Datadog **Database Monitoring** → Query Metrics → find the flights query → click **Explain Plan**. Show `type: ALL` in the EXPLAIN output. This is the best demonstration of Datadog's query plan analysis capability.

---

### 🚰 4. `connection_pool_exhaust` — Connection Pool Leak

| | |
|---|---|
| **Category** | Database |
| **Severity** | Critical |
| **Affected Page** | `/aviondash/reports.php` |
| **SQL Mechanism** | 12 × `new PDO()` opened per page load, never closed |

**What it does:**
Opens 12 extra PDO database connections on every Reports page load and never closes them. After several page loads the connection pool is saturated.

**What you see in the browser:**
Reports page loads normally initially. After 5–6 rapid refreshes, new page loads may time out with a database connection error.

**Datadog detects:**
- `mysql.net.connections` metric climbs in a staircase pattern — +12 per Reports page load
- Change Alert monitor fires when connection count increases by >20 in 5 minutes
- PHP error log captures "Too many connections" once pool is full

**Demo tip:**
Arm the fault → rapidly refresh `/aviondash/reports.php` 5 times → Datadog **Infrastructure** → filter to `host:aviondash-server` → show the `mysql.net.connections` graph as a staircase rising with each page refresh.

---

### 💥 5. `page_500_aircraft` — Aircraft Page HTTP 500

| | |
|---|---|
| **Category** | Web / HTTP |
| **Severity** | Critical |
| **Affected Page** | `/aviondash/aircraft.php` |
| **SQL Mechanism** | PHP-level only — `throw new RuntimeException()` |

**What it does:**
Throws an unhandled `RuntimeException` before any output is sent on the Aircraft Status page, producing a genuine HTTP 500 response and blank page.

**What you see in the browser:**
The Aircraft Status page is completely blank or shows a generic server error. No data is displayed.

**Datadog detects:**
- APM error rate for `GET /aircraft.php` jumps to 100%
- Apache access log records `500` status for each request
- PHP error log captures the full exception message and stack trace
- Log Monitor for `source:php "RuntimeException"` fires

**Demo tip:**
Arm the fault → load `/aviondash/aircraft.php` (blank page) → Datadog **Logs** → search `source:php "RuntimeException"` → click the log entry to show the full stack trace in the detail panel. Then switch to APM → show 100% error rate on the aircraft resource.

---

### ⏳ 6. `slow_page_reports` — Slow Reports Page

| | |
|---|---|
| **Category** | Web / HTTP |
| **Severity** | High |
| **Affected Page** | `/aviondash/reports.php` |
| **SQL Mechanism** | `sleep(8)` in PHP before any rendering |

**What it does:**
Calls PHP `sleep(8)` before the Reports page renders, simulating a hung external dependency or runaway process. Time-to-first-byte blocks for 8 seconds.

**What you see in the browser:**
The Reports page shows a spinning tab loading indicator for 8 full seconds before any content appears.

**Datadog detects:**
- APM p90 latency for `GET /reports.php` exceeds 8 seconds
- Datadog Synthetic HTTP test for the Reports page times out and reports failure
- RUM `@view.loading_time` and LCP both exceed 8 seconds
- Apache `%D` field in access log shows 8,000,000+ microseconds

**Demo tip:**
Arm the fault → start loading `/aviondash/reports.php` → count aloud to 8 while the audience watches → then switch to Datadog **Synthetic Tests** to show the test failure notification. This is the most viscerally impactful demo for an audience unfamiliar with observability.

---

### 🧠 7. `memory_leak_dashboard` — Memory Leak (Dashboard)

| | |
|---|---|
| **Category** | Application |
| **Severity** | High |
| **Affected Page** | `/aviondash/dashboard.php` |
| **SQL Mechanism** | PHP-level only — 64 MB static array allocation per request |

**What it does:**
Allocates 64 MB of PHP arrays on every Dashboard load using `str_repeat()` inside a static variable, so the memory is never freed for the lifetime of the PHP-FPM worker process.

**What you see in the browser:**
The Dashboard appears to load normally. The memory leak is completely invisible to users.

**Datadog detects:**
- `system.mem.pct_usable` metric trends downward with each Dashboard refresh
- PHP-FPM slow log captures requests hitting memory pressure
- Eventually: PHP error log shows `Allowed memory size of 268435456 bytes exhausted`
- PHP-FPM worker restarts appear in the system log

**Demo tip:**
Arm the fault → rapidly refresh the Dashboard 10–15 times → Datadog **Infrastructure** → select `aviondash-server` → Memory widget → show the downward trend. For a dramatic finish, keep refreshing until PHP OOM kills the worker and the page briefly returns a 502.

---

### 📊 8. `bad_data_passengers` — Incorrect Passenger Counts

| | |
|---|---|
| **Category** | Application |
| **Severity** | Medium |
| **Affected Page** | `/aviondash/dashboard.php`, `/aviondash/flights.php` |
| **SQL Mechanism** | PHP post-query multiplication — `passengers_boarded × 847` |

**What it does:**
Multiplies all `passengers_boarded` values by 847 in PHP after the database query returns. The database is completely clean; only the application's output layer is corrupted.

**What you see in the browser:**
The Dashboard PAX TODAY KPI shows millions of passengers instead of ~1,000. The Flights table shows individual flights with absurd passenger counts.

**Datadog detects:**
- `aviation.pax.today` custom metric spikes to ~800× its normal value
- Anomaly monitor fires: the metric is 3+ deviations above its historical baseline
- RUM frustration signals: users who notice the wrong number rapidly click the refresh button (rage clicks)

**Demo tip:**
Arm the fault → open the Dashboard → point out the impossible PAX number → switch to Datadog **Metrics Explorer** → search `aviation.pax.today` → show the spike. Key lesson: the database and infrastructure are completely healthy — only a business metric monitor catches this class of bug.

---

### 🔥 9. `cpu_spike_query_runner` — CPU Spike (Query Runner)

| | |
|---|---|
| **Category** | Application |
| **Severity** | High |
| **Affected Page** | `/aviondash/query_runner.php` |
| **SQL Mechanism** | PHP-level only — tight `sqrt/log/sin` math loop for 3 seconds |

**What it does:**
Runs a tight CPU-bound computation loop (`sqrt * log * sin` across 50,000 iterations, repeated for 3 seconds) on every Query Runner page load, saturating one CPU core.

**What you see in the browser:**
The Query Runner page takes 3+ seconds to load even before any query is submitted.

**Datadog detects:**
- `system.cpu.user` spikes to ~100% for 3 seconds per request (visible on the host infrastructure metrics)
- APM p99 latency for `GET /query_runner.php` exceeds 4 seconds
- RUM Long Task events recorded in the browser during the TTFB wait

**Demo tip:**
Arm the fault → load `/aviondash/query_runner.php` and watch the 3-second delay → switch to Datadog **Infrastructure** → CPU graph for `aviondash-server` → show the spike pattern repeating with each page load.

---

### 📜 10. `log_flood` — Log Flood

| | |
|---|---|
| **Category** | Observability |
| **Severity** | Medium |
| **Affected Page** | All authenticated pages |
| **SQL Mechanism** | PHP-level only — 75 × `error_log()` per request |

**What it does:**
Writes 75 `WARNING`-level entries to the PHP error log on every authenticated page request across the entire application. This applies globally — every page load generates ~10 KB of log noise.

**What you see in the browser:**
Absolutely nothing — the application looks completely normal to users.

**Datadog detects:**
- Log Monitor: `source:php status:warn count > 200 in 5 minutes` fires within seconds
- Log ingestion volume anomaly: `datadog.estimated_usage.logs.ingested_bytes` spikes
- Datadog Log Management shows a dramatic surge in PHP warning volume

**Demo tip:**
Arm the fault → navigate through 3–4 pages → Datadog **Logs** → search `source:php status:warn` → show the flood of log entries. Then open the log volume timeseries widget to show the spike starting at the exact moment you armed the fault.

---

### 🔐 11. `auth_flap` — Session Flap (Auth Failures)

| | |
|---|---|
| **Category** | Observability |
| **Severity** | High |
| **Affected Page** | All authenticated pages |
| **SQL Mechanism** | PHP-level only — `session_destroy()` on 40% of loads |

**What it does:**
Randomly invalidates the PHP session on approximately 40% of page loads, forcing an unexpected redirect to `/aviondash/login.php`. The user logs back in and may be logged out again moments later.

**What you see in the browser:**
Users are randomly redirected to the login page mid-navigation with no explanation. Intermittent and unpredictable.

**Datadog detects:**
- Apache log Monitor: `@http.status_code:302 /login.php count > 30 in 5 minutes` fires
- Datadog Synthetic login browser test alternates between pass and fail
- RUM: unexpected view navigations to `/aviondash/login.php` from non-login referrers
- APM: span attribute showing session invalidation

**Demo tip:**
Arm the fault → navigate the application and wait for a random logout → meanwhile open Datadog **Synthetic Tests** → show the login test status alternating pass/fail in near-real-time. This is the best demonstration of why Synthetic tests catch intermittent failures that threshold monitors miss.

---

### 🚨 12. `alert_cascade` — Alert Storm

| | |
|---|---|
| **Category** | Observability |
| **Severity** | Critical |
| **Affected Page** | `/aviondash/alerts.php` |
| **SQL Mechanism** | `INSERT 20 rows` into `system_alerts` per page load |

**What it does:**
Inserts 20 new `critical` severity rows into `system_alerts` in MariaDB every time the Alerts page loads. The open alert count grows without bound.

**What you see in the browser:**
The Alerts page shows an ever-growing list of critical alerts with "FAULT INJECTED" in the message text. The Dashboard PAX TODAY KPI shows the open alert count climbing.

**Datadog detects:**
- `aviation.alerts.open_count` custom metric rises by 20 with each Alerts page load
- Change Alert monitor fires when count increases by >15 in 5 minutes
- Threshold monitor fires when absolute count exceeds 50

**Demo tip:**
Arm the fault → refresh `/aviondash/alerts.php` 3–4 times → Datadog **Metrics Explorer** → `aviation.alerts.open_count` → show the staircase graph rising with each refresh.

> **Cleanup required:** After this fault, run `CALL sp_ResetChaosAlerts();` in MariaDB to remove injected rows.

```bash
mariadb -u aviondash_app -p'Str0ngP@ssw0rd!' aviationdb \
  -e "CALL sp_ResetChaosAlerts();"
```

---

### 📦 13. `large_result_no_limit` — Large Result Set (No LIMIT)

| | |
|---|---|
| **Category** | Database |
| **Severity** | High |
| **Affected Page** | `/aviondash/reports.php` |
| **SQL Mechanism** | `preg_replace` removes the `LIMIT` clause from the SQL string |

**What it does:**
Removes the `LIMIT` clause from the Reports page query using a regex substitution, causing the query to return every historical flight row in the database without pagination.

**What you see in the browser:**
The Reports page takes longer to load and the table may show hundreds or thousands of rows instead of the expected paginated set.

**Datadog detects:**
- `mysql.performance.rows_sent` metric spikes significantly
- APM span shows increased response size for the `/aviondash/reports.php` resource
- DB Monitoring query metrics show unusual rows_examined/rows_sent ratio

**Demo tip:**
Run this fault alongside `alert_cascade` (which grows the database) to make the result set even larger over time. Load `/aviondash/reports.php` → DBM → Query Metrics → show the rows_sent spike compared to a normal request.

---

### 💿 14. `disk_io_spike` — Disk I/O Spike

| | |
|---|---|
| **Category** | Application |
| **Severity** | Medium |
| **Affected Page** | `/aviondash/dashboard.php` |
| **SQL Mechanism** | PHP-level only — 50 MB temp file write + read per request |

**What it does:**
Writes a 50 MB temporary file to disk and reads it back on every Dashboard load, simulating uncontrolled disk usage from a logging feature, export function, or poorly-implemented caching layer.

**What you see in the browser:**
The Dashboard loads 1–3 seconds slower than normal depending on disk speed.

**Datadog detects:**
- `system.io.util` metric spikes on the Dashboard page loads
- `system.disk.write_bytes` and `system.disk.read_bytes` show repeating spikes
- APM span duration increases — the disk I/O contributes to page latency

**Demo tip:**
Arm the fault → refresh the Dashboard 5 times → Datadog **Infrastructure** → select `aviondash-server` → Disk I/O section → show the write/read byte spikes with each refresh cycle.

---

### 🔒 15. `session_lock_contention` — Session Lock Contention

| | |
|---|---|
| **Category** | Application |
| **Severity** | High |
| **Affected Page** | All authenticated pages |
| **SQL Mechanism** | PHP-level only — `sleep(3)` while session file lock is held |

**What it does:**
Holds the PHP session file lock open for 3 seconds during each page request. Any concurrent request from the same browser session must wait for the lock to be released.

**What you see in the browser:**
If you open two browser tabs and load different pages simultaneously, the second tab waits 3+ seconds behind the first. Single-tab browsing is slow but functional.

**Datadog detects:**
- APM shows queued/waiting spans stacking when concurrent requests are made
- RUM Long Task events triggered by the TTFB wait
- Apache worker count rises as requests queue up

**Demo tip:**
Arm the fault → open two browser tabs simultaneously — one loading `/aviondash/flights.php` and one loading `/aviondash/reports.php` → watch the second tab wait → APM Traces → show the queued span waiting behind the first.

---

### 🔄 16. `health_check_flap` — Health Check Flap

| | |
|---|---|
| **Category** | Observability |
| **Severity** | High |
| **Affected Page** | `/aviondash/health.php` |
| **SQL Mechanism** | PHP-level only — odd/even counter in `/tmp/aviondash_health_counter` |

**What it does:**
Makes the `/aviondash/health.php` endpoint alternate between HTTP 200 OK and HTTP 503 Service Unavailable on every other request, simulating an unstable service in a recovery loop.

**What you see:**
Alternating 200 and 503 responses to the health endpoint.

**Datadog detects:**
- Datadog Synthetic HTTP test for `/aviondash/health.php` alternates pass and fail
- Service Check monitor shows a flapping state
- Uptime SLO burn rate increases

**Demo tip:**
Arm the fault → run in a terminal:
```bash
watch -n 0.5 "curl -s -o /dev/null -w '%{http_code}' http://localhost/aviondash/health.php"
```
Show the alternating 200/503 live → switch to Datadog Synthetic Tests → show the test status flapping.

**Cleanup:**
```bash
rm -f /tmp/aviondash_health_counter
```

---

### 🤫 17. `exception_silencer` — Exception Silencer

| | |
|---|---|
| **Category** | Observability |
| **Severity** | Medium |
| **Affected Page** | `/aviondash/dashboard.php` |
| **SQL Mechanism** | PHP-level only — `$GLOBALS` flag wraps DB calls in silent catch |

**What it does:**
Sets a global PHP flag that wraps all database queries in silent `try/catch` blocks that return empty arrays on failure, making database errors completely invisible to users and APM alike.

**What you see in the browser:**
The Dashboard shows empty tables and zeroed KPI cards with no error message. Everything looks normal except the data is gone.

**Datadog detects:**
- `aviation.flights.airborne` custom metric drops to 0 unexpectedly
- Datadog Synthetic browser test fails on content assertion ("AIRBORNE NOW" is missing)
- APM error rate stays at **0%** — this is the monitoring blind spot

**Demo tip:**
This fault is most powerful when combined with temporarily breaking the DB credentials (change `DB_PASS` in `config.php`). Armed alone, the data will just be empty. The key lesson: **infrastructure metrics look normal, APM shows 0% errors, but the application is silently broken**. Only a Synthetic content assertion or business metric monitor detects this.

---

### 🌐 18. `slow_third_party` — Slow External API Call

| | |
|---|---|
| **Category** | Web / HTTP |
| **Severity** | High |
| **Affected Page** | `/aviondash/reports.php` |
| **SQL Mechanism** | `curl` to `192.0.2.1` (RFC 5737 TEST-NET, non-routable) with 5-second timeout |

**What it does:**
Blocks for exactly 5 seconds on a `curl` call to a non-routable IP address (simulating a weather or NOTAM API) before rendering the Reports page. The external dependency is completely invisible to database monitoring.

**What you see in the browser:**
The Reports page blocks for 5 seconds before any content appears — identical in user experience to `slow_page_reports`, but with a different root cause.

**Datadog detects:**
- APM trace shows an `http-client` / `curl` span consuming 5 seconds — **distinct from a `mysql.query` span**
- This is the key demonstration: the span type tells you it's an external dependency, not a database problem
- Synthetic test times out
- DB Monitoring shows no slow queries — ruling out the database as the cause

**Demo tip:**
Arm this fault → load `/aviondash/reports.php` → APM Traces → expand the trace waterfall → show the `http-client` span consuming 5 seconds alongside the normal (fast) `mysql.query` spans. Key lesson: **if only infrastructure and DB monitoring were configured, this fault would be completely invisible. APM trace spans are the only surface that captures external dependency latency.**

---

### ⏰ 19. `timezone_corruption` — Timestamp Corruption

| | |
|---|---|
| **Category** | Application |
| **Severity** | Medium |
| **Affected Page** | `/aviondash/flights.php`, `/aviondash/dashboard.php` |
| **SQL Mechanism** | PHP `DateTime::modify('-24 hours')` on all datetime fields post-query |

**What it does:**
Shifts all datetime fields in query results back by 24 hours in PHP after the database query returns. The database contains correct timestamps; only the application's display layer is wrong.

**What you see in the browser:**
All flight scheduled times appear one day in the past. Flights that haven't departed yet show as already landed. The data looks corrupted but everything in the database is correct.

**Datadog detects:**
- **Infrastructure monitoring cannot detect this** — CPU, memory, DB, and logs are all normal
- Datadog Synthetic browser test fails on content assertion (expected departure date is wrong)
- RUM rage clicks: users see wrong dates and repeatedly click refresh
- Custom metric `aviation.flights.airborne` may show 0 (all flights appear landed)

**Demo tip:**
This fault is specifically designed to demonstrate **the limits of infrastructure-only monitoring**. Arm it → open `/aviondash/flights.php` → show that all dates are yesterday → switch to Datadog and show that every infrastructure metric is green. Ask the audience: "How would you detect this with only server metrics?" Then show the Synthetic content assertion failure — that's the only automatic signal.

---

### 📈 20. `high_cardinality_tags` — Metric Tag Cardinality Bomb

| | |
|---|---|
| **Category** | Observability |
| **Severity** | High |
| **Affected Page** | All authenticated pages |
| **SQL Mechanism** | UDP StatsD to Datadog Agent port 8125 with UUID tag value |

**What it does:**
Emits a custom StatsD metric (`aviondash.page.request`) with a unique UUID as a tag value (`request_id:xxxxxxxx-...`) on every page request, creating a brand-new metric series for each request and rapidly consuming Datadog's custom metric quota.

**What you see:**
No visible change in the application.

**Datadog detects:**
- `datadog.estimated_usage.metrics.custom` metric spikes rapidly
- Datadog's Metrics Cardinality management UI shows `aviondash.page.request` as a new high-cardinality source
- Usage alerts fire if configured on the custom metric count
- Datadog may automatically apply cardinality reduction

**Demo tip:**
Arm the fault → navigate through 5–10 pages → Datadog **Plan & Usage** → **Custom Metrics** → show the count increasing with each page load. Then open **Metrics → Cardinality** (if your plan includes it) to show the source. Key lesson: **bad tagging practices cause platform-level problems that are entirely independent of application health.**

> **Note:** This fault requires the Datadog Agent to be running with StatsD enabled on port 8125 (default). If the Agent is not running, the UDP packets are silently dropped and no metric is emitted.

---

## Monitor Quick Reference

| Fault | Primary Monitor Type | Key Signal |
|---|---|---|
| `slow_flights_query` | APM Threshold | p95 on /flights.php > 3s |
| `n_plus_one_pilots` | APM Trace Analytics | Burst of identical mysql.query spans |
| `missing_index_scan` | DB Monitoring | EXPLAIN type: ALL |
| `connection_pool_exhaust` | Metric Change Alert | mysql.net.connections +20 in 5min |
| `page_500_aircraft` | APM Error Rate | error_rate on /aircraft.php > 5% |
| `slow_page_reports` | Synthetic | HTTP timeout > 7s |
| `memory_leak_dashboard` | Metric | system.mem.pct_usable < 15% |
| `bad_data_passengers` | Anomaly | aviation.pax.today 3× deviation |
| `cpu_spike_query_runner` | Metric | system.cpu.user > 85% |
| `log_flood` | Log Monitor | source:php status:warn > 200/5min |
| `auth_flap` | Synthetic | Login browser test flapping |
| `alert_cascade` | Metric Change Alert | aviation.alerts.open_count +15/5min |
| `large_result_no_limit` | Metric | mysql.performance.rows_sent spike |
| `disk_io_spike` | Metric | system.io.util > 70% |
| `session_lock_contention` | APM | Queued spans in trace waterfall |
| `health_check_flap` | Synthetic | HTTP /health.php assert 200 |
| `exception_silencer` | Custom Metric | aviation.flights.airborne = 0 |
| `slow_third_party` | APM | http-client span > 4s |
| `timezone_corruption` | Synthetic | Content assertion (date field wrong) |
| `high_cardinality_tags` | Metric | datadog.estimated_usage.metrics.custom spike |

---

## Resetting After Faults

```bash
# Disarm all faults via /chaos.php → DISARM ALL

# Clean up alert_cascade injected rows
mariadb -u aviondash_app -p'Str0ngP@ssw0rd!' aviationdb \
  -e "CALL sp_ResetChaosAlerts();"

# Clean up health_check_flap counter
rm -f /tmp/aviondash_health_counter

# Verify all faults are disarmed
cat /var/www/html/aviondash/storage/faults.json
# All 20 values should be false

# Wait 2-3 minutes for Datadog monitors to recover to OK state
```
