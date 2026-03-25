-- ============================================================
-- AvionDash — Database Seed Data
-- MariaDB / MySQL
--
-- Run this AFTER schema_mariadb.sql to load or refresh demo data.
-- Safe to re-run: clears existing rows then re-inserts.
-- Does NOT drop or recreate tables.
--
--   mariadb -u root -p aviationdb < seed.sql
-- ============================================================

USE aviationdb;

-- ── Disable foreign key checks so we can truncate in any order ──
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE system_alerts;
TRUNCATE TABLE maintenance_logs;
TRUNCATE TABLE flights;
TRUNCATE TABLE pilots;
TRUNCATE TABLE aircraft;
TRUNCATE TABLE airports;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- USERS
-- All passwords are: password
-- Hash: password_hash('password', PASSWORD_BCRYPT)
-- ============================================================
INSERT INTO users (username, password_hash, full_name, role) VALUES
('admin',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator',   'admin'),
('analyst', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane Analyst',    'analyst'),
('viewer',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Viewer',     'viewer');

-- ============================================================
-- AIRPORTS
-- ============================================================
INSERT INTO airports (icao_code, iata_code, name, city, country, latitude, longitude, elevation_ft, timezone, is_hub) VALUES
('KATL', 'ATL', 'Hartsfield-Jackson Atlanta International', 'Atlanta',     'USA',  33.636719,  -84.428067,  1026, 'America/New_York',    1),
('KLAX', 'LAX', 'Los Angeles International',               'Los Angeles', 'USA',  33.942791, -118.410042,   125, 'America/Los_Angeles', 1),
('KORD', 'ORD', "O'Hare International",                    'Chicago',     'USA',  41.978603,  -87.904842,   672, 'America/Chicago',     1),
('KDFW', 'DFW', 'Dallas/Fort Worth International',         'Dallas',      'USA',  32.896828,  -97.037997,   607, 'America/Chicago',     1),
('KJFK', 'JFK', 'John F. Kennedy International',           'New York',    'USA',  40.639751,  -73.778925,    13, 'America/New_York',    1),
('KMIA', 'MIA', 'Miami International',                     'Miami',       'USA',  25.795865,  -80.287046,     8, 'America/New_York',    0),
('KSEA', 'SEA', 'Seattle-Tacoma International',            'Seattle',     'USA',  47.449888, -122.311777,   433, 'America/Los_Angeles', 0),
('KDEN', 'DEN', 'Denver International',                    'Denver',      'USA',  39.856096, -104.673737,  5431, 'America/Denver',      1),
('KBOS', 'BOS', 'Logan International',                     'Boston',      'USA',  42.364347,  -71.005181,    19, 'America/New_York',    0),
('KLAS', 'LAS', 'Harry Reid International',                'Las Vegas',   'USA',  36.080056, -115.152222,  2141, 'America/Los_Angeles', 0),
('EGLL', 'LHR', 'London Heathrow',                         'London',      'GBR',  51.477500,   -0.461389,    83, 'Europe/London',       1),
('LFPG', 'CDG', 'Charles de Gaulle',                       'Paris',       'FRA',  49.009724,    2.547778,   392, 'Europe/Paris',        1);

-- ============================================================
-- AIRCRAFT  (base_airport_id references airports by insert order 1-12)
-- ============================================================
INSERT INTO aircraft (tail_number, manufacturer, model, aircraft_type, seat_capacity, max_range_nm, year_manufactured, status, base_airport_id, total_hours) VALUES
('N101AV', 'Boeing',     '737-800',    'narrowbody',  162, 2935, 2018, 'active',      1,  12450.5),
('N202AV', 'Boeing',     '737-900ER',  'narrowbody',  180, 3200, 2019, 'active',      1,   9820.0),
('N303AV', 'Airbus',     'A320neo',    'narrowbody',  165, 3400, 2020, 'active',      2,   8730.3),
('N404AV', 'Airbus',     'A321XLR',    'narrowbody',  220, 4700, 2022, 'active',      3,   4120.7),
('N505AV', 'Boeing',     '787-9',      'widebody',    296, 7635, 2017, 'active',      4,  18900.2),
('N606AV', 'Boeing',     '777-300ER',  'widebody',    396, 7930, 2015, 'active',      5,  24300.8),
('N707AV', 'Airbus',     'A350-900',   'widebody',    325, 8100, 2021, 'active',      1,   6540.1),
('N808AV', 'Embraer',    'E175',       'regional',     76, 2200, 2019, 'active',      6,   7200.4),
('N909AV', 'Bombardier', 'CRJ-900',    'regional',     90, 1550, 2016, 'active',      7,  11100.9),
('N010AV', 'Boeing',     '737-800',    'narrowbody',  162, 2935, 2014, 'maintenance', 8,  31200.0),
('N011AV', 'Boeing',     '767-300F',   'cargo',          0, 5765, 2012, 'active',     1,  38400.5),
('N012AV', 'Airbus',     'A330-300',   'widebody',    293, 5880, 2016, 'active',      5,  20100.3);

-- ============================================================
-- PILOTS
-- ============================================================
INSERT INTO pilots (employee_id, first_name, last_name, license_number, license_type, total_hours, base_airport_id, status, hire_date) VALUES
('EMP001', 'James',    'Hartwell',   'ATP-204851', 'ATP', 12400.5, 1, 'active',  '2010-03-15'),
('EMP002', 'Sarah',    'Okonkwo',    'ATP-319072', 'ATP',  9200.0, 1, 'active',  '2013-07-22'),
('EMP003', 'Michael',  'Tran',       'ATP-445621', 'ATP',  7800.3, 2, 'active',  '2015-01-10'),
('EMP004', 'Linda',    'Vasquez',    'ATP-502843', 'ATP',  6100.7, 2, 'active',  '2016-09-30'),
('EMP005', 'Robert',   'Elsworth',   'ATP-618974', 'ATP', 15200.2, 3, 'active',  '2008-05-20'),
('EMP006', 'Amanda',   'Chen',       'ATP-724135', 'ATP', 11000.8, 4, 'active',  '2011-11-14'),
('EMP007', 'David',    'Nkemdirim',  'CPL-801256', 'CPL',  2400.1, 5, 'active',  '2020-06-01'),
('EMP008', 'Patricia', 'Mueller',    'ATP-934782', 'ATP',  8900.4, 6, 'active',  '2014-02-28'),
('EMP009', 'Carlos',   'Reyes',      'CPL-052947', 'CPL',  1800.9, 7, 'active',  '2021-08-15'),
('EMP010', 'Nancy',    'Brightwell', 'ATP-178320', 'ATP', 19500.0, 8, 'leave',   '2005-04-03');

-- ============================================================
-- FLIGHTS
-- Relative to NOW() so data is always current
-- ============================================================
INSERT INTO flights (
    flight_number, aircraft_id, captain_id, first_officer_id,
    origin_airport_id, dest_airport_id,
    scheduled_dep, actual_dep, scheduled_arr, actual_arr,
    status, delay_minutes, delay_reason,
    passengers_boarded, fuel_used_lbs, distance_nm
) VALUES
-- Completed flights (past)
('AV101', 1,  1, 2,    1, 2,
    DATE_SUB(NOW(), INTERVAL 8 HOUR),  DATE_SUB(NOW(), INTERVAL 8 HOUR),
    DATE_SUB(NOW(), INTERVAL 3 HOUR),  DATE_SUB(NOW(), INTERVAL 3 HOUR),
    'landed', 0, NULL, 155, 42300.0, 1946),

('AV102', 3,  3, 4,    2, 1,
    DATE_SUB(NOW(), INTERVAL 6 HOUR),  DATE_SUB(NOW(), INTERVAL 335 MINUTE),
    DATE_SUB(NOW(), INTERVAL 1 HOUR),  DATE_SUB(NOW(), INTERVAL 30 MINUTE),
    'landed', 25, 'Late crew arrival', 160, 44100.0, 1946),

('AV201', 5,  5, 6,    4, 11,
    DATE_SUB(NOW(), INTERVAL 10 HOUR), DATE_SUB(NOW(), INTERVAL 10 HOUR),
    DATE_SUB(NOW(), INTERVAL 1 HOUR),  DATE_SUB(NOW(), INTERVAL 1 HOUR),
    'landed', 0, NULL, 280, 98400.0, 4739),

-- Currently airborne
('AV301', 7,  1, 7,    1, 5,
    DATE_SUB(NOW(), INTERVAL 3 HOUR),  DATE_SUB(NOW(), INTERVAL 3 HOUR),
    DATE_ADD(NOW(), INTERVAL 30 MINUTE), NULL,
    'airborne', 0, NULL, 318, NULL, 1190),

('AV302', 6,  6, NULL, 5, 12,
    DATE_SUB(NOW(), INTERVAL 2 HOUR),  DATE_SUB(NOW(), INTERVAL 80 MINUTE),
    DATE_ADD(NOW(), INTERVAL 5 HOUR),  NULL,
    'airborne', 40, 'ATC ground delay', 380, NULL, 3640),

('AV401', 2,  2, 9,    7, 3,
    DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_SUB(NOW(), INTERVAL 30 MINUTE),
    DATE_ADD(NOW(), INTERVAL 2 HOUR),  NULL,
    'airborne', 0, NULL, 170, NULL, 1720),

-- Boarding / scheduled
('AV501', 8,  8, NULL, 6, 1,
    DATE_ADD(NOW(), INTERVAL 30 MINUTE), NULL,
    DATE_ADD(NOW(), INTERVAL 3 HOUR),  NULL,
    'boarding', 0, NULL, NULL, NULL, 660),

('AV502', 4,  4, 7,    3, 8,
    DATE_ADD(NOW(), INTERVAL 1 HOUR),  NULL,
    DATE_ADD(NOW(), INTERVAL 5 HOUR),  NULL,
    'scheduled', 0, NULL, NULL, NULL, 4700),

('AV601', 1,  3, 9,    2, 5,
    DATE_ADD(NOW(), INTERVAL 2 HOUR),  NULL,
    DATE_ADD(NOW(), INTERVAL 7 HOUR),  NULL,
    'scheduled', 0, NULL, NULL, NULL, 2475),

('AV602', 9,  8, NULL, 1, 6,
    DATE_ADD(NOW(), INTERVAL 3 HOUR),  NULL,
    DATE_ADD(NOW(), INTERVAL 5 HOUR),  NULL,
    'scheduled', 0, NULL, NULL, NULL, 660),

('AV701', 11, 5, NULL, 1, 11,
    DATE_ADD(NOW(), INTERVAL 4 HOUR),  NULL,
    DATE_ADD(NOW(), INTERVAL 12 HOUR), NULL,
    'scheduled', 0, NULL, NULL, NULL, 4651),

-- Yesterday's completed flights
('AV801', 12, 6, 4,    5, 12,
    DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 1 DAY),
    DATE_SUB(NOW(), INTERVAL 16 HOUR), DATE_SUB(NOW(), INTERVAL 16 HOUR),
    'landed', 0, NULL, 285, 91200.0, 3640),

('AV902', 3,  2, NULL, 1, 10,
    DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 1435 MINUTE),
    DATE_SUB(NOW(), INTERVAL 19 HOUR), DATE_SUB(NOW(), INTERVAL 19 HOUR),
    'landed', 15, 'Baggage delay', 150, 38700.0, 1745),

-- Two days ago
('AV210', 5,  1, 2,    11, 4,
    DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 2 DAY),
    DATE_SUB(NOW(), INTERVAL 37 HOUR), DATE_SUB(NOW(), INTERVAL 37 HOUR),
    'landed', 0, NULL, 271, 96800.0, 4739),

