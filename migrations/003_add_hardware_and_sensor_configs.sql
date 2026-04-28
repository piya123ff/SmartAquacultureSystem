-- =========================================
-- 003_add_hardware_and_sensor_configs.sql
-- เพิ่ม sensor_configs + devices ให้ระบบรองรับ hardware health
-- =========================================

CREATE TABLE IF NOT EXISTS sensor_configs (
    config_id           INT AUTO_INCREMENT PRIMARY KEY,
    tank_id             INT NOT NULL,
    sensor_type         ENUM('temperature','ph','turbidity','water_level') NOT NULL,
    is_enabled          TINYINT(1) NOT NULL DEFAULT 1,
    status              ENUM('normal','error','offline') NOT NULL DEFAULT 'normal',
    read_interval_sec   INT NOT NULL DEFAULT 5,
    calibration_offset  DECIMAL(8,2) NOT NULL DEFAULT 0,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tank_sensor (tank_id, sensor_type),
    CONSTRAINT fk_sensor_configs_tank
      FOREIGN KEY (tank_id) REFERENCES tanks(tank_id)
      ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS devices (
    device_id   INT AUTO_INCREMENT PRIMARY KEY,
    tank_id     INT NOT NULL,
    device_type ENUM('pump','feeder','cctv') NOT NULL,
    device_name VARCHAR(100) NOT NULL DEFAULT '',
    status      ENUM('normal','error','offline') DEFAULT 'normal',
    last_seen   DATETIME NULL,
    error_msg   VARCHAR(255) NULL,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tank_device (tank_id, device_type),
    CONSTRAINT fk_devices_tank
      FOREIGN KEY (tank_id) REFERENCES tanks(tank_id)
      ON DELETE CASCADE
);

INSERT IGNORE INTO sensor_configs (tank_id, sensor_type, is_enabled, status)
SELECT t.tank_id, s.sensor_type, 1, 'normal'
FROM tanks t
CROSS JOIN (
    SELECT 'temperature' AS sensor_type
    UNION ALL SELECT 'ph'
    UNION ALL SELECT 'turbidity'
    UNION ALL SELECT 'water_level'
) s
WHERE t.deleted_at IS NULL;

INSERT IGNORE INTO devices (tank_id, device_type, device_name)
SELECT t.tank_id, d.device_type, CONCAT(UPPER(d.device_type), '-', t.tank_id)
FROM tanks t
CROSS JOIN (
    SELECT 'pump' AS device_type
    UNION ALL SELECT 'feeder'
    UNION ALL SELECT 'cctv'
) d
WHERE t.deleted_at IS NULL;

ALTER TABLE tanks
  ADD COLUMN IF NOT EXISTS last_sensor_at DATETIME NULL DEFAULT NULL
  AFTER deleted_at;

CREATE INDEX IF NOT EXISTS idx_tanks_last_sensor ON tanks(last_sensor_at);