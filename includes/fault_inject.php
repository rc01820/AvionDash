<?php
// ============================================================
// fault_inject.php — AvionDash Chaos / Fault Injection Engine
// MariaDB / Linux port
// SELECT SLEEP() replaces WAITFOR DELAY
// IGNORE INDEX replaces WITH(INDEX(0))
// PDO replaces sqlsrv
// ============================================================

class FaultEngine {

    public static array $catalogue = [

        // ── DATABASE FAULTS ────────────────────────────────────
        'slow_flights_query' => [
            'id'          => 'slow_flights_query',
            'label'       => 'Slow Flights Query',
            'category'    => 'database',
            'severity'    => 'high',
            'description' => 'Injects a SELECT SLEEP(4) into every flight list query on the Flights page, causing a 4-second DB stall.',
            'effect'      => 'Page response time spikes from ~80ms to >4s. Datadog APM trace shows slow sqlsrv span.',
            'detects'     => 'Datadog APM slow span, DB Monitoring slow query, Apache access log high time_taken.',
            'hook'        => 'pre_flights_query',
            'icon'        => '🐢',
        ],

        'n_plus_one_pilots' => [
            'id'          => 'n_plus_one_pilots',
            'label'       => 'N+1 Pilot Lookups',
            'category'    => 'database',
            'severity'    => 'medium',
            'description' => 'Dashboard replaces a single JOIN with a separate SELECT per flight row for pilot name lookups.',
            'effect'      => '15 flights = 16 queries. DB query count metric spikes dramatically.',
            'detects'     => 'Datadog APM trace burst of identical queries. DB Monitoring query volume anomaly.',
            'hook'        => 'post_airborne_query',
            'icon'        => '🔁',
        ],

        'missing_index_scan' => [
            'id'          => 'missing_index_scan',
            'label'       => 'Full Table Scan (No Index)',
            'category'    => 'database',
            'severity'    => 'medium',
            'description' => 'Appends IGNORE INDEX (idx_flights_status) to bypass the status index on the flights table, forcing a full scan.',
            'effect'      => 'MariaDB reads all rows instead of using the index. High Handler_read_rnd_next metric.',
            'detects'     => 'Datadog DB Monitoring shows full scan in execution plan. EXPLAIN shows type=ALL.',
            'hook'        => 'pre_flights_query',
            'icon'        => '🔍',
        ],

        'connection_pool_exhaust' => [
            'id'          => 'connection_pool_exhaust',
            'label'       => 'Connection Pool Leak',
            'category'    => 'database',
            'severity'    => 'critical',
            'description' => 'Opens 12 extra PDO connections on every Reports page load without closing them.',
            'effect'      => 'MariaDB Threads_connected climbs rapidly. Eventually new connections refused.',
            'detects'     => 'Datadog mysql.net.connections gauge rises. Connection refused errors in PHP log.',
            'hook'        => 'pre_reports_render',
            'icon'        => '🚰',
        ],

        // ── WEB / HTTP FAULTS ──────────────────────────────────
        'page_500_aircraft' => [
            'id'          => 'page_500_aircraft',
            'label'       => 'Aircraft Page 500 Error',
            'category'    => 'web',
            'severity'    => 'critical',
            'description' => 'Throws an unhandled RuntimeException on the Aircraft Status page, producing HTTP 500.',
            'effect'      => 'Aircraft page returns 500. Apache access log records 500 status.',
            'detects'     => 'Datadog APM error rate spike. Log Monitor on PHP error log. Apache access log alert.',
            'hook'        => 'pre_aircraft_render',
            'icon'        => '💥',
        ],

        'slow_page_reports' => [
            'id'          => 'slow_page_reports',
            'label'       => 'Slow Reports Page',
            'category'    => 'web',
            'severity'    => 'high',
            'description' => 'Adds an 8-second sleep() in PHP before the Reports page renders.',
            'effect'      => 'Reports page TTFB exceeds 8 seconds. Apache %T field shows 8000+ ms.',
            'detects'     => 'Datadog Synthetic test breach. APM trace shows 8s span. Apache log latency anomaly.',
            'hook'        => 'pre_reports_render',
            'icon'        => '⏳',
        ],

        // ── APPLICATION FAULTS ─────────────────────────────────
        'memory_leak_dashboard' => [
            'id'          => 'memory_leak_dashboard',
            'label'       => 'Memory Leak (Dashboard)',
            'category'    => 'application',
            'severity'    => 'high',
            'description' => 'Allocates ~64 MB of PHP arrays on every Dashboard load without freeing them.',
            'effect'      => 'PHP-FPM worker memory climbs per request. PHP fatal if memory_limit hit.',
            'detects'     => 'Datadog system.mem.used rises. PHP error log: Allowed memory exhausted.',
            'hook'        => 'pre_dashboard_render',
            'icon'        => '🧠',
        ],

        'bad_data_passengers' => [
            'id'          => 'bad_data_passengers',
            'label'       => 'Incorrect Passenger Counts',
            'category'    => 'application',
            'severity'    => 'medium',
            'description' => 'Multiplies all passengers_boarded values by 847 after the DB query returns.',
            'effect'      => 'Dashboard PAX TODAY shows millions instead of ~1,000. DB is clean; app output is wrong.',
            'detects'     => 'Datadog custom metric aviation.pax.today spikes 800×. Business anomaly monitor fires.',
            'hook'        => 'post_flights_data',
            'icon'        => '📊',
        ],

        'cpu_spike_query_runner' => [
            'id'          => 'cpu_spike_query_runner',
            'label'       => 'CPU Spike (Query Runner)',
            'category'    => 'application',
            'severity'    => 'high',
            'description' => 'Runs a tight 3-second CPU-bound math loop before processing each Query Runner page request.',
            'effect'      => 'PHP-FPM worker CPU hits 100% for 3 seconds per request.',
            'detects'     => 'Datadog system.cpu.user spike. APM trace shows large CPU-bound span.',
            'hook'        => 'pre_query_runner_render',
            'icon'        => '🔥',
        ],

        // ── OBSERVABILITY FAULTS ───────────────────────────────
        'log_flood' => [
            'id'          => 'log_flood',
            'label'       => 'Log Flood',
            'category'    => 'observability',
            'severity'    => 'medium',
            'description' => 'Writes 75 WARNING-level entries to the PHP error log on every authenticated page request.',
            'effect'      => 'PHP error log grows ~10 KB per request. Log ingestion volume in Datadog spikes.',
            'detects'     => 'Datadog Log Management volume anomaly. Log monitor on WARNING count threshold.',
            'hook'        => 'global_always',
            'icon'        => '📜',
        ],

        'auth_flap' => [
            'id'          => 'auth_flap',
            'label'       => 'Session Flap (Auth Failures)',
            'category'    => 'observability',
            'severity'    => 'high',
            'description' => 'Randomly invalidates the user session on 40% of page loads, forcing redirect to /login.php.',
            'effect'      => 'Users randomly logged out. Apache logs show repeated 302s to /login.php.',
            'detects'     => 'Datadog Synthetic login test fails intermittently. Apache 302 redirect rate spike.',
            'hook'        => 'global_auth_check',
            'icon'        => '🔐',
        ],

        'alert_cascade' => [
            'id'          => 'alert_cascade',
            'label'       => 'Alert Storm',
            'category'    => 'observability',
            'severity'    => 'critical',
            'description' => 'Inserts 20 new critical system_alerts rows into MariaDB every time the Alerts page loads.',
            'effect'      => 'Open alert count grows unbounded. Custom metric aviation.alerts.open_count spikes.',
            'detects'     => 'Datadog custom metric change alert fires. Monitor on alert count threshold breaches.',
            'hook'        => 'pre_alerts_render',
            'icon'        => '🚨',
        ],
    ];

