-- ============================================================
-- AvionDash — Aviation Operations Monitor
-- MariaDB / MySQL Schema & Seed Data
-- Compatible with MariaDB 10.6+ and MySQL 8.0+
-- ============================================================

CREATE DATABASE IF NOT EXISTS aviationdb
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE aviationdb;

-- ============================================================
-- TABLES
-- ============================================================

DROP TABLE IF EXISTS system_alerts;
DROP TABLE IF EXISTS maintenance_logs;
DROP TABLE IF EXISTS flights;
DROP TABLE IF EXISTS pilots;
DROP TABLE IF EXISTS aircraft;
DROP TABLE IF EXISTS airports;
DROP TABLE IF EXISTS users;

-- Users (Application Login)
CREATE TABLE users (
    user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    role          ENUM('viewer','analyst','admin') NOT NULL DEFAULT 'viewer',
    last_login    DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT NOW(),
    is_active     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Airports
CREATE TABLE airports (
    airport_id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    icao_code    CHAR(4)      NOT NULL UNIQUE,
    iata_code    CHAR(3)      NOT NULL UNIQUE,
    name         VARCHAR(150) NOT NULL,
    city         VARCHAR(100) NOT NULL,
    country      VARCHAR(100) NOT NULL,
    latitude     DECIMAL(9,6) NOT NULL,
    longitude    DECIMAL(9,6) NOT NULL,
    elevation_ft INT          NOT NULL,
    timezone     VARCHAR(50)  NOT NULL,
    is_hub       TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aircraft
CREATE TABLE aircraft (
    aircraft_id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tail_number       VARCHAR(10)   NOT NULL UNIQUE,
    manufacturer      VARCHAR(50)   NOT NULL,
    model             VARCHAR(50)   NOT NULL,
    aircraft_type     ENUM('narrowbody','widebody','regional','cargo') NOT NULL,
    seat_capacity     INT           NOT NULL,
    max_range_nm      INT           NOT NULL,
    year_manufactured INT           NOT NULL,
    status            ENUM('active','maintenance','retired') NOT NULL DEFAULT 'active',
    base_airport_id   INT UNSIGNED  NOT NULL,
    total_hours       DECIMAL(10,1) NOT NULL DEFAULT 0,
    FOREIGN KEY (base_airport_id) REFERENCES airports(airport_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pilots
CREATE TABLE pilots (
    pilot_id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(10)  NOT NULL UNIQUE,
    first_name      VARCHAR(50)  NOT NULL,
    last_name       VARCHAR(50)  NOT NULL,
    license_number  VARCHAR(20)  NOT NULL UNIQUE,
    license_type    ENUM('ATP','CPL','PPL') NOT NULL,
    total_hours     DECIMAL(10,1) NOT NULL,
    base_airport_id INT UNSIGNED  NOT NULL,
    status          ENUM('active','leave','retired') NOT NULL DEFAULT 'active',
    hire_date       DATE         NOT NULL,
    FOREIGN KEY (base_airport_id) REFERENCES airports(airport_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Flights
CREATE TABLE flights (
    flight_id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    flight_number      VARCHAR(10)  NOT NULL,
    aircraft_id        INT UNSIGNED NOT NULL,
    captain_id         INT UNSIGNED NOT NULL,
    first_officer_id   INT UNSIGNED NULL,
    origin_airport_id  INT UNSIGNED NOT NULL,
    dest_airport_id    INT UNSIGNED NOT NULL,
    scheduled_dep      DATETIME     NOT NULL,
    actual_dep         DATETIME     NULL,
    scheduled_arr      DATETIME     NOT NULL,
    actual_arr         DATETIME     NULL,
    status             ENUM('scheduled','boarding','airborne','landed','cancelled','diverted') NOT NULL DEFAULT 'scheduled',
    delay_minutes      INT          NOT NULL DEFAULT 0,
    delay_reason       VARCHAR(100) NULL,
    passengers_boarded INT          NULL,
    fuel_used_lbs      DECIMAL(10,1) NULL,
    distance_nm        INT          NOT NULL,
    FOREIGN KEY (aircraft_id)      REFERENCES aircraft(aircraft_id),
    FOREIGN KEY (captain_id)       REFERENCES pilots(pilot_id),
    FOREIGN KEY (first_officer_id) REFERENCES pilots(pilot_id),
    FOREIGN KEY (origin_airport_id) REFERENCES airports(airport_id),
    FOREIGN KEY (dest_airport_id)   REFERENCES airports(airport_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maintenance Logs
CREATE TABLE maintenance_logs (
    log_id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    aircraft_id      INT UNSIGNED NOT NULL,
    maintenance_type ENUM('routine','unscheduled','inspection','overhaul') NOT NULL,
    description      TEXT         NOT NULL,
    technician       VARCHAR(100) NOT NULL,
    start_date       DATETIME     NOT NULL,
    end_date         DATETIME     NULL,
    status           ENUM('open','in_progress','completed') NOT NULL DEFAULT 'open',
    cost_usd         DECIMAL(12,2) NULL,
    discrepancy_code VARCHAR(20)  NULL,
    FOREIGN KEY (aircraft_id) REFERENCES aircraft(aircraft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System Alerts
CREATE TABLE system_alerts (
    alert_id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    alert_type  ENUM('delay','diversion','maintenance','weather','fuel') NOT NULL,
    severity    ENUM('low','medium','high','critical') NOT NULL,
    message     TEXT         NOT NULL,
    flight_id   INT UNSIGNED NULL,
    aircraft_id INT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT NOW(),
    resolved_at DATETIME     NULL,
    is_resolved TINYINT(1)   NOT NULL DEFAULT 0,
    FOREIGN KEY (flight_id)  REFERENCES flights(flight_id),
    FOREIGN KEY (aircraft_id) REFERENCES aircraft(aircraft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX idx_flights_status        ON flights(status);
CREATE INDEX idx_flights_scheduled_dep ON flights(scheduled_dep);
CREATE INDEX idx_flights_aircraft      ON flights(aircraft_id);
CREATE INDEX idx_flights_origin        ON flights(origin_airport_id);
CREATE INDEX idx_maintenance_aircraft  ON maintenance_logs(aircraft_id);
CREATE INDEX idx_maintenance_status    ON maintenance_logs(status);
CREATE INDEX idx_alerts_resolved       ON system_alerts(is_resolved);
CREATE INDEX idx_alerts_created        ON system_alerts(created_at);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default password for all accounts is: password
-- Hash generated with: php -r "echo password_hash('password', PASSWORD_BCRYPT);"
INSERT INTO users (username, password_hash, full_name, role) VALUES
('admin',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator',  'admin'),
('analyst', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane Analyst',   'analyst'),
('viewer',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Viewer',    'viewer');

-- Airports
INSERT INTO airports (icao_code, iata_code, name, city, country, latitude, longitude, elevation_ft, timezone, is_hub) VALUES
('KATL', 'ATL', 'Hartsfield-Jackson Atlanta International', 'Atlanta',     'USA', 33.636719,  -84.428067,  1026, 'America/New_York',    1),
('KLAX', 'LAX', 'Los Angeles International',               'Los Angeles', 'USA', 33.942791, -118.410042,   125, 'America/Los_Angeles', 1),
('KORD', 'ORD', "O'Hare International",                    'Chicago',     'USA', 41.978603,  -87.904842,   672, 'America/Chicago',     1),
('KDFW', 'DFW', 'Dallas/Fort Worth International',         'Dallas',      'USA', 32.896828,  -97.037997,   607, 'America/Chicago',     1),
('KJFK', 'JFK', 'John F. Kennedy International',           'New York',    'USA', 40.639751,  -73.778925,    13, 'America/New_York',    1),
('KMIA', 'MIA', 'Miami International',                     'Miami',       'USA', 25.795865,  -80.287046,     8, 'America/New_York',    0),
('KSEA', 'SEA', 'Seattle-Tacoma International',            'Seattle',     'USA', 47.449888, -122.311777,   433, 'America/Los_Angeles', 0),
('KDEN', 'DEN', 'Denver International',                    'Denver',      'USA', 39.856096, -104.673737,  5431, 'America/Denver',      1),
('KBOS', 'BOS', 'Logan International',                     'Boston',      'USA', 42.364347,  -71.005181,    19, 'America/New_York',    0),
('KLAS', 'LAS', 'Harry Reid International',                'Las Vegas',   'USA', 36.080056, -115.152222,  2141, 'America/Los_Angeles', 0),
('EGLL', 'LHR', 'London Heathrow',                         'London',      'GBR', 51.477500,   -0.461389,    83, 'Europe/London',       1),
('LFPG', 'CDG', 'Charles de Gaulle',                       'Paris',       'FRA', 49.009724,    2.547778,   392, 'Europe/Paris',        1);

-- Aircraft
INSERT INTO aircraft (tail_number, manufacturer, model, aircraft_type, seat_capacity, max_range_nm, year_manufactured, status, base_airport_id, total_hours) VALUES
('N101AV', 'Boeing',    '737-800',   'narrowbody', 162, 2935, 2018, 'active',      1,  12450.5),
('N202AV', 'Boeing',    '737-900ER', 'narrowbody', 180, 3200, 2019, 'active',      1,   9820.0),
('N303AV', 'Airbus',    'A320neo',   'narrowbody', 165, 3400, 2020, 'active',      2,   8730.3),
('N404AV', 'Airbus',    'A321XLR',   'narrowbody', 220, 4700, 2022, 'active',      3,   4120.7),
('N505AV', 'Boeing',    '787-9',     'widebody',   296, 7635, 2017, 'active',      4,  18900.2),
('N606AV', 'Boeing',    '777-300ER', 'widebody',   396, 7930, 2015, 'active',      5,  24300.8),
('N707AV', 'Airbus',    'A350-900',  'widebody',   325, 8100, 2021, 'active',      1,   6540.1),
('N808AV', 'Embraer',   'E175',      'regional',    76, 2200, 2019, 'active',      6,   7200.4),
('N909AV', 'Bombardier','CRJ-900',   'regional',    90, 1550, 2016, 'active',      7,  11100.9),
('N010AV', 'Boeing',    '737-800',   'narrowbody', 162, 2935, 2014, 'maintenance', 8,  31200.0),
('N011AV', 'Boeing',    '767-300F',  'cargo',        0, 5765, 2012, 'active',      1,  38400.5),
('N012AV', 'Airbus',    'A330-300',  'widebody',   293, 5880, 2016, 'active',      5,  20100.3);

-- Pilots
INSERT INTO pilots (employee_id, first_name, last_name, license_number, license_type, total_hours, base_airport_id, status, hire_date) VALUES
('EMP001', 'James',    'Hartwell',  'ATP-204851', 'ATP', 12400.5, 1, 'active',  '2010-03-15'),
('EMP002', 'Sarah',    'Okonkwo',   'ATP-319072', 'ATP',  9200.0, 1, 'active',  '2013-07-22'),
('EMP003', 'Michael',  'Tran',      'ATP-445621', 'ATP',  7800.3, 2, 'active',  '2015-01-10'),
('EMP004', 'Linda',    'Vasquez',   'ATP-502843', 'ATP',  6100.7, 2, 'active',  '2016-09-30'),
('EMP005', 'Robert',   'Elsworth',  'ATP-618974', 'ATP', 15200.2, 3, 'active',  '2008-05-20'),
('EMP006', 'Amanda',   'Chen',      'ATP-724135', 'ATP', 11000.8, 4, 'active',  '2011-11-14'),
('EMP007', 'David',    'Nkemdirim', 'CPL-801256', 'CPL',  2400.1, 5, 'active',  '2020-06-01'),
('EMP008', 'Patricia', 'Mueller',   'ATP-934782', 'ATP',  8900.4, 6, 'active',  '2014-02-28'),
('EMP009', 'Carlos',   'Reyes',     'CPL-052947', 'CPL',  1800.9, 7, 'active',  '2021-08-15'),
('EMP010', 'Nancy',    'Brightwell','ATP-178320', 'ATP', 19500.0, 8, 'leave',   '2005-04-03');

-- Flights (relative to NOW() so data is always current)
INSERT INTO flights (flight_number, aircraft_id, captain_id, first_officer_id, origin_airport_id, dest_airport_id, scheduled_dep, actual_dep, scheduled_arr, actual_arr, status, delay_minutes, delay_reason, passengers_boarded, fuel_used_lbs, distance_nm) VALUES
('AV101', 1,  1, 2,    1, 2,  DATE_SUB(NOW(), INTERVAL 8 HOUR),  DATE_SUB(NOW(), INTERVAL 8 HOUR),  DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 3 HOUR), 'landed',    0,  NULL,                       155, 42300.0, 1946),
('AV102', 3,  3, 4,    2, 1,  DATE_SUB(NOW(), INTERVAL 6 HOUR),  DATE_SUB(NOW(), INTERVAL 335 MINUTE), DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL 30 MINUTE), 'landed', 25, 'Late crew arrival',       160, 44100.0, 1946),
('AV201', 5,  5, 6,    4, 11, DATE_SUB(NOW(), INTERVAL 10 HOUR), DATE_SUB(NOW(), INTERVAL 10 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR),  'landed',    0,  NULL,                       280, 98400.0, 4739),
('AV301', 7,  1, 7,    1, 5,  DATE_SUB(NOW(), INTERVAL 3 HOUR),  DATE_SUB(NOW(), INTERVAL 3 HOUR),  DATE_ADD(NOW(), INTERVAL 0 HOUR),  NULL, 'airborne',  0,  NULL,                       318, NULL,    1190),
('AV302', 6,  6, NULL, 5, 12, DATE_SUB(NOW(), INTERVAL 2 HOUR),  DATE_SUB(NOW(), INTERVAL 80 MINUTE), DATE_ADD(NOW(), INTERVAL 5 HOUR), NULL, 'airborne',  40, 'ATC ground delay',        380, NULL,    3640),
('AV401', 2,  2, 9,    7, 3,  DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_ADD(NOW(), INTERVAL 2 HOUR), NULL, 'airborne', 0,  NULL,                      170, NULL,    1720),
('AV501', 8,  8, NULL, 6, 1,  DATE_ADD(NOW(), INTERVAL 30 MINUTE), NULL,                            DATE_ADD(NOW(), INTERVAL 3 HOUR),  NULL, 'boarding',  0,  NULL,                       NULL, NULL,    660),
('AV502', 4,  4, 7,    3, 8,  DATE_ADD(NOW(), INTERVAL 1 HOUR),  NULL,                              DATE_ADD(NOW(), INTERVAL 5 HOUR),  NULL, 'scheduled', 0,  NULL,                       NULL, NULL,    4700),
('AV601', 1,  3, 9,    2, 5,  DATE_ADD(NOW(), INTERVAL 2 HOUR),  NULL,                              DATE_ADD(NOW(), INTERVAL 7 HOUR),  NULL, 'scheduled', 0,  NULL,                       NULL, NULL,    2475),
('AV602', 9,  8, NULL, 1, 6,  DATE_ADD(NOW(), INTERVAL 3 HOUR),  NULL,                              DATE_ADD(NOW(), INTERVAL 5 HOUR),  NULL, 'scheduled', 0,  NULL,                       NULL, NULL,    660),
('AV701', 11, 5, NULL, 1, 11, DATE_ADD(NOW(), INTERVAL 4 HOUR),  NULL,                              DATE_ADD(NOW(), INTERVAL 12 HOUR), NULL, 'scheduled', 0,  NULL,                       NULL, NULL,    4651),
('AV801', 12, 6, 4,    5, 12, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 16 HOUR), DATE_SUB(NOW(), INTERVAL 16 HOUR), 'landed', 0, NULL,                285, 91200.0, 3640),
('AV902', 3,  2, NULL, 1, 10, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 1435 MINUTE), DATE_SUB(NOW(), INTERVAL 19 HOUR), DATE_SUB(NOW(), INTERVAL 19 HOUR), 'landed', 15, 'Baggage delay', 150, 38700.0, 1745),
('AV210', 5,  1, 2,    11, 4, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 37 HOUR), DATE_SUB(NOW(), INTERVAL 37 HOUR), 'landed',  0, NULL,                271, 96800.0, 4739),
('AV099', 10, 5, NULL, 8, 1,  DATE_SUB(NOW(), INTERVAL 3 DAY),   NULL,                              DATE_SUB(NOW(), INTERVAL 69 HOUR), NULL, 'cancelled', 0, 'Aircraft AOG - maintenance', 0, NULL, 1398);

-- Maintenance Logs
INSERT INTO maintenance_logs (aircraft_id, maintenance_type, description, technician, start_date, end_date, status, cost_usd, discrepancy_code) VALUES
(10, 'unscheduled', 'Engine 1 oil leak detected post-flight inspection. Seal replacement required.',             'Tony Marek',    DATE_SUB(NOW(), INTERVAL 3 DAY), NULL,                              'in_progress', NULL,        'ENG-2241'),
(2,  'routine',     'A-Check: 600hr inspection, oil change, tire rotation, cabin pressurization test.',         'Maria Santos',  DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY),   'completed',   18400.00,    NULL),
(6,  'inspection',  'Annual airworthiness inspection per FAR 91.409.',                                           'Carlos Fuentes',DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY),   'completed',   32000.00,    NULL),
(7,  'routine',     '300hr interval check: hydraulic fluid levels, avionics calibration, fuel system check.',   'Tom Reilly',    DATE_SUB(NOW(), INTERVAL 1 DAY), NULL,                              'in_progress', NULL,        NULL),
(1,  'routine',     'C-Check: Full structural inspection, interior refurbishment, APU overhaul.',               'Lisa Park',     DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY), 'completed',   1250000.00,  NULL),
(9,  'unscheduled', 'Landing gear indication fault. Sensor replaced and systems tested.',                        'Steve Ward',    DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY),   'completed',   4200.00,     'LDG-0882'),
(11, 'routine',     'Cargo door seal inspection and cargo compartment liner replacement.',                       'Amy Zhou',      DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY),  'completed',   9600.00,     NULL),
(3,  'overhaul',    'Engine 2 hot section overhaul per manufacturer schedule at 20,000 EFH.',                   'Derek Olsen',   DATE_SUB(NOW(), INTERVAL 90 DAY), DATE_SUB(NOW(), INTERVAL 60 DAY), 'completed',   2800000.00,  NULL);

-- System Alerts
INSERT INTO system_alerts (alert_type, severity, message, flight_id, aircraft_id, created_at, is_resolved) VALUES
('maintenance', 'critical', 'Aircraft N010AV is AOG — Engine oil seal failure. Flight AV099 cancelled.',       15, 10, DATE_SUB(NOW(), INTERVAL 3 DAY),    0),
('delay',       'medium',   'Flight AV102 delayed 25 minutes due to late crew arrival at LAX.',                2,  NULL, DATE_SUB(NOW(), INTERVAL 6 HOUR),  1),
('delay',       'high',     'Flight AV302 delayed 40 minutes due to ATC ground delay at JFK.',                 5,  NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR),  0),
('fuel',        'low',      'N606AV fuel uplift exceeded plan by 800 lbs. Dispatch notified.',                  5,  6,   DATE_SUB(NOW(), INTERVAL 2 HOUR),  0),
('weather',     'medium',   'SIGMET active for North Atlantic track. Reroute filed for AV701.',                 11, NULL, DATE_SUB(NOW(), INTERVAL 1 HOUR),  0),
('maintenance', 'high',     'N707AV hydraulic system inspection overdue by 2 days.',                           NULL, 7,  DATE_SUB(NOW(), INTERVAL 1 DAY),   0),
('diversion',   'critical', 'AV302 monitoring for possible diversion to BOS due to destination weather.',      5,  6,   DATE_SUB(NOW(), INTERVAL 30 MINUTE),0),
('delay',       'low',      'AV101 pushed back on time. All systems nominal.',                                  1,  NULL, DATE_SUB(NOW(), INTERVAL 8 HOUR),  1);

-- ============================================================
-- STORED PROCEDURES / ROUTINES
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_FlightSummaryByDate$$
CREATE PROCEDURE sp_FlightSummaryByDate(IN p_start DATE, IN p_end DATE)
BEGIN
    SELECT
        DATE(scheduled_dep)                                         AS flight_date,
        COUNT(*)                                                    AS total_flights,
        SUM(status = 'landed')                                      AS completed,
        SUM(status = 'cancelled')                                   AS cancelled,
        SUM(delay_minutes > 0)                                      AS delayed_count,
        ROUND(AVG(delay_minutes), 1)                                AS avg_delay_minutes,
        SUM(passengers_boarded)                                     AS total_passengers
    FROM flights
    WHERE DATE(scheduled_dep) BETWEEN p_start AND p_end
    GROUP BY DATE(scheduled_dep)
    ORDER BY flight_date DESC;
END$$

DROP PROCEDURE IF EXISTS sp_AircraftUtilization$$
CREATE PROCEDURE sp_AircraftUtilization()
BEGIN
    SELECT
        a.tail_number,
        CONCAT(a.manufacturer, ' ', a.model)   AS aircraft,
        a.aircraft_type,
        a.status,
        ap.iata_code                            AS base,
        COUNT(f.flight_id)                      AS flights_last_30d,
        IFNULL(SUM(f.distance_nm), 0)           AS nm_last_30d,
        ROUND(IFNULL(AVG(f.delay_minutes), 0), 1) AS avg_delay,
        a.total_hours
    FROM aircraft a
    JOIN airports ap ON ap.airport_id = a.base_airport_id
    LEFT JOIN flights f ON f.aircraft_id = a.aircraft_id
        AND f.scheduled_dep >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY a.aircraft_id, a.tail_number, a.manufacturer, a.model,
             a.aircraft_type, a.status, ap.iata_code, a.total_hours
    ORDER BY flights_last_30d DESC;
END$$

DROP PROCEDURE IF EXISTS sp_TopDelayReasons$$
CREATE PROCEDURE sp_TopDelayReasons()
BEGIN
    SELECT
        delay_reason,
        COUNT(*)            AS occurrences,
        AVG(delay_minutes)  AS avg_delay_min,
        MAX(delay_minutes)  AS max_delay_min,
        SUM(delay_minutes)  AS total_delay_min
    FROM flights
    WHERE delay_reason IS NOT NULL AND delay_minutes > 0
    GROUP BY delay_reason
    ORDER BY occurrences DESC;
END$$

DROP PROCEDURE IF EXISTS sp_ResetChaosAlerts$$
CREATE PROCEDURE sp_ResetChaosAlerts()
BEGIN
    DELETE FROM system_alerts
    WHERE message LIKE 'FAULT INJECTED:%';
    SELECT ROW_COUNT() AS rows_deleted;
END$$

DELIMITER ;

-- ============================================================
-- APPLICATION USER (least-privilege)
-- Run as root: mysql -u root -p < schema_mariadb.sql
-- ============================================================
-- CREATE USER IF NOT EXISTS 'aviondash_app'@'localhost' IDENTIFIED BY 'Str0ngP@ssw0rd!';
-- GRANT SELECT, INSERT, UPDATE ON aviationdb.system_alerts TO 'aviondash_app'@'localhost';
-- GRANT SELECT ON aviationdb.users TO 'aviondash_app'@'localhost';
-- GRANT SELECT ON aviationdb.airports TO 'aviondash_app'@'localhost';
-- GRANT SELECT ON aviationdb.aircraft TO 'aviondash_app'@'localhost';
-- GRANT SELECT ON aviationdb.pilots TO 'aviondash_app'@'localhost';
-- GRANT SELECT ON aviationdb.flights TO 'aviondash_app'@'localhost';
-- GRANT SELECT ON aviationdb.maintenance_logs TO 'aviondash_app'@'localhost';
-- GRANT UPDATE (last_login) ON aviationdb.users TO 'aviondash_app'@'localhost';
-- GRANT EXECUTE ON PROCEDURE aviationdb.sp_FlightSummaryByDate TO 'aviondash_app'@'localhost';
-- GRANT EXECUTE ON PROCEDURE aviationdb.sp_AircraftUtilization TO 'aviondash_app'@'localhost';
-- GRANT EXECUTE ON PROCEDURE aviationdb.sp_TopDelayReasons TO 'aviondash_app'@'localhost';
-- GRANT EXECUTE ON PROCEDURE aviationdb.sp_ResetChaosAlerts TO 'aviondash_app'@'localhost';
-- FLUSH PRIVILEGES;