-- Cancelled (aircraft AOG)
('AV099', 10, 5, NULL, 8, 1,
    DATE_SUB(NOW(), INTERVAL 3 DAY),   NULL,
    DATE_SUB(NOW(), INTERVAL 69 HOUR), NULL,
    'cancelled', 0, 'Aircraft AOG - maintenance', 0, NULL, 1398);

-- ============================================================
-- MAINTENANCE LOGS
-- ============================================================
INSERT INTO maintenance_logs (aircraft_id, maintenance_type, description, technician, start_date, end_date, status, cost_usd, discrepancy_code) VALUES
(10, 'unscheduled',
    'Engine 1 oil leak detected post-flight inspection. Seal replacement required.',
    'Tony Marek',     DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, 'in_progress', NULL, 'ENG-2241'),

(2,  'routine',
    'A-Check: 600hr inspection, oil change, tire rotation, cabin pressurization test.',
    'Maria Santos',   DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), 'completed', 18400.00, NULL),

(6,  'inspection',
    'Annual airworthiness inspection per FAR 91.409.',
    'Carlos Fuentes', DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), 'completed', 32000.00, NULL),

(7,  'routine',
    '300hr interval check: hydraulic fluid levels, avionics calibration, fuel system check.',
    'Tom Reilly',     DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, 'in_progress', NULL, NULL),