    // ── State management ───────────────────────────────────────
    private static ?array $state = null;
    private static string $stateFile = '';

    public static function init(): void {
        self::$stateFile = dirname(__DIR__) . '/storage/faults.json';
        self::$state = self::loadState();
    }

    private static function loadState(): array {
        if (!file_exists(self::$stateFile)) {
            return array_fill_keys(array_keys(self::$catalogue), false);
        }
        $raw   = file_get_contents(self::$stateFile);
        $state = json_decode($raw, true) ?? [];
        foreach (self::$catalogue as $id => $_) {
            if (!isset($state[$id])) $state[$id] = false;
        }
        return $state;
    }

    public static function saveState(array $state): bool {
        $result = file_put_contents(
            self::$stateFile,
            json_encode($state, JSON_PRETTY_PRINT),
            LOCK_EX
        );
        if ($result === false) {
            error_log('[AvionDash] FAULT STATE SAVE FAILED — cannot write to: ' . self::$stateFile
                . ' — check file ownership and SELinux context (httpd_sys_rw_content_t)');
        }
        return $result !== false;
    }

    public static function isActive(string $id): bool {
        if (self::$state === null) self::init();
        return (bool)(self::$state[$id] ?? false);
    }

    public static function enable(string $id): void {
        if (self::$state === null) self::init();
        self::$state[$id] = true;
        self::saveState(self::$state);
    }

    public static function disable(string $id): void {
        if (self::$state === null) self::init();
        self::$state[$id] = false;
        self::saveState(self::$state);
    }

