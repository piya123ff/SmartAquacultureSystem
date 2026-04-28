-- =============================================================
--  Migration 001 — 2026-04-17
--  วัตถุประสงค์:
--    1) เพิ่ม INDEX บน users.session_token  → ทำให้ทุก API ที่
--       ต้อง verify token ด้วย WHERE session_token = ? เร็วขึ้น
--    2) เพิ่ม column deleted_at ให้ tanks    → รองรับ Soft Delete
--       (ลบตู้โดยไม่เสียข้อมูล sensor_logs/event_logs)
--
--  วิธีรัน:
--    • เปิด phpMyAdmin → เลือก DB smartaqua → กด SQL → paste
--      ไฟล์นี้ → Go
--    • หรือ: docker exec -i db mysql -uuser -ppassword smartaqua
--            < migrations/001_index_and_soft_delete.sql
--
--  รันซ้ำได้ (idempotent): ใช้ IF NOT EXISTS / CREATE INDEX IF NOT EXISTS
-- =============================================================

USE smartaqua;

-- ── (1) INDEX บน session_token ──────────────────────────────
-- MySQL 8.0.29+ รองรับ CREATE INDEX IF NOT EXISTS ก็จริง แต่
-- หลายเวอร์ชันยังไม่รองรับ เลยใช้วิธีเช็คจาก INFORMATION_SCHEMA

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND INDEX_NAME   = 'idx_session_token'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_session_token ON users(session_token)',
    'SELECT "idx_session_token already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── (2) deleted_at column ใน tanks ──────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tanks'
      AND COLUMN_NAME  = 'deleted_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tanks ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status',
    'SELECT "tanks.deleted_at already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── (3) INDEX บน deleted_at (ช่วย filter เร็วขึ้น) ─────────
SET @idx2_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tanks'
      AND INDEX_NAME   = 'idx_tanks_deleted_at'
);
SET @sql := IF(@idx2_exists = 0,
    'CREATE INDEX idx_tanks_deleted_at ON tanks(deleted_at)',
    'SELECT "idx_tanks_deleted_at already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── (4) UPDATE VIEW v_dashboard ให้กรองตู้ที่ถูกลบออก ───────
-- หมายเหตุ: ต้อง run ด้วย root (-uroot -proot) ถึงจะมีสิทธิ์
--           ถ้ารันด้วย user 'user' step นี้จะถูก skip
SET @is_root := (SELECT CURRENT_USER() LIKE 'root@%');
SET @sql := IF(@is_root,
    "DROP VIEW IF EXISTS v_dashboard",
    "SELECT '⚠ skip: ต้องใช้ root สำหรับ VIEW' AS warn");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@is_root,
    "CREATE SQL SECURITY INVOKER VIEW v_dashboard AS
     SELECT t.tank_id, t.tank_name, t.species, t.location, t.status,
            s.target_temp, s.min_temp, s.max_temp,
            s.trigger_ph_high, s.trigger_ph_low,
            s.trigger_turbidity, s.trigger_water_low,
            s.feeding_time, s.feeding_amount, s.auto_feed_status
     FROM tanks t LEFT JOIN tank_settings s ON s.tank_id = t.tank_id
     WHERE t.deleted_at IS NULL",
    "SELECT '⚠ view skipped' AS info");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT '✅ Migration 001 สำเร็จ' AS result;
