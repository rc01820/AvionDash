# AvionDash — Aviation Operations Monitor

> A multi-tier aviation operations web application built as a **Datadog monitoring demonstration platform**.

AvionDash simulates a real airline operations centre — tracking live flight statuses, fleet health, crew records, airport routing, maintenance logs, and operational alerts — all backed by real SQL queries against a production-class database. Every monitoring signal it generates is authentic rather than synthetic noise.

---

## What Is This?

AvionDash is designed to show how Datadog monitors a **PHP web application with a database backend** across the full observability stack:

| Surface | What AvionDash Exercises |
|---|---|
| **APM** | Page latency, DB span duration, error rates, trace waterfall |
| **DB Monitoring** | Slow queries, missing indexes, connection counts, execution plans |
| **Log Management** | PHP error log, Apache access log, WARNING volume monitors |
| **Infrastructure** | CPU, memory, disk I/O on the web/DB server |
| **RUM** | Page load time, Core Web Vitals, rage clicks, Session Replay |
| **Synthetic** | Login flow, page SLA, health endpoint flapping |
| **Custom Metrics** | `aviation.flights.airborne`, `aviation.pax.today`, `aviation.alerts.open_count` |

The application also includes a **Chaos Control Panel** (`/aviondash/chaos.php`) that injects 20 configurable fault scenarios on demand — from slow DB queries to connection pool leaks to silent exception swallowing — each designed to trigger specific Datadog monitors.

---

## Technology Stack

### Linux (Primary)

| Component | Technology |
|---|---|
| OS | Red Hat Enterprise Linux 9 / Rocky Linux 9 / AlmaLinux 9 |
| Web server | Apache httpd 2.4 + PHP-FPM 8.2 |
| Database | MariaDB 10.11 LTS |
| PHP DB driver | PDO with `pdo_mysql` |
| PHP session | File-based via PHP-FPM pool |

### Windows (Alternative)

| Component | Technology |
|---|---|
| OS | Windows Server 2022 |
| Web server | IIS 10 with PHP Manager for IIS |
| Database | Microsoft SQL Server 2022 |
| PHP DB driver | `sqlsrv` (Microsoft PHP Drivers for SQL Server) |

---

## Application Pages

| Page | URL | Access | Description |
|---|---|---|---|
| Login | `/aviondash/login.php` | Public | Session auth with bcrypt hashing |
| Dashboard | `/aviondash/dashboard.php` | All | Live KPIs, airborne flights, 7-day OTP chart |
| Flight Operations | `/aviondash/flights.php` | All | Filterable flight list with delays and PAX |
| Aircraft Status | `/aviondash/aircraft.php` | All | Fleet registry and maintenance log |
| Airports & Routes | `/aviondash/airports.php` | All | Airport directory and top 10 routes |
| Alerts | `/aviondash/alerts.php` | All | Operational alert board by severity |
| Reports | `/aviondash/reports.php` | All | Six pre-built SQL reports |
| Query Runner | `/aviondash/query_runner.php` | Analyst + Admin | Ad-hoc `SELECT` query editor |
| Health Check | `/aviondash/health.php` | All | JSON health endpoint for uptime monitors |
| About | `/aviondash/about.php` | All | Static info and monitoring reference |
| **Chaos Control** | `/aviondash/chaos.php` | **Admin only** | 20-fault injection control panel |

---

## User Roles

| Username | Password | Role |
|---|---|---|
| `admin` | `password` | Full access including Chaos Control Panel |
| `analyst` | `password` | All pages + Query Runner + resolve alerts |
| `viewer` | `password` | Read-only access to all report pages |

> **Always use `admin` during demonstrations** — it is the only role that can see and use the Chaos Control Panel.

---

## Database

The application uses a database named `aviationdb` (MariaDB) or `AviationDB` (SQL Server) pre-populated with synthetic seed data:

| Table | Rows | Contents |
|---|---|---|
| `users` | 3 | Application login accounts |
| `airports` | 12 | ICAO/IATA codes, coordinates, timezone, hub flag |
| `aircraft` | 12 | Fleet registry — manufacturer, model, capacity, status |
| `pilots` | 10 | Crew records — license type, hours, base airport |
| `flights` | 15 | Mix of past, current, and future flights |
| `maintenance_logs` | 8 | MX events with cost, technician, discrepancy code |
| `system_alerts` | Dynamic | Operational alerts (grows with `alert_cascade` fault) |

**Stored procedures:** `sp_FlightSummaryByDate`, `sp_AircraftUtilization`, `sp_TopDelayReasons`, `sp_ResetChaosAlerts`

---

## Chaos Control — 20 Fault Scenarios

The Chaos Control Panel lets you arm and disarm fault injection scenarios using toggle switches. No application restart is needed. Fault state is persisted in `storage/faults.json`.

### Categories