    public static function toggle(string $id): bool {
        if (self::$state === null) self::init();
        $new = !self::isActive($id);
        self::$state[$id] = $new;
        self::saveState(self::$state);
        return $new;
    }

    public static function activeCount(): int {
        if (self::$state === null) self::init();
        return count(array_filter(self::$state));
    }

    public static function allState(): array {
        if (self::$state === null) self::init();
        return self::$state;
    }

    // ── Hook dispatcher ────────────────────────────────────────
    public static function apply(string $hook, mixed &$data = null): void {
        if (self::$state === null) self::init();

        switch ($hook) {
            case 'global_always':
                if (self::isActive('log_flood')) self::fault_log_flood();
                break;

            case 'global_auth_check':
                if (self::isActive('auth_flap')) self::fault_auth_flap();
                break;

            case 'pre_dashboard_render':
                if (self::isActive('memory_leak_dashboard')) self::fault_memory_leak();
                break;

            case 'pre_flights_query':
                // Modify the SQL string in-place
                if (self::isActive('slow_flights_query') && is_string($data)) {
                    $data = self::fault_slow_query($data);
                }
                if (self::isActive('missing_index_scan') && is_string($data)) {
                    $data = self::fault_missing_index($data);
                }
                break;

            case 'post_flights_data':
                if (self::isActive('bad_data_passengers') && is_array($data)) {
                    $data = self::fault_bad_data($data);
                }
                break;

            case 'post_airborne_query':
                if (self::isActive('n_plus_one_pilots') && is_array($data)) {
                    $data = self::fault_n_plus_one($data);
                }
                if (self::isActive('bad_data_passengers') && is_array($data)) {
                    $data = self::fault_bad_data($data);
                }
                break;

            case 'pre_aircraft_render':
                if (self::isActive('page_500_aircraft')) self::fault_page_500();
                break;

            case 'pre_reports_render':
                if (self::isActive('slow_page_reports'))      self::fault_slow_page(8);
                if (self::isActive('connection_pool_exhaust')) self::fault_connection_leak();
                break;

            case 'pre_alerts_render':
                if (self::isActive('alert_cascade')) self::fault_alert_cascade();
                break;

            case 'pre_query_runner_render':
                if (self::isActive('cpu_spike_query_runner')) self::fault_cpu_spike(3);
                break;
        }
    }

    // ── Fault implementations ──────────────────────────────────

    /** MariaDB: prepend SELECT SLEEP(4) before the main query */
    private static function fault_slow_query(string $sql): string {
        // Wrap in a subquery that evaluates SLEEP first
        // The cleanest approach on MariaDB is to add AND SLEEP(4)=0 to WHERE
        if (preg_match('/WHERE\s/i', $sql)) {
            return preg_replace('/WHERE\s/i', 'WHERE SLEEP(4)=0 AND ', $sql, 1);
        }
        // If no WHERE clause, append one
        return $sql . ' AND SLEEP(4)=0';
    }

    /** MariaDB: use IGNORE INDEX to force full table scan */
    private static function fault_missing_index(string $sql): string {
        // Replace `FROM flights f` with `FROM flights f IGNORE INDEX (idx_flights_status)`
        return str_replace(
            'FROM flights f',
            'FROM flights f IGNORE INDEX (idx_flights_status)',
            $sql
        );
    }

    /** Replace JOIN-based pilot lookup with N individual queries */
    private static function fault_n_plus_one(array $rows): array {
        foreach ($rows as &$row) {
            $flightId = $row['flight_id'] ?? null;
            if ($flightId) {
                try {
                    $result = DB::query(
                        'SELECT CONCAT(p.first_name, " ", p.last_name) AS captain
                         FROM flights f
                         JOIN pilots p ON p.pilot_id = f.captain_id
                         WHERE f.flight_id = ?',
                        [$flightId]
                    );
                    if (!empty($result)) {
                        $row['captain'] = $result[0]['captain'];
                    }
                } catch (RuntimeException $e) { /* swallow */ }
            }
        }
        unset($row);
        return $rows;
    }

    /** Open extra PDO connections and never close them */
    private static function fault_connection_leak(): void {
        static $leaked = [];
        $dsn  = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        for ($i = 0; $i < 12; $i++) {
            try {
                $leaked[] = new PDO($dsn, DB_USER, DB_PASS, $opts);
            } catch (PDOException $e) { /* pool exhausted — that's the point */ }
        }
        error_log('[AvionDash FAULT] connection_pool_exhaust: 12 connections opened and leaked');
    }

    /** Throw unhandled exception → HTTP 500 */
    private static function fault_page_500(): void {
        error_log('[AvionDash FAULT] page_500_aircraft: injecting unhandled exception');
        throw new RuntimeException(
            'FAULT INJECTED: Unhandled exception in aircraft data layer. ' .
            'FleetRepository::hydrateAircraftStatus() returned NULL for fleet_id=0.'
        );
    }

