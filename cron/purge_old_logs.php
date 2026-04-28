<?php
/* =========================================================
 *  cron/purge_old_logs.php
 *  ลบข้อมูลเก่าเพื่อคุมขนาด DB
 *
 *    • sensor_logs  เก่ากว่า SENSOR_RETAIN_DAYS วัน (default 90)
 *    • event_logs   เก่ากว่า EVENT_RETAIN_DAYS  วัน (default 365)
 *    • login_logs   เก่ากว่า LOGIN_RETAIN_DAYS  วัน (default 180)
 *    • tanks        ที่ถูก soft-delete เกิน TRASH_DAYS วัน (default 30)
 *      → hard delete เพื่อให้ cascade logs เก่าๆ ทิ้งไปได้ด้วย
 *
 *  วิธีรัน:
 *    (ครั้งเดียว — manual)
 *      docker exec php-apache php /var/www/html/cron/purge_old_logs.php
 *
 *    (อัตโนมัติ — ใส่ใน crontab host)
 *      0 3 * * * docker exec php-apache php /var/www/html/cron/purge_old_logs.php >> /var/log/smartaqua-purge.log 2>&1
 *
 *    (ผ่าน browser — สำหรับทดสอบ)
 *      http://localhost:8080/cron/purge_old_logs.php?token=<SECRET>
 * ========================================================= */

ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(300);   // 5 นาที

/* ── Config (ปรับได้) ────────────────────────────── */
const SENSOR_RETAIN_DAYS = 90;
const EVENT_RETAIN_DAYS  = 365;
const LOGIN_RETAIN_DAYS  = 180;
const TRASH_DAYS         = 30;

/* ── Secret token สำหรับ browser mode ─────────────
 *   ตั้งใน .env เป็น CRON_TOKEN=xxxxx  หรือแก้ค่า fallback ด้านล่าง
 */
$CRON_TOKEN = getenv('CRON_TOKEN') ?: 'change-me-to-a-long-random-string';

/* ── ถ้าเรียกผ่าน HTTP ต้องมี token ─────────────── */
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    if (($_GET['token'] ?? '') !== $CRON_TOKEN) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'invalid token']);
        exit;
    }
}

require_once __DIR__ . '/../db.php';

$log = function(string $msg) use ($isCli) {
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] $msg";
    if ($isCli) echo $line . "\n";
    error_log($line);
};

try {
    $pdo = getConnection();

    /* ── เช็คว่ามี column deleted_at ไหม ─ */
    $hasSoftCol = (int)$pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tanks' AND COLUMN_NAME = 'deleted_at'
    ")->fetchColumn();

    $result = [];

    /* ── 1) sensor_logs ─────────────── */
    $stmt = $pdo->prepare("
        DELETE FROM sensor_logs
        WHERE recorded_at < (NOW() - INTERVAL :d DAY)
    ");
    $stmt->bindValue(':d', SENSOR_RETAIN_DAYS, PDO::PARAM_INT);
    $stmt->execute();
    $result['sensor_logs_deleted'] = $stmt->rowCount();
    $log("purged sensor_logs older than " . SENSOR_RETAIN_DAYS
         . " days : " . $result['sensor_logs_deleted'] . " rows");

    /* ── 2) event_logs ──────────────── */
    $stmt = $pdo->prepare("
        DELETE FROM event_logs
        WHERE created_at < (NOW() - INTERVAL :d DAY)
    ");
    $stmt->bindValue(':d', EVENT_RETAIN_DAYS, PDO::PARAM_INT);
    $stmt->execute();
    $result['event_logs_deleted'] = $stmt->rowCount();
    $log("purged event_logs older than " . EVENT_RETAIN_DAYS
         . " days : " . $result['event_logs_deleted'] . " rows");

    /* ── 3) login_logs ──────────────── */
    $stmt = $pdo->prepare("
        DELETE FROM login_logs
        WHERE created_at < (NOW() - INTERVAL :d DAY)
    ");
    $stmt->bindValue(':d', LOGIN_RETAIN_DAYS, PDO::PARAM_INT);
    $stmt->execute();
    $result['login_logs_deleted'] = $stmt->rowCount();
    $log("purged login_logs older than " . LOGIN_RETAIN_DAYS
         . " days : " . $result['login_logs_deleted'] . " rows");

    /* ── 4) trash tanks (soft-deleted ตู้เก่าเกิน 30 วัน) ── */
    if ($hasSoftCol) {
        $stmt = $pdo->prepare("
            DELETE FROM tanks
            WHERE deleted_at IS NOT NULL
              AND deleted_at < (NOW() - INTERVAL :d DAY)
        ");
        $stmt->bindValue(':d', TRASH_DAYS, PDO::PARAM_INT);
        $stmt->execute();
        $result['trash_tanks_deleted'] = $stmt->rowCount();
        $log("purged trashed tanks older than " . TRASH_DAYS
             . " days : " . $result['trash_tanks_deleted'] . " rows");
    } else {
        $result['trash_tanks_deleted'] = 'skipped (deleted_at column not found, run migrations/001 first)';
    }

    /* ── 5) OPTIMIZE TABLE เพื่อคืนพื้นที่ disk ── */
    $pdo->query("OPTIMIZE TABLE sensor_logs, event_logs, login_logs");
    $log("optimized tables");

    $result['success'] = true;
    $result['purged_at'] = date('Y-m-d H:i:s');

    if (!$isCli) echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    $log("ERROR: " . $e->getMessage());
    if (!$isCli) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit(1);
}
