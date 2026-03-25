<?php
// ============================================================
// config.php — AvionDash Configuration (Linux / MariaDB)
// ============================================================

define('APP_NAME',    'AvionDash');
define('APP_VERSION', '1.0.0');
define('APP_ENV',     'demo');

// ---- Deployment Base Path ----------------------------------------
// Set this to the URL sub-path where AvionDash is deployed.
// Examples:
//   '/aviondash'          -> http://server/aviondash/
//   '/tools/aviondash'    -> http://server/tools/aviondash/
//   ''                    -> http://server/  (root deployment)
// No trailing slash. Must match the Alias directive in Apache config.
define('APP_BASE', '/aviondash');

// ---- MariaDB Connection (PDO) ----
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'aviationdb');
define('DB_USER',    'aviondash_app');
define('DB_PASS',    'Str0ngP@ssw0rd!');   // Change in production
define('DB_CHARSET', 'utf8mb4');

// ---- Session Security ----
define('SESSION_NAME',    'aviondash_session');
define('SESSION_TIMEOUT', 1800);  // 30 minutes

// ---- Query Runner ----
define('ALLOW_WRITE_QUERIES', false);