    /** Sleep N seconds before rendering */
    private static function fault_slow_page(int $seconds): void {
        error_log("[AvionDash FAULT] slow_page_reports: sleeping {$seconds}s");
        sleep($seconds);
    }

    /** Allocate large PHP arrays to drive up memory usage */
    private static function fault_memory_leak(): void {
        static $leaked = [];
        for ($i = 0; $i < 64; $i++) {
            $leaked[] = str_repeat('X', 1024 * 1024); // 1 MB each
        }
        error_log('[AvionDash FAULT] memory_leak_dashboard: ~64MB allocated, total=' .
                  round(memory_get_usage(true) / 1048576, 1) . 'MB');
    }

    /** Corrupt passenger counts in result set */
    private static function fault_bad_data(array $rows): array {
        foreach ($rows as &$row) {
            if (isset($row['passengers_boarded']) && is_numeric($row['passengers_boarded'])) {
                $row['passengers_boarded'] = (int)$row['passengers_boarded'] * 847;
            }
            if (isset($row['pax_today']) && is_numeric($row['pax_today'])) {
                $row['pax_today'] = (int)$row['pax_today'] * 847;
            }
        }
        unset($row);
        error_log('[AvionDash FAULT] bad_data_passengers: passenger counts corrupted ×847');
        return $rows;
    }

    /** CPU-bound loop for N seconds */
    private static function fault_cpu_spike(int $seconds): void {
        error_log("[AvionDash FAULT] cpu_spike_query_runner: burning CPU for {$seconds}s");
        $end = microtime(true) + $seconds;
        $acc = 0.0;
        while (microtime(true) < $end) {
            for ($i = 1; $i < 50000; $i++) {
                $acc += sqrt($i) * log($i) * sin($i);
            }
        }
        error_log("[AvionDash FAULT] cpu_spike done");
    }

    /** Write 75 WARNING log lines per request */
    private static function fault_log_flood(): void {
        $messages = [
            'FlightDataService: cache miss on segment, falling back to DB',
            'PilotRosterCache: stale entry detected, invalidating',
            'AlertManager: polling cycle completed, 0 new alerts',
            'SessionStore: GC triggered, swept 0 expired sessions',
            'MetricsCollector: heartbeat emitted',
            'AircraftTracker: position delta received',
            'FuelCalcEngine: burn estimate within tolerance',
            'WeatherService: SIGMET check completed',
            'NotificationBus: no subscribers for GATE_CHANGE',
            'DBConnectionPool: health check passed',
        ];
        for ($i = 0; $i < 75; $i++) {
            error_log('[AvionDash WARNING][' . date('H:i:s') . "][flood-{$i}] " . $messages[$i % count($messages)]);
        }
    }

    /** Randomly invalidate session (40% of loads) */
    private static function fault_auth_flap(): void {
        if (mt_rand(1, 10) <= 4) {
            $user = $_SESSION['username'] ?? 'unknown';
            error_log("[AvionDash FAULT] auth_flap: invalidating session for '{$user}'");
            session_unset();
            session_destroy();
            header('Location: ' . (defined('APP_BASE') ? APP_BASE : '/demo') . '/login.php?reason=session_error');
            exit;
        }
    }

    /** Insert 20 synthetic critical alerts into MariaDB */
    private static function fault_alert_cascade(): void {
        $types    = ['maintenance', 'weather', 'fuel', 'delay', 'diversion'];
        $messages = [
            'FAULT INJECTED: Engine oil pressure out of limits on N%03dAV. Immediate inspection required.',
            'FAULT INJECTED: SIGMET CHARLIE active — diversion probability HIGH for transatlantic routes.',
            'FAULT INJECTED: Fuel contamination suspected at gate B%02d. Ground stop in effect.',
            'FAULT INJECTED: ATC system outage at KATL TRACON. Holding pattern in effect.',
            'FAULT INJECTED: Runway incursion alert — operations halted pending investigation.',
        ];
        try {
            for ($i = 0; $i < 20; $i++) {
                DB::query(
                    "INSERT INTO system_alerts (alert_type, severity, message, created_at, is_resolved)
                     VALUES (?, 'critical', ?, NOW(), 0)",
                    [$types[$i % 5], sprintf($messages[$i % 5], $i + 1)]
                );
            }
            error_log('[AvionDash FAULT] alert_cascade: inserted 20 critical alerts');
        } catch (RuntimeException $e) {
            error_log('[AvionDash FAULT] alert_cascade failed: ' . $e->getMessage());
        }
    }
}

FaultEngine::init();
