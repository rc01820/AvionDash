<?php
// ============================================================
// ADDITIONAL FAULT SCENARIOS — Patch for fault_inject.php
// Add these entries to $catalogue and apply() in both
// the Windows (sqlsrv) and Linux (PDO/MariaDB) versions.
// ============================================================

// ── NEW CATALOGUE ENTRIES ─────────────────────────────────
// Paste these into the $catalogue array in fault_inject.php

/*
'large_result_no_limit' => [
    'id'          => 'large_result_no_limit',
    'label'       => 'Large Result Set (No LIMIT)',
    'category'    => 'database',
    'severity'    => 'high',
    'description' => 'Removes the implicit LIMIT on the flights query, returning every historical flight row (and growing with each alert_cascade). Simulates a missing pagination clause.',
    'effect'      => 'Query returns 100s-1000s of rows. PHP memory spikes. JSON/HTML response body balloons. Network I/O increases.',
    'detects'     => 'Datadog DB Monitoring: rows_sent metric spikes. APM span shows large response size. RUM Large-XHR or slow render.',
    'hook'        => 'pre_reports_query',
    'icon'        => '📦',
],

'disk_io_spike' => [
    'id'          => 'disk_io_spike',
    'label'       => 'Disk I/O Spike',
    'category'    => 'application',
    'severity'    => 'medium',
    'description' => 'Writes and reads a 50 MB temporary file on every Dashboard load, simulating uncontrolled disk usage from a logging or export feature.',
    'effect'      => 'system.disk.read_time and system.disk.write_time metrics spike. Page load slows by 1-2 seconds depending on disk speed.',
    'detects'     => 'Datadog infrastructure disk I/O metrics spike. system.io.util rises. APM span shows file I/O latency.',
    'hook'        => 'pre_dashboard_render',
    'icon'        => '💿',
],

'session_lock_contention' => [
    'id'          => 'session_lock_contention',
    'label'       => 'Session Lock Contention',
    'category'    => 'application',
    'severity'    => 'high',
    'description' => 'Holds the PHP session file lock open for 3 seconds before releasing it, blocking any concurrent requests from the same browser session.',
    'effect'      => 'Concurrent page loads from the same user queue behind each other. TTFB on parallel requests stacks to 3s * N requests.',
    'detects'     => 'Datadog APM shows queued spans waiting. RUM shows stacked long task events. Apache request queue depth rises.',
    'hook'        => 'global_auth_check',
    'icon'        => '🔒',
],

'health_check_flap' => [
    'id'          => 'health_check_flap',
    'label'       => 'Health Check Flap',
    'category'    => 'observability',
    'severity'    => 'high',
    'description' => 'Makes the /health.php endpoint alternate between HTTP 200 and HTTP 503 on every other request, simulating an unstable service recovering loop.',
    'effect'      => 'Load balancer or uptime checks see intermittent failures. Datadog Synthetic alternates pass/fail. On-call alert flaps.',
    'detects'     => 'Datadog Synthetic flapping alert. HTTP check monitor shows intermittent 503. Uptime SLO burn rate spikes.',
    'hook'        => 'health_check',
    'icon'        => '🔄',
],

'exception_silencer' => [
    'id'          => 'exception_silencer',
    'label'       => 'Exception Silencer',
    'category'    => 'observability',
    'severity'    => 'medium',
    'description' => 'Wraps all DB queries in silent try/catch blocks that swallow exceptions and return empty results, making errors invisible to users and APM alike.',
    'effect'      => 'DB errors produce empty tables instead of 500 pages. APM error rate stays at 0. Silent data loss — hardest fault to detect.',
    'detects'     => 'Datadog custom metric: aviation.flights.airborne drops to 0 unexpectedly. Business anomaly monitor fires. Synthetic test fails on content assertion.',
    'hook'        => 'pre_dashboard_render',
    'icon'        => '🤫',
],

'slow_third_party' => [
    'id'          => 'slow_third_party',
    'label'       => 'Slow External API Call',
    'category'    => 'web',
    'severity'    => 'high',
    'description' => 'Simulates a slow weather/NOTAM API call on every Reports page load using curl to a non-responsive endpoint with a 5-second timeout.',
    'effect'      => 'Reports page blocked for 5 seconds waiting for external dependency. Dependency failure is invisible in DB monitoring — only APM shows the span.',
    'detects'     => 'Datadog APM shows curl/http-client span consuming 5s. External service monitor fires. Distinguished from slow DB by span type.',
    'hook'        => 'pre_reports_render',
    'icon'        => '🌐',
],

'timezone_corruption' => [
    'id'          => 'timezone_corruption',
    'label'       => 'Timestamp Corruption',
    'category'    => 'application',
    'severity'    => 'medium',
    'description' => 'Shifts all datetime fields in query results back by 24 hours before rendering. DB and logs are clean; only the application output is wrong.',
    'effect'      => 'All flight scheduled times appear one day in the past. Flights show as landed that have not departed yet. Data looks corrupted but DB is fine.',
    'detects'     => 'Datadog cannot detect this directly. Demonstrates the value of RUM + Synthetic content assertions and business metric monitors over infrastructure alerts.',
    'hook'        => 'post_flights_data',
    'icon'        => '⏰',
],

'high_cardinality_tags' => [
    'id'          => 'high_cardinality_tags',
    'label'       => 'Metric Tag Cardinality Bomb',
    'category'    => 'observability',
    'severity'    => 'high',
    'description' => 'Emits a custom StatsD metric with a unique UUID tag on every page load, creating thousands of distinct metric series and flooding the Datadog custom metrics quota.',
    'effect'      => 'Datadog custom metrics count climbs rapidly. Metric cardinality limit warnings appear. Datadog billing impact metric anomaly fires.',
    'detects'     => 'Datadog estimated_usage.metrics.custom spikes. Cardinality management UI shows new high-cardinality source. Usage alert fires.',
    'hook'        => 'global_always',
    'icon'        => '📈',
],
*/

