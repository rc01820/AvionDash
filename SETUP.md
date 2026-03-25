# AvionDash — Setup Walkthrough

Complete installation guide for RHEL with Apache httpd, PHP-FPM 8.2, and MariaDB 10.11.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Install Apache httpd](#2-install-apache-httpd)
3. [Install PHP 8.2 and PHP-FPM](#3-install-php-82-and-php-fpm)
4. [Install and Configure MariaDB](#4-install-and-configure-mariadb)
5. [Create Database and Application User](#5-create-database-and-application-user)
6. [Deploy Application Files](#6-deploy-application-files)
7. [Configure Apache Virtual Host](#7-configure-apache-virtual-host)
8. [Configure PHP-FPM Pool](#8-configure-php-fpm-pool)
9. [Configure SELinux](#9-configure-selinux)
10. [Configure Firewall](#10-configure-firewall)
11. [Set Permissions](#11-set-permissions)
12. [Verify the Installation](#12-verify-the-installation)
13. [Install Datadog Agent](#13-install-datadog-agent)
14. [Configure Datadog Integrations](#14-configure-datadog-integrations)
15. [Install PHP APM Tracer](#15-install-php-apm-tracer)
16. [Configure RUM Browser SDK](#16-configure-rum-browser-sdk)
17. [Configure Chaos Control Storage](#17-configure-chaos-control-storage)
18. [Troubleshooting](#18-troubleshooting)

---

## 1. Prerequisites

| Component | Minimum | Notes |
|---|---|---|
| OS | RHEL 9.x | Rocky / Alma Linux 9 also fully supported |
| CPU | 2 vCPUs | `cpu_spike` fault pegs one core |
| RAM | 4 GB | `memory_leak` fault allocates 64 MB per Dashboard load |
| Disk | 20 GB | OS + logs + MariaDB data |
| Network | Port 80 open | Port 443 optional for HTTPS |
| Root / sudo | Required | All setup commands require elevated privileges |

> **Run all commands as root or via `sudo`.**

---

## 2. Install Apache httpd

```bash
# Install Apache and SSL module
dnf install -y httpd mod_ssl

# Enable and start
systemctl enable --now httpd

# Verify mod_rewrite is available (required for .htaccess)
httpd -M | grep rewrite
# Expected: rewrite_module (shared)
```

### Enable mod_status (required for Datadog Apache integration)

Create `/etc/httpd/conf.d/status.conf`:

```apache
<Location "/server-status">
    SetHandler server-status
    Require local
</Location>
```

```bash
systemctl reload httpd
# Verify
curl -s http://localhost/server-status?auto | head -5
```

---

## 3. Install PHP 8.2 and PHP-FPM

```bash
# Enable the PHP 8.2 module stream
dnf module reset php
dnf module enable php:8.2

# Install PHP and all required extensions
dnf install -y \
    php \
    php-fpm \
    php-mysqlnd \
    php-pdo \
    php-mbstring \
    php-json \
    php-opcache \
    php-session \
    php-xml \
    php-curl

# Enable and start PHP-FPM
systemctl enable --now php-fpm

# Verify
php --version              # PHP 8.2.x (cli)
php -m | grep pdo_mysql    # pdo_mysql
systemctl is-active php-fpm  # active
```

### Configure php.ini

Create `/etc/php.d/99-aviondash.ini`:

```ini
; Error handling — log only, never display
display_errors          = Off
log_errors              = On
error_log               = /var/log/aviondash/php_errors.log
error_reporting         = E_ALL & ~E_DEPRECATED

; Session security
session.cookie_httponly = 1
session.use_strict_mode = 1
session.gc_maxlifetime  = 1800

; Resource limits
memory_limit            = 256M
max_execution_time      = 60
post_max_size           = 8M
upload_max_filesize     = 8M

; OPcache (significant performance improvement)
opcache.enable                  = 1
opcache.memory_consumption      = 128
opcache.max_accelerated_files   = 4000
opcache.validate_timestamps     = 1
```

---

## 4. Install and Configure MariaDB

```bash
# Install MariaDB 10.11
# If RHEL AppStream only has 10.5, add the official MariaDB repo first:
# curl -sS https://downloads.mariadb.com/MariaDB/mariadb_repo_setup \
#   | bash -s -- --mariadb-server-version="mariadb-10.11"

dnf install -y mariadb-server mariadb

# Enable and start
systemctl enable --now mariadb

# Secure the installation
# Accept all defaults: set root password, remove anonymous users,
# disallow remote root login, remove test database
mariadb-secure-installation
```

### MariaDB Performance Tuning

Create `/etc/my.cnf.d/aviondash.cnf`:

```ini
[mysqld]
# Character set
character-set-server      = utf8mb4
collation-server          = utf8mb4_unicode_ci
max_connections           = 100

# InnoDB buffer pool — set to 50-70% of available RAM
# Adjust for your server: 1G for a 4GB server, 2G for 8GB, etc.
innodb_buffer_pool_size       = 1G
innodb_buffer_pool_instances  = 1

# Slow query log — critical for Datadog DB Monitoring demo
# Catches the slow_flights_query and missing_index_scan faults
slow_query_log                    = 1
slow_query_log_file               = /var/log/mariadb/slow.log
long_query_time                   = 2
log_queries_not_using_indexes     = 1

# Performance schema — required for Datadog DB Monitoring (dbm: true)
performance_schema = ON

# Binary log — enables some Datadog DBM features
log_bin           = /var/log/mariadb/mariadb-bin
binlog_format     = ROW
expire_logs_days  = 3

[client]
default-character-set = utf8mb4
```

```bash
# Apply configuration
systemctl restart mariadb

# Verify slow query log is active
mariadb -u root -p -e "SHOW VARIABLES LIKE 'slow_query%';"
```

---

## 5. Create Database and Application User

```bash
mariadb -u root -p << 'EOF'

-- Create database
CREATE DATABASE IF NOT EXISTS aviationdb
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Create application user (never use root for app connections)
CREATE USER IF NOT EXISTS 'aviondash_app'@'localhost'
  IDENTIFIED BY 'Str0ngP@ssw0rd!';

-- Minimum required permissions
GRANT SELECT ON aviationdb.*                     TO 'aviondash_app'@'localhost';
GRANT INSERT, UPDATE ON aviationdb.system_alerts TO 'aviondash_app'@'localhost';
GRANT UPDATE (last_login) ON aviationdb.users    TO 'aviondash_app'@'localhost';

-- Required for Datadog DB Monitoring (dbm: true)
GRANT PROCESS, REPLICATION CLIENT ON *.*         TO 'aviondash_app'@'localhost';
GRANT SELECT ON performance_schema.*             TO 'aviondash_app'@'localhost';

FLUSH PRIVILEGES;
EOF
```

### Load Schema and Seed Data

```bash
mariadb -u root -p aviationdb < /var/www/html/aviondash/schema_mariadb.sql

# Verify
mariadb -u root -p -e "USE aviationdb; SHOW TABLES;"
mariadb -u root -p -e "SELECT COUNT(*) AS flights FROM aviationdb.flights;"
# Expected: 15

# Test application user connection
mariadb -u aviondash_app -p'Str0ngP@ssw0rd!' \
  -e "SELECT username, role FROM aviationdb.users;"
```

---

## 6. Deploy Application Files

```bash
# Create web root
mkdir -p /var/www/html/aviondash

# Copy all application files
# (adjust source path to where you extracted the repository)
cp -r /path/to/aviondash/. /var/www/html/aviondash/

# Update database credentials in config.php if you changed the password
nano /var/www/html/aviondash/includes/config.php
# Set DB_PASS to your chosen password for aviondash_app
```

---

## 7. Configure Apache (Alias — Side-by-Side with Existing Site)

```bash
# Copy virtual host config
cp /var/www/html/aviondash/aviondash.conf /etc/httpd/conf.d/aviondash.conf

# Edit ServerName to match your server hostname or IP
nano /etc/httpd/conf.d/aviondash.conf
# Change: ServerName aviondash.local
# To:     ServerName your-server-hostname-or-ip

# Create the log directory
mkdir -p /var/log/aviondash
chown apache:apache /var/log/aviondash

# Test configuration
apachectl configtest
# Expected: Syntax OK

# Reload Apache
systemctl reload httpd
```

The `aviondash.conf` virtual host includes:
- PHP-FPM Unix socket handler for all `.php` files
- Response time (`%D` in microseconds) in the access log — critical for APM correlation
- Block on direct access to `includes/` and `storage/`

---

## 8. Configure PHP-FPM Pool

```bash
# Copy the AvionDash PHP-FPM pool config
cp /var/www/html/aviondash/aviondash-fpm.conf /etc/php-fpm.d/aviondash.conf

# Disable the default www pool to avoid conflicts
mv /etc/php-fpm.d/www.conf /etc/php-fpm.d/www.conf.disabled

# Create the session directory for this pool
mkdir -p /var/lib/php/session/aviondash
chown apache:apache /var/lib/php/session/aviondash
chmod 700 /var/lib/php/session/aviondash

# Restart PHP-FPM to load the new pool
systemctl restart php-fpm

# Verify the socket was created
ls -la /run/php-fpm/aviondash.sock
# Expected: srw-rw---- apache apache

# Confirm slow log is configured
grep slowlog /etc/php-fpm.d/aviondash.conf
```

The `aviondash-fpm.conf` pool includes:
- Dynamic process management (2–8 workers)
- **Slow log at 2 seconds** — captures `slow_flights_query` and `slow_page_reports` faults
- Worker recycle after 500 requests (makes `memory_leak_dashboard` more visible)
- Dedicated session directory at `/var/lib/php/session/aviondash`

---

## 9. Configure SELinux

> **Do not disable SELinux.** Configure it correctly instead.

```bash
# Verify SELinux is enforcing
getenforce
# Expected: Enforcing

# Allow Apache to connect to PHP-FPM via Unix socket
setsebool -P httpd_can_network_connect 1

# Allow PHP-FPM to connect to MariaDB on localhost
setsebool -P httpd_can_network_connect_db 1

# Set correct file contexts
semanage fcontext -a -t httpd_sys_content_t    "/var/www/html/aviondash(/.*)?"
semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/aviondash/storage(/.*)?"
semanage fcontext -a -t httpd_log_t            "/var/log/aviondash(/.*)?"

# Apply the contexts
restorecon -Rv /var/www/html/aviondash/
restorecon -Rv /var/log/aviondash/

# Verify storage/ context is correct
ls -laZ /var/www/html/aviondash/storage/
# Should show: httpd_sys_rw_content_t on faults.json
```

### SELinux Troubleshooting

If you get a 403 or PHP cannot write to `storage/`:

```bash
# Diagnose exactly what SELinux is blocking
ausearch -c httpd --raw | audit2why

# Common fix — re-apply contexts
restorecon -Rv /var/www/html/aviondash/

# Alternative — generate a custom policy module
ausearch -c httpd --raw | audit2allow -M aviondash
semodule -i aviondash.pp
```

---

## 10. Configure Firewall

```bash
# Open HTTP (required)
firewall-cmd --permanent --add-service=http

# Open HTTPS (optional — for production use with TLS)
firewall-cmd --permanent --add-service=https

# Apply changes
firewall-cmd --reload

# Verify
firewall-cmd --list-all
# services: should include http
```

> MariaDB port 3306 — leave **closed** for external access. The application connects via localhost socket, not TCP.

---

## 11. Set Permissions

```bash
# Set ownership — Apache process needs read access
chown -R apache:apache /var/www/html/aviondash

# PHP files — read + execute, no write (prevents code injection)
find /var/www/html/aviondash -type f -name "*.php" -exec chmod 640 {} \;

# Static assets — readable by Apache
find /var/www/html/aviondash/assets -type f -exec chmod 644 {} \;

# storage/ — Apache must be able to read AND write (fault state JSON)
chmod 750 /var/www/html/aviondash/storage
chmod 660 /var/www/html/aviondash/storage/faults.json

# Schema file — restrict to root only (contains DB credentials in comments)
chmod 600 /var/www/html/aviondash/schema_mariadb.sql

# Log directory
mkdir -p /var/log/aviondash
chown apache:apache /var/log/aviondash
chmod 755 /var/log/aviondash

# Restart services to apply all changes
systemctl restart php-fpm httpd
```

---

## 12. Verify the Installation

Run each check in order. All should pass before proceeding to Datadog setup.

```bash
# 1. Services running
systemctl is-active httpd php-fpm mariadb
# All three should output: active

# 2. PHP-FPM socket exists
ls /run/php-fpm/aviondash.sock

# 3. PHP → MariaDB connectivity
php -r "
try {
  \$pdo = new PDO(
    'mysql:host=localhost;dbname=aviationdb;charset=utf8mb4',
    'aviondash_app',
    'Str0ngP@ssw0rd!'
  );
  echo 'DB Connection: OK' . PHP_EOL;
} catch (PDOException \$e) {
  echo 'FAILED: ' . \$e->getMessage() . PHP_EOL;
}
"

# 4. HTTP redirect from root
curl -I http://localhost/aviondash/
# Expected: HTTP/1.1 302 Found
#           Location: /aviondash/login.php

# 5. Login page returns 200
curl -s -o /dev/null -w "%{http_code}" http://localhost/aviondash/login.php
# Expected: 200

# 6. Health check endpoint
curl -s http://localhost/aviondash/health.php | python3 -m json.tool
# Expected: {"status": "ok", ...}

# 7. includes/ is blocked from web access
curl -s -o /dev/null -w "%{http_code}" http://localhost/aviondash/includes/config.php
# Expected: 403

# 8. storage/ is blocked from web access
curl -s -o /dev/null -w "%{http_code}" http://localhost/aviondash/storage/faults.json
# Expected: 403
```

Open `http://your-server-ip/aviondash/` in a browser. You should be redirected to `/login.php`. Log in with `admin` / `password` and verify the Dashboard loads with live flight data.

---

## 13. Install Datadog Agent

```bash
# Replace YOUR_DD_API_KEY with your actual Datadog API key
DD_API_KEY="YOUR_DD_API_KEY" \
DD_SITE="datadoghq.com" \
bash -c "$(curl -L https://s3.amazonaws.com/dd-agent-bootstrap/scripts/install_script_agent7.sh)"

# Verify the Agent is running
sudo datadog-agent status
```

### Configure Universal Tags

Edit `/etc/datadog-agent/datadog.yaml`:

```yaml
api_key: YOUR_DD_API_KEY_HERE
site:    datadoghq.com
hostname: aviondash-server

# Universal tags — appear on ALL data from this Agent
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
```

```bash
systemctl restart datadog-agent
```

---

## 14. Configure Datadog Integrations

### Apache Integration

Create `/etc/datadog-agent/conf.d/apache.d/conf.yaml`:

```yaml
init_config:

instances:
  - apache_status_url: http://localhost/server-status?auto
    tags:
      - env:demo
      - service:aviondash
      - tier:web
      - component:apache

logs:
  # Apache access log (includes response time in %D field)
  - type: file
    path: /var/log/httpd/aviondash_access.log
    source: apache
    service: aviondash-web
    tags:
      - env:demo
      - tier:web
      - component:apache
      - app:aviondash

  # Apache error log
  - type: file
    path: /var/log/httpd/aviondash_error.log
    source: apache
    service: aviondash-web
    tags:
      - env:demo
      - tier:web
      - component:apache

  # PHP error log (captures all fault injection log entries)
  - type: file
    path: /var/log/aviondash/php_errors.log
    source: php
    service: aviondash-web
    tags:
      - env:demo
      - tier:web
      - component:php
      - app:aviondash
```

### MariaDB Integration with Custom Business Metrics

Create `/etc/datadog-agent/conf.d/mysql.d/conf.yaml`:

> **Note:** Datadog uses the `mysql` integration for MariaDB.

```yaml
init_config:

instances:
  - host: localhost
    port: 3306
    username: aviondash_app
    password: 'Str0ngP@ssw0rd!'
    database: aviationdb
    connector: pymysql
    dbm: true
    query_metrics:   {enabled: true}
    query_samples:   {enabled: true}
    query_activity:  {enabled: true}
    tags:
      - env:demo
      - service:aviondash
      - tier:database
      - component:mariadb
      - app:aviondash

    # Custom business metrics — emitted as Datadog gauges
    custom_queries:
      # Flights airborne right now
      - query: >
          SELECT
            SUM(status = 'airborne') AS airborne,
            COUNT(*) AS total_today
          FROM flights
          WHERE DATE(scheduled_dep) = CURDATE()
        columns:
          - {name: aviation.flights.airborne,    type: gauge}
          - {name: aviation.flights.total_today, type: gauge}
        tags: [env:demo, service:aviondash, app:aviondash]

      # Open (unresolved) system alerts
      - query: >
          SELECT COUNT(*) AS open_alerts
          FROM system_alerts
          WHERE is_resolved = 0
        columns:
          - {name: aviation.alerts.open_count, type: gauge}
        tags: [env:demo, service:aviondash]

      # Passengers boarded today (detected by bad_data_passengers fault)
      - query: >
          SELECT IFNULL(SUM(passengers_boarded), 0) AS pax
          FROM flights
          WHERE DATE(scheduled_dep) = CURDATE()
            AND status = 'landed'
        columns:
          - {name: aviation.pax.today, type: gauge}
        tags: [env:demo, service:aviondash, app:aviondash]
```

### Restart the Agent

```bash
systemctl restart datadog-agent

# Verify integrations are running
sudo datadog-agent status | grep -A 8 "apache"
sudo datadog-agent status | grep -A 8 "mysql"

# Confirm custom metrics are flowing (wait ~2 minutes)
# Check in Datadog: Metrics Explorer → aviation.flights.airborne
```

---

## 15. Install PHP APM Tracer

```bash
# Download and install the Datadog PHP tracer
curl -LO https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php
php datadog-setup.php --php-bin=all

# Add tracer configuration to /etc/php.d/99-aviondash.ini
cat >> /etc/php.d/99-aviondash.ini << 'EOF'

[ddtrace]
extension = ddtrace.so

; Service identity — must match tags in datadog.yaml
datadog.service        = aviondash
datadog.env            = demo
datadog.version        = 1.0.0

; Inject trace_id into PHP error logs for log-to-trace correlation
datadog.logs_injection = true

; Tag every span with app-level context
datadog.global_tags    = app:aviondash,tier:web,component:php,os:rhel

datadog.trace.enabled  = 1
datadog.trace.debug    = 0
EOF

# Restart PHP-FPM to load the tracer extension
systemctl restart php-fpm

# Verify the tracer loaded
php -m | grep ddtrace
# Expected: ddtrace
```

---

## 16. Configure RUM Browser SDK

### Step 1 — Create a RUM Application in Datadog

1. Navigate to **UX Monitoring → Real User Monitoring → New Application**
2. Name: `AvionDash`
3. Application type: **Browser**
4. Enable **Session Replay**
5. Copy the generated `clientToken` and `applicationId`

### Step 2 — Add the SDK to layout_header.php

Add the following block **before `</head>`** in `/var/www/html/aviondash/includes/layout_header.php`:

```html
<script src="https://www.datadoghq-browser-agent.com/datadog-rum.js"
        type="text/javascript"></script>
<script>
  window.DD_RUM && DD_RUM.init({
    clientToken:             'YOUR_CLIENT_TOKEN',
    applicationId:           'YOUR_APPLICATION_ID',
    site:                    'datadoghq.com',
    service:                 'aviondash',
    env:                     'demo',
    version:                 '1.0.0',
    sessionSampleRate:       100,
    sessionReplaySampleRate: 100,
    trackResources:          true,
    trackLongTasks:          true,
    trackUserInteractions:   true,
    defaultPrivacyLevel:     'mask-user-input',
  });

  // Tag sessions with current page name
  window.DD_RUM && DD_RUM.setGlobalContextProperty(
    'page', '<?= htmlspecialchars(basename($_SERVER["PHP_SELF"], ".php")) ?>'
  );

  // Tag sessions with currently armed fault IDs
  <?php if (class_exists('FaultEngine')) {
    $armed = array_keys(array_filter(FaultEngine::allState()));
    foreach ($armed as $fid) {
      echo "window.DD_RUM && DD_RUM.setGlobalContextProperty('fault.{$fid}', 'true');\n";
    }
  } ?>

  window.DD_RUM && window.DD_RUM.startSessionReplayRecording();
</script>
```

### Step 3 — Verify RUM Data is Flowing

1. Open `http://your-server/dashboard.php` in a browser
2. In Datadog: **UX Monitoring → Real User Monitoring → AvionDash**
3. Page views should appear within 30 seconds
4. If no data: check browser DevTools → Network tab for requests to `datadoghq.com`

---

## 17. Configure Chaos Control Storage

The Chaos Control Panel stores fault state in `storage/faults.json`. The `apache` user must be able to write to this file.

```bash
# Verify the file exists and is writable
ls -la /var/www/html/aviondash/storage/faults.json

# Test write access as the apache user
sudo -u apache sh -c 'echo "test" > /tmp/apache_write_test && echo "Write OK"'

# If storage/ write fails, re-apply SELinux context
semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/aviondash/storage(/.*)?"
restorecon -Rv /var/www/html/aviondash/storage/

# Verify the chaos panel loads
curl -s -o /dev/null -w "%{http_code}" http://localhost/chaos.php
# Expected: 302 (redirects to login if not authenticated — that's correct)
```

---

## 18. Troubleshooting

### PHP cannot write to storage/

```bash
# Check the SELinux context
ls -laZ /var/www/html/aviondash/storage/
# Should show: httpd_sys_rw_content_t on faults.json

# Fix
semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/aviondash/storage(/.*)?"
restorecon -Rv /var/www/html/aviondash/storage/

# Diagnose if still failing
ausearch -c httpd --raw | audit2why
```

### 502 Bad Gateway

```bash
# PHP-FPM not running or socket path mismatch
systemctl status php-fpm
ls -la /run/php-fpm/aviondash.sock

# Socket path in aviondash.conf must match listen= in aviondash-fpm.conf
grep "aviondash.sock" /etc/httpd/conf.d/aviondash.conf
grep "^listen" /etc/php-fpm.d/aviondash.conf
```

### Database Connection Failed

```bash
# Test PDO connection directly
php -r "new PDO('mysql:host=localhost;dbname=aviationdb;charset=utf8mb4', 'aviondash_app', 'Str0ngP@ssw0rd!'); echo 'OK';"

# Check MariaDB is running
systemctl status mariadb

# Check user grants
mariadb -u root -p -e "SHOW GRANTS FOR 'aviondash_app'@'localhost';"

# Check MariaDB is listening on localhost
mariadb -u root -p -e "SELECT @@hostname, @@port;"
```

### 403 Forbidden on All Pages

```bash
# Almost always a SELinux issue
ausearch -c httpd --raw | audit2why

# Common fix
restorecon -Rv /var/www/html/aviondash/

# Verify booleans are set
getsebool httpd_can_network_connect
getsebool httpd_can_network_connect_db
# Both should output: on
```

### mod_rewrite Not Working

```bash
# Verify AllowOverride All is in the virtual host
grep -i AllowOverride /etc/httpd/conf.d/aviondash.conf

# Check .htaccess syntax
apachectl -t

# Verify mod_rewrite is loaded
httpd -M | grep rewrite
```

### Datadog Agent Not Collecting

```bash
# Check Agent status
sudo datadog-agent status

# Test individual integration
sudo datadog-agent check mysql
sudo datadog-agent check apache

# Check Agent logs
journalctl -u datadog-agent -n 50 --no-pager

# Verify API key is correct
grep api_key /etc/datadog-agent/datadog.yaml
```

### PHP Slow Log Not Capturing Faults

```bash
# Verify slow log settings in PHP-FPM pool
grep -E "slowlog|slowlog_timeout" /etc/php-fpm.d/aviondash.conf

# Watch the slow log in real time
tail -f /var/log/aviondash/php_slow.log

# Trigger a slow request (arm the fault first via /chaos.php)
curl http://localhost/flights.php
# A slow log entry should appear within 5 seconds
```

### alert_cascade Growing Out of Control

```bash
# Step 1: Disarm the fault on /chaos.php first
# Step 2: Clean up injected rows
mariadb -u aviondash_app -p'Str0ngP@ssw0rd!' aviationdb \
  -e "CALL sp_ResetChaosAlerts();"

# Step 3: Verify cleanup
mariadb -u aviondash_app -p'Str0ngP@ssw0rd!' aviationdb \
  -e "SELECT COUNT(*) FROM system_alerts WHERE message LIKE 'FAULT INJECTED:%';"
# Expected: 0
```

---

## Datadog Monitor Quick Reference

The following monitors are recommended for this demo environment. See the companion Datadog setup guide for full creation instructions.

| Monitor | Type | Key Metric | Fault |
|---|---|---|---|
| Flights Page Latency > 3s | APM | p95 on GET /aviondash/flights.php | `slow_flights_query` |
| DB Query Duration Anomaly | Anomaly | avg:trace.mysql.query.duration | `slow_flights_query` |
| DB Query Count Spike | Metric | mysql.performance.queries rate | `n_plus_one_pilots` |
| SQL Cache Hit Ratio Drop | Metric | mysql.performance.qcache_hits | `missing_index_scan` |
| SQL Connection Count Rising | Change | mysql.net.connections | `connection_pool_exhaust` |
| Aircraft Page Error Rate | APM | error_rate on /aircraft.php | `page_500_aircraft` |
| PHP RuntimeException | Log | source:php "RuntimeException" | `page_500_aircraft` |
| Reports Page p90 > 5s | APM | p90 on GET /aviondash/reports.php | `slow_page_reports` |
| Host Memory Exhaustion | Metric | system.mem.pct_usable | `memory_leak_dashboard` |
| Passenger Count Anomaly | Anomaly | aviation.pax.today | `bad_data_passengers` |
| Host CPU Spike | Metric | system.cpu.user | `cpu_spike_query_runner` |
| PHP Warning Log Flood | Log | source:php status:warn | `log_flood` |
| 302 Redirect Rate to Login | Log | @http.status_code:302 /login.php | `auth_flap` |
| Login Synthetic Test | Synthetic | Browser: login → dashboard assert | `auth_flap` |
| Open Alert Count Growth | Change | aviation.alerts.open_count | `alert_cascade` |
| Health Check Flapping | Synthetic | HTTP GET /aviondash/health.php assert 200 | `health_check_flap` |
| RUM Page Load > 4s | RUM | p75:@view.loading_time | Multiple |
| RUM LCP Failure | RUM | p75:@view.largest_contentful_paint | Multiple |
| Custom Metrics Spike | Metric | datadog.estimated_usage.metrics.custom | `high_cardinality_tags` |

---

## Reset Between Demonstrations

```bash
# 1. Open /chaos.php → DISARM ALL → verify Active Faults = 0

# 2. Clean up alert_cascade rows
mariadb -u aviondash_app -p'Str0ngP@ssw0rd!' aviationdb \
  -e "CALL sp_ResetChaosAlerts();"

# 3. Remove health check flap counter
rm -f /tmp/aviondash_health_counter

# 4. Clear PHP error log (optional)
truncate -s 0 /var/log/aviondash/php_errors.log

# 5. Verify all faults are disarmed
cat /var/www/html/aviondash/storage/faults.json
# All values should be false

# 6. Wait 2-3 minutes for Datadog monitors to recover to OK state
```