(1,  'routine',
    'C-Check: Full structural inspection, interior refurbishment, APU overhaul.',
    'Lisa Park',      DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY), 'completed', 1250000.00, NULL),

(9,  'unscheduled',
    'Landing gear indication fault. Sensor replaced and systems tested.',
    'Steve Ward',     DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 'completed', 4200.00, 'LDG-0882'),

(11, 'routine',
    'Cargo door seal inspection and cargo compartment liner replacement.',
    'Amy Zhou',       DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), 'completed', 9600.00, NULL),

(3,  'overhaul',
    'Engine 2 hot section overhaul per manufacturer schedule at 20,000 EFH.',
    'Derek Olsen',    DATE_SUB(NOW(), INTERVAL 90 DAY), DATE_SUB(NOW(), INTERVAL 60 DAY), 'completed', 2800000.00, NULL);

-- ============================================================
-- SYSTEM ALERTS
-- ============================================================
INSERT INTO system_alerts (alert_type, severity, message, flight_id, aircraft_id, created_at, is_resolved) VALUES
('maintenance', 'critical',
    'Aircraft N010AV is AOG — Engine oil seal failure. Flight AV099 cancelled.',
    15, 10, DATE_SUB(NOW(), INTERVAL 3 DAY), 0),

('delay', 'medium',
    'Flight AV102 delayed 25 minutes due to late crew arrival at LAX.',
    2, NULL, DATE_SUB(NOW(), INTERVAL 6 HOUR), 1),