// ── IMPLEMENTATION METHODS ─────────────────────────────────
// Add these static methods to the FaultEngine class in fault_inject.php

class AdditionalFaults {

    /** Remove LIMIT clause from a SQL query */
    public static function fault_large_result(string $sql): string {
        // Remove existing LIMIT clause
        $sql = preg_replace('/\s+LIMIT\s+\d+(\s*,\s*\d+)?\s*$/i', '', $sql);
        return $sql;
    }

    /** Write and read a 50 MB temp file to drive disk I/O */
    public static function fault_disk_io(): void {
        $tmpFile = sys_get_temp_dir() . '/aviondash_disk_fault_' . getmypid();
        $chunk   = str_repeat(random_bytes(1024), 1024);   // 1 MB chunk
        $fh      = fopen($tmpFile, 'wb');
        if ($fh) {
            for ($i = 0; $i < 50; $i++) {                 // 50 MB total
                fwrite($fh, $chunk);
            }
            fclose($fh);
            // Read it back to flush the disk cache
            $fh = fopen($tmpFile, 'rb');
            if ($fh) {
                while (!feof($fh)) fread($fh, 65536);
                fclose($fh);
            }
            unlink($tmpFile);
        }
        error_log('[AvionDash FAULT] disk_io_spike: wrote and read 50MB temp file');
    }

    /** Hold session lock for 3 seconds — blocks concurrent same-session requests */
    public static function fault_session_lock(): void {
        // Session is already open at this point.
        // Sleep INSIDE the session open window to hold the file lock.
        error_log('[AvionDash FAULT] session_lock_contention: holding session lock 3s');
        sleep(3);
        // Session will be closed normally after this returns, releasing the lock.
    }

    /**
     * Health check flap — write a flip-flop counter to disk.
     * Returns true if this request should serve 503.
     */
    public static function fault_health_flap(): bool {
        $counterFile = sys_get_temp_dir() . '/aviondash_health_counter';
        $count = (int)@file_get_contents($counterFile);
        file_put_contents($counterFile, $count + 1, LOCK_EX);
        return ($count % 2 === 0); // Alternate 200 / 503
    }

    /** Wrap all subsequent DB queries in silent catch — returns empty arrays */
    public static function fault_exception_silencer(): void {
        // Monkey-patch: set a global flag; DB::query() checks it
        $GLOBALS['__aviondash_silence_exceptions'] = true;
        error_log('[AvionDash FAULT] exception_silencer: DB exceptions will be swallowed silently');
    }

    /** Simulate a slow external HTTP dependency using curl with a low-RTT host */
    public static function fault_slow_third_party(): void {
        error_log('[AvionDash FAULT] slow_third_party: simulating 5s external API call');
        // Use a non-routable IP (RFC 5737 TEST-NET-1) with a short timeout
        // The connection attempt will block until timeout
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'http://192.0.2.1/weather-api/notams',
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL       => 1,
        ]);
        @curl_exec($ch);
        curl_close($ch);
        error_log('[AvionDash FAULT] slow_third_party: external call timed out after 5s');
    }

    /** Shift all datetime fields in a result array back 24 hours */
    public static function fault_timestamp_corrupt(array &$rows): void {
        $dateKeys = ['scheduled_dep','actual_dep','scheduled_arr','actual_arr','created_at','start_date','end_date'];
        foreach ($rows as &$row) {
            foreach ($dateKeys as $key) {
                if (!empty($row[$key]) && is_string($row[$key])) {
                    try {
                        $dt = new DateTime($row[$key]);
                        $dt->modify('-24 hours');
                        $row[$key] = $dt->format('Y-m-d H:i:s');
                    } catch (Exception $e) { /* skip */ }
                }
            }
        }
        unset($row);
        error_log('[AvionDash FAULT] timezone_corruption: all timestamps shifted -24h');
    }

    /**
     * Emit a high-cardinality StatsD metric via UDP to Datadog Agent.
     * Each call uses a unique UUID as a tag value, creating a new metric series.
     */
    public static function fault_cardinality_bomb(): void {
        $uuid   = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

        $metric = "aviondash.page.request:1|c|#env:demo,service:aviondash,request_id:{$uuid}";

        // Send via UDP to the Datadog StatsD listener (port 8125)
        $sock = @fsockopen('udp://127.0.0.1', 8125, $errno, $errstr, 0.1);
        if ($sock) {
            fwrite($sock, $metric);
            fclose($sock);
        }
        error_log("[AvionDash FAULT] high_cardinality_tags: emitted metric with request_id={$uuid}");
    }
}
