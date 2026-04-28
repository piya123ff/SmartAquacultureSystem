<?php
/* =========================================================
 *  api/tank_delete.php  (Soft Delete)
 *  ลบตู้ปลา → set deleted_at = NOW() แทนการ DELETE จริง
 *  ทำให้ sensor_logs / event_logs ยังอยู่ ไม่หายไป
 *
 *  BODY (JSON):
 *    { "tank_id": 3, "hard": false }
 *
 *  - hard = false (default) → soft delete
 *  - hard = true            → DELETE จริง (cascade logs ทิ้งหมด)
 *  - ถ้ายังไม่ได้รัน migration 001 → auto fallback เป็น hard delete
 * ========================================================= */
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean(); http_response_code(200); exit;
}

function jsonError($code, $msg) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError(405, 'POST only');

require_once '../db.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
$tank_id = (int)($data['tank_id'] ?? 0);
$hard    = (bool)($data['hard']    ?? false);
if ($tank_id <= 0) jsonError(400, 'tank_id required');

try { $pdo = getConnection(); }
catch (Exception $e) { jsonError(500, 'DB connect error'); }

/* ───── เช็คว่า deleted_at column มีอยู่จริงไหม ───── */
$hasSoftCol = (int)$pdo->query("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tanks'
      AND COLUMN_NAME  = 'deleted_at'
")->fetchColumn();

/* ───── หาข้อมูลก่อนลบ เผื่อจะ log ───── */
$selCols = $hasSoftCol ? 'tank_name, deleted_at' : 'tank_name';
$stmt = $pdo->prepare("SELECT $selCols FROM tanks WHERE tank_id = :id");
$stmt->execute([':id' => $tank_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) jsonError(404, 'ไม่พบ tank_id นี้');

/* ───── กันลบตู้สุดท้าย (นับเฉพาะที่ยังไม่ถูกลบ) ───── */
if ($hasSoftCol) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM tanks WHERE deleted_at IS NULL")->fetchColumn();
} else {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM tanks")->fetchColumn();
}
if ($cnt <= 1) jsonError(400, 'ไม่สามารถลบตู้สุดท้ายได้');

/* ───── ดำเนินการลบ ───── */
try {
    $pdo->beginTransaction();

    if ($hard || !$hasSoftCol) {
        // HARD delete (cascade logs)
        $pdo->prepare("DELETE FROM tanks WHERE tank_id = :id")
            ->execute([':id' => $tank_id]);
        $mode = 'hard';
    } else {
        // SOFT delete
        if (!empty($row['deleted_at'])) {
            $pdo->rollBack();
            jsonError(400, 'ตู้นี้ถูกลบไปแล้ว');
        }
        $pdo->prepare("
            UPDATE tanks
            SET deleted_at = NOW(),
                status     = 'offline'
            WHERE tank_id = :id
        ")->execute([':id' => $tank_id]);
        $mode = 'soft';
    }

    /* event log */
    @session_start();
    $changedBy = $_SESSION['user_id'] ?? null;
    // ถ้า soft delete → tank ยังอยู่ใส่ tank_id ได้, ถ้า hard → tank_id จะ cascade ทิ้งไปด้วย
    if ($mode === 'soft') {
        $pdo->prepare("
            INSERT INTO event_logs (tank_id, category_id, event_type, severity, changed_by, detail)
            VALUES (:id, 8, 'tank_deleted', 'warning', :by, :dt)
        ")->execute([
            ':id' => $tank_id,
            ':by' => $changedBy,
            ':dt' => "ย้ายตู้ \"{$row['tank_name']}\" ไปถังขยะ (soft delete)",
        ]);
    }

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError(500, $e->getMessage());
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'message' => ($mode === 'soft')
                    ? "ย้ายตู้ \"{$row['tank_name']}\" ไปถังขยะแล้ว (กู้คืนได้)"
                    : "ลบตู้ \"{$row['tank_name']}\" ถาวรแล้ว",
    'tank_id' => $tank_id,
    'mode'    => $mode,
], JSON_UNESCAPED_UNICODE);