('delay', 'high',
    'Flight AV302 delayed 40 minutes due to ATC ground delay at JFK.',
    5, NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR), 0),

('fuel', 'low',
    'N606AV fuel uplift exceeded plan by 800 lbs. Dispatch notified.',
    5, 6, DATE_SUB(NOW(), INTERVAL 2 HOUR), 0),

('weather', 'medium',
    'SIGMET active for North Atlantic track. Reroute filed for AV701.',
    11, NULL, DATE_SUB(NOW(), INTERVAL 1 HOUR), 0),

('maintenance', 'high',
    'N707AV hydraulic system inspection overdue by 2 days.',
    NULL, 7, DATE_SUB(NOW(), INTERVAL 1 DAY), 0),

('diversion', 'critical',
    'AV302 monitoring for possible diversion to BOS due to destination weather.',
    5, 6, DATE_SUB(NOW(), INTERVAL 30 MINUTE), 0),

('delay', 'low',
    'AV101 pushed back on time. All systems nominal.',
    1, NULL, DATE_SUB(NOW(), INTERVAL 8 HOUR), 1);

-- ============================================================
-- VERIFY ROW COUNTS
-- ============================================================
SELECT 'users'             AS tbl, COUNT(*) AS row_count FROM users
UNION ALL
SELECT 'airports',                 COUNT(*) FROM airports
UNION ALL
SELECT 'aircraft',                 COUNT(*) FROM aircraft
UNION ALL
SELECT 'pilots',                   COUNT(*) FROM pilots
UNION ALL
SELECT 'flights',                  COUNT(*) FROM flights
UNION ALL
SELECT 'maintenance_logs',         COUNT(*) FROM maintenance_logs
UNION ALL
SELECT 'system_alerts',            COUNT(*) FROM system_alerts;
