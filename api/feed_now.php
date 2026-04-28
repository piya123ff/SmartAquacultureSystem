<?php
// ===================================
// api/feed_now.php
// บันทึกการให้อาหาร Manual จากหน้าเว็บ
// ===================================

ob_start();
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}

function jsonError($code, $msg) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'รองรับเฉพาะ POST');
}

require_once '../db.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) jsonError(400, 'Invalid JSON');

$tank_id = isset($data['tank_id']) ? (int)$data['tank_id'] : 0;
$amount  = isset($data['amount'])  ? (float)$data['amount']  : 0;
$mode    = isset($data['mode']) && in_array($data['mode'], ['manual','auto'], true) ? $data['mode'] : 'manual';

if ($tank_id <= 0)   jsonError(400, 'tank_id ต้องระบุ');
if ($amount  <= 0)   jsonError(400, 'amount ต้องมากกว่า 0');
if ($amount  > 5000) jsonError(400, 'amount สูงเกินไป');

try {
    $pdo = getConnection();
} catch (Exception $e) {
    jsonError(500, 'DB connect error');
}

// ตรวจว่าตู้มีจริง
$stmt = $pdo->prepare("SELECT tank_name FROM tanks WHERE tank_id = :id");
$stmt->execute([':id' => $tank_id]);
$tank = $stmt->fetch();
if (!$tank) jsonError(404, 'ไม่พบตู้ปลา ID: ' . $tank_id);

// ดึง user id จาก session (ถ้ามี)
@session_start();
$changedBy = $_SESSION['user_id'] ?? null;
$userName  = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'admin';

// บันทึก event — category 5 = feeding
// ถ้า auto — ใช้ 'system' เป็นผู้สั่ง (ไม่มี user จริง)
$actor  = ($mode === 'auto') ? 'AUTO-SCHEDULER' : $userName;
$detail = ($mode === 'auto')
    ? "ระบบให้อาหารอัตโนมัติ {$amount}g (ตามตาราง)"
    : "{$userName} สั่งให้อาหาร Manual {$amount}g";

try {
    $pdo->prepare("
        INSERT INTO event_logs
            (tank_id, category_id, event_type, severity, feed_mode, feed_amount_g, changed_by, detail)
        VALUES
            (:tank_id, 5, 'feeding_done', 'info', :mode, :amount, :changed_by, :detail)
    ")->execute([
        ':tank_id'    => $tank_id,
        ':mode'       => $mode,
        ':amount'     => $amount,
        ':changed_by' => $changedBy,
        ':detail'     => $detail,
    ]);

    $event_id = $pdo->lastInsertId();
} catch (PDOException $e) {
    jsonError(500, 'บันทึกไม่สำเร็จ: ' . $e->getMessage());
}

ob_end_clean();
echo json_encode([
    'success'   => true,
    'message'   => ($mode === 'auto' ? 'ให้อาหารอัตโนมัติสำเร็จ' : 'ให้อาหารสำเร็จ'),
    'event_id'  => (int)$event_id,
    'tank_id'   => $tank_id,
    'tank_name' => $tank['tank_name'],
    'amount'    => $amount,
    'mode'      => $mode,
], JSON_UNESCAPED_UNICODE);