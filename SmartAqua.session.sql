-- =========================================
-- SmartAqua_clean.sql
-- ฐานข้อมูล Smart Aquaculture System
-- รัน 1 ครั้งเพื่อสร้างโครงสร้างทั้งหมด
-- =========================================

-- =========================================
-- CREATE DATABASE (ต้องมาก่อน DROP TABLE)
-- =========================================
CREATE DATABASE IF NOT EXISTS smartaqua
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smartaqua;

-- =========================================
-- RESET (ลบตารางเก่าถ้ามี)
-- =========================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS login_logs;
DROP TABLE IF EXISTS event_logs;
DROP TABLE IF EXISTS event_categories;
DROP TABLE IF EXISTS sensor_logs;
DROP TABLE IF EXISTS tank_settings;
DROP TABLE IF EXISTS tanks;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================
-- TABLE: users
-- เก็บข้อมูลผู้ใช้งานระบบ
-- =========================================
CREATE TABLE users (
    user_id          INT AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50)  NOT NULL UNIQUE,
    password_hash    VARCHAR(255) NOT NULL,
    display_name     VARCHAR(100) DEFAULT NULL,
    role             ENUM('admin','operator','viewer') DEFAULT 'operator',
    is_active        TINYINT(1)   DEFAULT 1,
    session_token    VARCHAR(64)  DEFAULT NULL,
    token_expires_at DATETIME     DEFAULT NULL,
    last_login       DATETIME     DEFAULT NULL,
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- =========================================
-- TABLE: login_logs
-- บันทึกประวัติ login สำเร็จ/ล้มเหลว
-- =========================================
CREATE TABLE login_logs (
    log_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         DEFAULT NULL,   -- NULL ได้ กรณี username ไม่พบในระบบ
    username   VARCHAR(50) NOT NULL,
    status     ENUM('success','failed') NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,   -- รองรับ IPv6
    created_at DATETIME    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- =========================================
-- TABLE: tanks
-- ข้อมูลพื้นฐานของตู้เพาะเลี้ยงสัตว์น้ำ
-- =========================================
CREATE TABLE tanks (
    tank_id    INT AUTO_INCREMENT PRIMARY KEY,
    tank_name  VARCHAR(100) NOT NULL,
    species    VARCHAR(100) DEFAULT NULL,
    status     ENUM('online','offline') DEFAULT 'online',
    device_id  VARCHAR(50)  DEFAULT NULL,  -- รหัส IoT เช่น ESP32-A1
    location   VARCHAR(100) DEFAULT NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- =========================================
-- TABLE: tank_settings
-- ค่าตั้งค่าของแต่ละตู้ (1:1 กับ tanks)
-- =========================================
CREATE TABLE tank_settings (
    setting_id        INT AUTO_INCREMENT PRIMARY KEY,
    tank_id           INT UNIQUE NOT NULL,
    target_temp       DECIMAL(5,2) DEFAULT NULL,   -- อุณหภูมิเป้าหมาย (°C)
    min_temp          DECIMAL(5,2) DEFAULT NULL,   -- ต่ำสุดที่ยอมรับ
    max_temp          DECIMAL(5,2) DEFAULT NULL,   -- สูงสุดที่ยอมรับ
    trigger_ph_high   DECIMAL(4,2) DEFAULT NULL,   -- pH สูงเกินนี้ → alert
    trigger_ph_low    DECIMAL(4,2) DEFAULT NULL,   -- pH ต่ำกว่านี้ → alert
    trigger_turbidity DECIMAL(6,2) DEFAULT NULL,   -- ความขุ่นเกินนี้ → alert (NTU)
    trigger_water_low DECIMAL(5,2) DEFAULT NULL,   -- ระดับน้ำต่ำกว่านี้ → alert (%)
    feeding_time      TIME         DEFAULT NULL,   -- เวลาให้อาหารหลัก HH:MM:SS
    feeding_amount    DECIMAL(6,2) DEFAULT NULL,   -- ปริมาณอาหารรวม (กรัม)
    auto_feed_status  ENUM('on','off') DEFAULT 'off',
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tank_id) REFERENCES tanks(tank_id) ON DELETE CASCADE
);

-- =========================================
-- TABLE: sensor_logs
-- บันทึกค่าเซนเซอร์ที่ IoT ส่งมาทุกรอบ
-- =========================================
CREATE TABLE sensor_logs (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    tank_id     INT NOT NULL,
    recorded_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    temperature DECIMAL(5,2) DEFAULT NULL,   -- อุณหภูมิน้ำ (°C)
    ph_level    DECIMAL(4,2) DEFAULT NULL,   -- ค่า pH
    turbidity   DECIMAL(6,2) DEFAULT NULL,   -- ความขุ่น (NTU)
    water_level DECIMAL(5,2) DEFAULT NULL,   -- ระดับน้ำ (%)
    INDEX idx_tank_recorded (tank_id, recorded_at),
    FOREIGN KEY (tank_id) REFERENCES tanks(tank_id) ON DELETE CASCADE
);

-- =========================================
-- TABLE: event_categories
-- ตาราง lookup ประเภท event
--
-- ทำไมแยกตารางนี้?
--   - ป้องกัน event_type สะกดผิด/ไม่สอดคล้อง
--   - เก็บ label ภาษาไทยและ severity กลางที่เดียว
--   - Frontend/Report JOIN มาแสดงชื่อไทยได้เลย
-- =========================================
CREATE TABLE event_categories (
    category_id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_code VARCHAR(30) NOT NULL UNIQUE,  -- รหัสที่ PHP ใช้
    label_th      VARCHAR(60) NOT NULL,          -- ชื่อภาษาไทยสำหรับ display
    severity      ENUM('info','warning','critical') NOT NULL DEFAULT 'info'
    -- info     = ปกติ บันทึกไว้อ้างอิง
    -- warning  = ควรตรวจสอบ (น้ำต่ำ, ความขุ่นสูง)
    -- critical = ต้องแก้ทันที (temp/pH ออกนอกขอบเขต)
);

-- =========================================
-- TABLE: event_logs
-- บันทึก event/alert ทุกประเภทแบบ structured
--
-- แนวคิดการออกแบบ:
--   แต่ละ event_type มี column เฉพาะของตัวเอง
--   column ที่ไม่เกี่ยวกับ event นั้นจะเป็น NULL
--
--   alert_temp_high  → sensor_value=31.5, threshold=30.0
--   feeding_done     → feed_mode='auto', feed_amount_g=250
--   filter_start     → pump_action='start'
--   settings_updated → changed_by=1
-- =========================================
CREATE TABLE event_logs (
    event_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tank_id       INT NOT NULL,
    category_id   TINYINT UNSIGNED NOT NULL,
    event_type    VARCHAR(50) NOT NULL,

    severity      ENUM('info','warning','critical') NOT NULL,
    -- คัดลอกมาจาก event_categories เพื่อ query filter เร็ว ไม่ต้อง JOIN

    -- ── Sensor alert ─────────────────────────────────────
    sensor_value  DECIMAL(7,2) DEFAULT NULL,
    -- ค่าเซนเซอร์จริงขณะเกิด alert
    -- ใช้กับ: alert_temp_high/low, alert_ph_high/low,
    --          alert_turbidity, alert_water_low

    threshold     DECIMAL(7,2) DEFAULT NULL,
    -- ค่า threshold ที่ตั้งไว้ขณะนั้น (บันทึกไว้ เพราะ settings อาจเปลี่ยนภายหลัง)

    -- ── Feeding ───────────────────────────────────────────
    feed_mode     ENUM('auto','manual') DEFAULT NULL,
    -- 'auto'   = ระบบให้อาหารตามตาราง
    -- 'manual' = ผู้ดูแลกดให้เองจากหน้าเว็บ
    -- ใช้กับ: feeding_done

    feed_amount_g DECIMAL(6,2) DEFAULT NULL,
    -- ปริมาณอาหารที่ให้จริง (กรัม)

    -- ── Filter/Pump ───────────────────────────────────────
    pump_action   ENUM('start','stop') DEFAULT NULL,
    -- 'start' = เปิดปั๊มกรองน้ำ
    -- 'stop'  = ปิดปั๊ม
    -- ใช้กับ: filter_start, filter_stop

    -- ── Settings audit ────────────────────────────────────
    changed_by    INT DEFAULT NULL,
    -- user_id ของผู้แก้ไข (NULL = ระบบเปลี่ยนเอง)
    -- ใช้กับ: settings_updated

    -- ── Display ───────────────────────────────────────────
    detail        TEXT DEFAULT NULL,
    -- ข้อความสรุปสำหรับแสดงบนหน้าเว็บ

    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_tank_time (tank_id, created_at),
    INDEX idx_category  (category_id),
    INDEX idx_severity  (severity),

    FOREIGN KEY (tank_id)     REFERENCES tanks(tank_id)              ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES event_categories(category_id),
    FOREIGN KEY (changed_by)  REFERENCES users(user_id)              ON DELETE SET NULL
);

-- =========================================
-- VIEW: v_dashboard
-- ข้อมูล dashboard รวมทุกตู้ (ค่าล่าสุด)
-- =========================================
CREATE OR REPLACE VIEW v_dashboard AS
SELECT
    t.tank_id,
    t.tank_name,
    t.species,
    t.location,
    t.status,
    s.temperature,
    s.ph_level,
    s.turbidity,
    s.water_level,
    s.recorded_at,
    ts.target_temp,
    ts.min_temp,
    ts.max_temp,
    ts.trigger_ph_high,
    ts.trigger_ph_low,
    ts.trigger_turbidity,
    ts.trigger_water_low,
    ts.auto_feed_status,
    ts.feeding_time,
    ts.feeding_amount
FROM tanks t
LEFT JOIN sensor_logs s
    ON s.log_id = (
        SELECT log_id FROM sensor_logs
        WHERE tank_id = t.tank_id
        ORDER BY recorded_at DESC
        LIMIT 1
    )
LEFT JOIN tank_settings ts ON ts.tank_id = t.tank_id;

-- =========================================
-- VIEW: v_event_log
-- event_logs รวม label ภาษาไทยและชื่อตู้
-- =========================================
CREATE OR REPLACE VIEW v_event_log AS
SELECT
    e.event_id,
    t.tank_name,
    ec.label_th       AS category_label,
    e.event_type,
    e.severity,
    e.sensor_value,
    e.threshold,
    e.feed_mode,
    e.feed_amount_g,
    e.pump_action,
    u.display_name    AS changed_by_name,
    e.detail,
    e.created_at
FROM event_logs e
JOIN  tanks           t  ON t.tank_id      = e.tank_id
JOIN  event_categories ec ON ec.category_id = e.category_id
LEFT JOIN users       u  ON u.user_id      = e.changed_by
ORDER BY e.created_at DESC;

-- =========================================
-- SEED: event_categories
-- =========================================
INSERT INTO event_categories (category_code, label_th, severity) VALUES
('alert_temp',     'แจ้งเตือนอุณหภูมิ',  'critical'),
('alert_ph',       'แจ้งเตือน pH',        'critical'),
('alert_turbidity','แจ้งเตือนความขุ่น',  'warning'),
('alert_water',    'แจ้งเตือนระดับน้ำ',  'warning'),
('feeding',        'บันทึกการให้อาหาร',  'info'),
('filter',         'ระบบกรองน้ำ',         'info'),
('settings',       'เปลี่ยนการตั้งค่า',  'info'),
('system',         'ระบบ',               'info'),
('hardware',       'อุปกรณ์ฮาร์ดแวร์',   'critical');

-- =========================================
-- SEED: users
-- password = 'admin123' (bcrypt cost=12)
-- =========================================
INSERT INTO users (username, password_hash, display_name, role, is_active) VALUES
(
    'admin',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'System Administrator',
    'admin',
    1
);
-- เปลี่ยนรหัสผ่าน:
-- php -r "echo password_hash('NEW_PASSWORD', PASSWORD_BCRYPT, ['cost'=>12]);"
-- UPDATE users SET password_hash='...' WHERE username='admin';

-- =========================================
-- SEED: tanks (5 ตู้ตัวอย่าง)
-- =========================================
INSERT INTO tanks (tank_id, tank_name, species, device_id, location) VALUES
(1, 'Tank A1 - Tilapia',     'Tilapia',    'ESP32-A1', 'Zone A'),
(2, 'Tank B2 - Dory',        'Dory',       'ESP32-B2', 'Zone B'),
(3, 'Tank C1 - Catfish',     'Catfish',    'ESP32-C1', 'Zone C'),
(4, 'Tank D3 - Clownfish',   'Clownfish',  'ESP32-D3', 'Zone D'),
(5, 'Tank E2 - Red Tilapia', 'Red Tilapia','ESP32-E2', 'Zone E');

-- =========================================
-- SEED: tank_settings
-- =========================================
INSERT INTO tank_settings (
    tank_id,
    target_temp, min_temp, max_temp,
    trigger_ph_high, trigger_ph_low, trigger_turbidity, trigger_water_low,
    feeding_time, feeding_amount, auto_feed_status
) VALUES
(1, 28.00, 24.00, 32.00, 8.5, 6.5, 50.00, 30.00, '08:00:00', 250.00, 'on'),
(2, 27.00, 23.00, 30.00, 8.0, 6.5, 40.00, 25.00, '07:30:00', 150.00, 'on'),
(3, 29.00, 24.00, 34.00, 8.5, 6.5, 60.00, 30.00, '08:30:00', 300.00, 'off'),
(4, 26.00, 22.00, 30.00, 8.0, 6.5, 35.00, 25.00, '07:00:00', 180.00, 'on'),
(5, 27.50, 23.00, 31.00, 8.3, 6.5, 45.00, 30.00, '08:00:00', 220.00, 'off');

-- =========================================
-- SEED: sensor_logs (1 แถว/ตู้)
-- =========================================
INSERT INTO sensor_logs (tank_id, temperature, ph_level, turbidity, water_level) VALUES
(1, 28.0, 7.2, 30.00, 80.00),
(2, 29.5, 7.5, 35.00, 75.00),
(3, 27.8, 7.1, 25.00, 85.00),
(4, 26.5, 7.3, 20.00, 90.00),
(5, 28.2, 7.4, 28.00, 88.00);

-- =========================================
-- SEED: event_logs (ครอบคลุมทุก category)
-- =========================================
-- ตู้ 1: ระบบปกติ
INSERT INTO event_logs (tank_id, category_id, event_type, severity, detail)
VALUES (1, 8, 'system_normal', 'info', 'ระบบทำงานปกติ ค่าเซนเซอร์อยู่ในเกณฑ์');

-- ตู้ 2: อุณหภูมิสูงเกิน
INSERT INTO event_logs (tank_id, category_id, event_type, severity, sensor_value, threshold, detail)
VALUES (2, 1, 'alert_temp_high', 'critical', 29.5, 30.0, 'อุณหภูมิสูงใกล้เกณฑ์: 29.5°C (สูงสุด 30°C)');

-- ตู้ 3: ให้อาหารอัตโนมัติ
INSERT INTO event_logs (tank_id, category_id, event_type, severity, feed_mode, feed_amount_g, detail)
VALUES (3, 5, 'feeding_done', 'info', 'auto', 300.00, 'ให้อาหารอัตโนมัติตามตาราง 300g');

-- ตู้ 3: ให้อาหาร manual เพิ่มเติม
INSERT INTO event_logs (tank_id, category_id, event_type, severity, feed_mode, feed_amount_g, changed_by, detail)
VALUES (3, 5, 'feeding_done', 'info', 'manual', 100.00, 1, 'admin ให้อาหารเพิ่มเติม 100g');

-- ตู้ 4: ระดับน้ำต่ำ
INSERT INTO event_logs (tank_id, category_id, event_type, severity, sensor_value, threshold, detail)
VALUES (4, 4, 'alert_water_low', 'warning', 22.5, 25.0, 'ระดับน้ำต่ำ: 22.5% (ต่ำสุด 25%)');

-- ตู้ 4: เปิดปั๊มกรองน้ำ
INSERT INTO event_logs (tank_id, category_id, event_type, severity, pump_action, detail)
VALUES (4, 6, 'filter_start', 'info', 'start', 'เปิดปั๊มกรองน้ำ เนื่องจากความขุ่นสูง');

-- ตู้ 5: pH สูงเกิน
INSERT INTO event_logs (tank_id, category_id, event_type, severity, sensor_value, threshold, detail)
VALUES (5, 2, 'alert_ph_high', 'critical', 8.6, 8.3, 'pH สูงเกิน: 8.6 (กำหนด 8.3)');

-- ตู้ 1: admin แก้ settings
INSERT INTO event_logs (tank_id, category_id, event_type, severity, changed_by, detail)
VALUES (1, 7, 'settings_updated', 'info', 1, 'admin แก้ไข: max_temp 32→34°C, auto_feed off→on');

-- ตู้ 3: ความขุ่นสูงเกิน (alert_turbidity — category_id = 3)
INSERT INTO event_logs (tank_id, category_id, event_type, severity, sensor_value, threshold, detail)
VALUES (3, 3, 'alert_turbidity_high', 'warning', 63.5, 60.0, 'ความขุ่นสูงเกิน: 63.5 NTU (กำหนด 60.0 NTU)');

-- =========================================
-- VERIFY
-- =========================================
SELECT 'tanks'            AS tbl, COUNT(*) AS row_count FROM tanks            UNION ALL
SELECT 'tank_settings'    AS tbl, COUNT(*) AS row_count FROM tank_settings    UNION ALL
SELECT 'sensor_logs'      AS tbl, COUNT(*) AS row_count FROM sensor_logs      UNION ALL
SELECT 'event_categories' AS tbl, COUNT(*) AS row_count FROM event_categories UNION ALL
SELECT 'event_logs'       AS tbl, COUNT(*) AS row_count FROM event_logs       UNION ALL
SELECT 'users'            AS tbl, COUNT(*) AS row_count FROM users;

-- แยก Auto vs Manual feeding
SELECT feed_mode, COUNT(*) AS cnt, SUM(feed_amount_g) AS total_g
FROM event_logs WHERE event_type = 'feeding_done' GROUP BY feed_mode;

-- severity breakdown
SELECT severity, COUNT(*) AS cnt FROM event_logs GROUP BY severity;

SELECT * FROM v_dashboard;
SELECT * FROM v_event_log;