| Category | Faults | What They Exercise |
|---|---|---|
| **Database** | 4 | Slow queries, N+1, full table scans, connection leaks |
| **Web / HTTP** | 3 | HTTP 500 errors, slow pages, slow external APIs |
| **Application** | 5 | Memory leaks, CPU spikes, corrupt data, disk I/O, session locks |
| **Observability** | 8 | Log floods, session flap, alert storms, health flapping, silent exceptions, cardinality bombs, timestamp corruption |

See [`FAULT_SCENARIOS.md`](FAULT_SCENARIOS.md) for full details on all 20 faults, their Datadog detection methods, and demo tips.

---

## Quick Start

See [`SETUP.md`](SETUP.md) for the complete installation walkthrough. The short version:

```bash
# Install dependencies (RHEL 9)
dnf install -y httpd php php-fpm php-mysqlnd php-pdo mariadb-server

# Deploy and load schema
cp -r . /var/www/html/aviondash/
mariadb -u root -p aviationdb < /var/www/html/aviondash/schema_mariadb.sql

# Set permissions and start
chown -R apache:apache /var/www/html/aviondash/
systemctl restart httpd php-fpm

# Browse to
http://your-server/
```

---

## Datadog Custom Metrics

The following custom metrics are emitted via the Datadog Agent `custom_queries` in `mysql.d/conf.yaml`:

| Metric | Type | Description |
|---|---|---|
| `aviation.flights.airborne` | gauge | Count of flights currently airborne |
| `aviation.flights.total_today` | gauge | Total flights scheduled today |
| `aviation.alerts.open_count` | gauge | Open (unresolved) system alerts |
| `aviation.pax.today` | gauge | Passengers boarded on today's landed flights |

These metrics power business-level monitors and dashboard widgets that cannot be derived from infrastructure signals alone.

---

## Datadog Tags

All data is tagged consistently so monitors, traces, logs, and metrics can be correlated across surfaces:

| Tag | Value | Applied To |
|---|---|---|
| `service` | `aviondash` | All data |
| `env` | `demo` | All data |
| `app` | `aviondash` | All data |
| `host` | `aviondash-server` | Infrastructure metrics |
| `tier` | `web` / `database` | Integration metrics |
| `component` | `apache` / `php` / `mariadb` | Metrics and logs |
| `page` | `flights` / `reports` / … | APM spans (per page) |
| `fault.*` | `true` when armed | APM spans (dynamic) |

---

## Repository Structure

```
aviondash/
├─ includes/                  ← PHP back-end (not web-accessible)
│   ├─ config.php             ← DB credentials, session constants
│   ├─ db.php                 ← PDO/MariaDB connection wrapper
│   ├─ auth.php               ← Login, logout, session management
│   ├─ fault_inject.php       ← Chaos engine — 12 original faults
│   ├─ additional_faults.php  ← 8 additional fault implementations
│   ├─ layout_header.php      ← HTML head, sidebar nav, topbar
│   └─ layout_footer.php      ← Closing tags, loads script.js
│
├─ assets/
│   ├─ style.css              ← Dark glass-cockpit theme
│   └─ script.js              ← Live clock, sidebar, SQL editor
│
├─ storage/
│   └─ faults.json            ← Fault armed/disarmed state (JSON)
│
├─ login.php                  ← Authentication form
├─ logout.php                 ← Session destroy
├─ dashboard.php              ← KPI cards, airborne table, OTP chart
├─ flights.php                ← Filterable flight list
├─ aircraft.php               ← Fleet registry + maintenance log
├─ airports.php               ← Airport directory + top routes
├─ alerts.php                 ← Alert board by severity
├─ reports.php                ← Six pre-built SQL report tabs
├─ query_runner.php           ← Ad-hoc SELECT editor
├─ health.php                 ← JSON health endpoint
├─ about.php                  ← Static info page
├─ chaos.php                  ← Fault injection control panel
│
├─ schema_mariadb.sql         ← MariaDB schema, seed data, procs
├─ aviondash-demo.conf        ← Apache Alias config (sits alongside /demo site)
├─ aviondash-fpm.conf         ← PHP-FPM pool configuration
├─ .htaccess                  ← URL rewrite, security headers
│
├─ README.md                  ← This file
├─ SETUP.md                   ← Full installation walkthrough
└─ FAULT_SCENARIOS.md         ← All 20 chaos fault details
```

---

## Security Notes

- All demo passwords are `password` — change before any non-isolated use
- The `aviondash_app` database user has `SELECT` only (plus limited `UPDATE` on `system_alerts`)
- The Query Runner blocks all non-`SELECT` statements via regex + keyword allowlist
- `includes/` and `storage/` are blocked from direct web access via `.htaccess` and virtual host config
- All DB calls use PDO parameterised queries — no string concatenation
- `display_errors` is `Off` — errors log to file only

---

## License

This application is a demonstration platform. All flight, crew, airport, and maintenance data is entirely synthetic and fictional. No real operational data is used.
