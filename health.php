<?php
// ============================================================
// health.php — Application Health Check Endpoint
// Used by load balancers, uptime monitors, and Datadog Synthetics.
// The health_check_flap fault makes this alternate 200/503.
// ============================================================

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fault_inject.php';

$status  = 'ok';
$checks  = [];
$httpCode = 200;

// ── Database connectivity check ──────────────────────────────
try {
    $dbResult = DB::scalar('SELECT 1');
    $checks['database'] = 'ok';
} catch (RuntimeException $e) {
    $checks['database'] = 'error: ' . $e->getMessage();
    $status   = 'degraded';
    $httpCode = 503;
}

// ── Flight count sanity check ─────────────────────────────────
try {
    $flightCount = DB::scalar("SELECT COUNT(*) FROM flights WHERE DATE(scheduled_dep) = CURDATE()");
    $checks['flights_today'] = (int)$flightCount;
} catch (RuntimeException $e) {
    $checks['flights_today'] = 'error';
    $status   = 'degraded';
    $httpCode = 503;
}

// ── Fault: health_check_flap ──────────────────────────────────
// Check BEFORE setting headers so we can override the response code
FaultEngine::apply('health_check');

if (FaultEngine::isActive('health_check_flap')) {
    require_once __DIR__ . '/includes/additional_faults.php';
    if (AdditionalFaults::fault_health_flap()) {
        $status   = 'flapping';
        $httpCode = 503;
        $checks['fault'] = 'health_check_flap ARMED — alternating 200/503';
        error_log('[AvionDash FAULT] health_check_flap: returning 503 this request');
    }
}

// ── Response ──────────────────────────────────────────────────
http_response_code($httpCode);
header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode([
    'status'    => $status,
    'timestamp' => date('c'),
    'version'   => APP_VERSION,
    'env'       => APP_ENV,
    'checks'    => $checks,
], JSON_PRETTY_PRINT);
